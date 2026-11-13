<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Fees;
use App\Services\Gateways\StewardPay\Client;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The real bank (StewardPay / CyberPay) and the checkout, with the bank faked. */
class StewardPayTest extends TestCase
{
    use RefreshDatabase;

    private const BANK = 'https://bank.test/v1/merchant';

    private string $live;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stewardpay' => [
            'base_url' => 'https://bank.test/v1', 'client_id' => 'bless_test', 'client_secret' => 'shh-secret',
            'encryption_key' => 'enc-key', 'sandbox' => false, 'timeout' => 5,
        ]]);

        $company = Company::create(['name' => 'BlessLuxe', 'slug' => 'blessluxe', 'website' => 'https://blessluxe.com', 'status' => 'active', 'callback_secret' => 'whsec_test']);
        [, $this->live] = ApiKey::issue($company, 'Shop', 'live');
    }

    private function bank(array $routes): void
    {
        Http::fake($routes + [
            self::BANK.'/client/token' => Http::response(['message' => 'Successful Request', 'status' => 200, 'errors' => null, 'body' => ['access_token' => 'tok-1', 'expires_in' => 300]]),
            'shop.test/*' => Http::response('ok'),
        ]);
    }

    private function envelope(array $body): array
    {
        return ['message' => 'Successful Request', 'status' => 200, 'errors' => null, 'body' => $body];
    }

    private function payment(array $over = []): string
    {
        return $this->withToken($this->live)->postJson('/api/v1/payments', $over + [
            'amount' => '48.00', 'currency' => 'USD', 'reference' => 'BLX-10482',
            'return_url' => 'https://shop.test/thanks', 'callback_url' => 'https://shop.test/hooks/pay',
        ])->assertStatus(201)->json('id');
    }

    public function test_live_keys_are_refused_when_the_bank_is_not_configured(): void
    {
        config(['services.stewardpay.client_secret' => null]);

        $this->withToken($this->live)->postJson('/api/v1/payments', [
            'amount' => '48.00', 'currency' => 'USD', 'reference' => 'R', 'return_url' => 'https://shop.test/thanks',
        ])->assertStatus(503)->assertJsonPath('error.code', 'live_not_available');
    }

    public function test_the_checkout_offers_the_banks_methods_and_cards_only_in_usd(): void
    {
        $this->bank([]);

        $usd = $this->getJson('/p/'.$this->payment().'/status')->assertOk();
        $this->assertSame(['ecocash', 'card', 'bank'], collect($usd->json('methods'))->pluck('id')->all());

        $zwg = $this->getJson('/p/'.$this->payment(['currency' => 'ZWG', 'reference' => 'Z1']).'/status');
        $this->assertSame(['ecocash', 'bank'], collect($zwg->json('methods'))->pluck('id')->all());
    }

    public function test_card_opens_a_bank_session_with_the_right_hash_and_never_leaks_secrets(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope([
                'uid' => 'uid-card-1', 'status' => 'PENDING', 'amount' => '48.00', 'clientSecret' => 'shh-secret',
                'sessionId' => 'SESSION0002', 'successIndicator' => 'abc', 'targetUrl' => 'https://na.gateway.mastercard.com/checkout/pay/SESSION0002?checkoutVersion=1.0.0',
            ])),
        ]);
        $id = $this->payment();

        $step = $this->postJson("/p/{$id}/pay", ['method' => 'card'])->assertOk();
        $step->assertJsonPath('next.kind', 'card')
            ->assertJsonPath('next.session_id', 'SESSION0002')
            ->assertJsonPath('next.script', 'https://na.gateway.mastercard.com/static/checkout/checkout.min.js');

        Http::assertSent(function (ClientRequest $request) use ($id) {
            if (! str_ends_with($request->url(), '/sdk/action')) {
                return false;
            }
            $phone = $request['phone'];

            return $request->hasHeader('Authorization', 'Bearer tok-1')
                && $request['clientId'] === 'bless_test'
                && $request['authType'] === 'WEB_EMBEDDED' && $request['paymentProcessorLabel'] === 'MPGS'
                && $request['action'] === 'PAYMENT' && $request['currency'] === 'USD'
                && $request['amount'] === '48.00'                                   // a string, exactly as hashed
                && $request['hash'] === hash('sha256', 'bless_test'.'enc-key'.$phone.'48.00')
                && ! isset($request['encryptionKey'])                               // the key itself is never sent
                && str_ends_with($request['responseUrl'], '/hooks/stewardpay')
                && str_ends_with($request['returnUrl'], "/p/{$id}/card-return")
                && preg_match('/^BP[0-9A-Z]{12}-1$/', $request['billerReference']);
        });

        // Nothing secret reaches the shopper's browser or the payment log.
        $everything = $step->getContent().json_encode(Payment::findPublic($id)->events->toArray());
        $this->assertStringNotContainsString('shh-secret', $everything);
        $this->assertStringNotContainsString('enc-key', $everything);
        $this->assertSame('uid-card-1', PaymentAttempt::first()->gateway_uid);
    }

    public function test_a_card_is_paid_only_when_the_banks_card_check_says_so(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope(['uid' => 'uid-card-1', 'status' => 'PENDING', 'sessionId' => 'S1', 'targetUrl' => 'https://na.gateway.mastercard.com/checkout/pay/S1'])),
            self::BANK.'/sdk/mpgs/poll/uid-card-1' => Http::sequence()
                ->push($this->envelope(['uid' => 'uid-card-1', 'status' => 'PENDING', 'responseCode' => '02', 'amount' => '48.00']))
                ->push($this->envelope(['uid' => 'uid-card-1', 'status' => 'SUCCESS', 'responseCode' => '00', 'amount' => '48.00', 'transaction' => ['debitReference' => 'FT220358LVMZ']])),
        ]);
        $id = $this->payment();
        $this->postJson("/p/{$id}/pay", ['method' => 'card'])->assertOk();

        $this->getJson("/p/{$id}/status")->assertJsonPath('payment.status', 'pending');
        $this->travel(5)->seconds();
        $this->getJson("/p/{$id}/status")->assertJsonPath('payment.status', 'paid')->assertJsonPath('payment.paid_with', 'card');

        $payment = Payment::findPublic($id);
        $this->assertSame('FT220358LVMZ', $payment->gateway_reference);
        $this->assertSame('delivered', $payment->callback_status);
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://shop.test/hooks/pay' && $r['event'] === 'payment.paid');
    }

    public function test_the_bank_is_not_asked_more_than_once_every_few_seconds(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope(['uid' => 'u1', 'status' => 'PENDING', 'sessionId' => 'S1', 'targetUrl' => 'https://na.gateway.mastercard.com/x'])),
            self::BANK.'/sdk/mpgs/poll/u1' => Http::response($this->envelope(['uid' => 'u1', 'status' => 'PENDING', 'amount' => '48.00'])),
        ]);
        $id = $this->payment();
        $this->postJson("/p/{$id}/pay", ['method' => 'card']);

        foreach (range(1, 5) as $i) {
            $this->getJson("/p/{$id}/status")->assertOk();
        }

        $this->assertSame(1, Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), '/mpgs/poll/'))->count());
    }

    public function test_ecocash_answers_the_browser_first_and_settles_when_the_shopper_approves(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope([
                'uid' => 'uid-eco-1', 'status' => 'SUCCESS', 'amount' => '48.00',
                'transaction' => ['status' => 'SUCCESS', 'debitReference' => 'MP261113.1420.A1', 'responseCode' => '00'],
            ])),
        ]);
        $id = $this->payment();

        $this->postJson("/p/{$id}/pay", ['method' => 'ecocash', 'phone' => '077 123 4567'])
            ->assertOk()
            ->assertJsonPath('next.kind', 'wait')              // told to wait...
            ->assertJsonPath('payment.status', 'pending')      // ...before the bank call was made
            ->assertJsonPath('attempt.phone', '077 *** 4567');

        // The bank call ran after the response went out.
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/sdk/action')
            && $r['authType'] === 'REMOTE' && $r['paymentProcessorLabel'] === 'ECOCASH' && $r['phone'] === '263771234567'
            && $r['hash'] === hash('sha256', 'bless_test'.'enc-key'.'263771234567'.'48.00'));

        $this->getJson("/p/{$id}/status")->assertJsonPath('payment.status', 'paid')->assertJsonPath('payment.paid_with', 'ecocash');
        $this->assertSame('MP261113.1420.A1', Payment::findPublic($id)->gateway_reference);
    }

    public function test_a_declined_try_leaves_the_payment_open_for_another_way_to_pay(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::sequence()
                ->push($this->envelope(['uid' => 'e1', 'status' => 'FAILED', 'errorMessage' => 'Insufficient funds', 'amount' => '48.00']))
                ->push($this->envelope(['uid' => 'c2', 'status' => 'PENDING', 'sessionId' => 'S2', 'targetUrl' => 'https://na.gateway.mastercard.com/x'])),
        ]);
        $id = $this->payment();

        $this->postJson("/p/{$id}/pay", ['method' => 'ecocash', 'phone' => '0771234567'])->assertOk();
        $this->getJson("/p/{$id}/status")
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('attempt.status', 'failed')
            ->assertJsonPath('attempt.error', 'Insufficient funds');

        $this->postJson("/p/{$id}/pay", ['method' => 'card'])->assertOk()->assertJsonPath('next.kind', 'card');
        $this->assertSame(['failed', 'pending'], PaymentAttempt::orderBy('id')->pluck('status')->all());
        Http::assertNotSent(fn (ClientRequest $r) => $r->url() === 'https://shop.test/hooks/pay'); // nothing to tell the shop yet
    }

    public function test_bank_account_hands_the_shopper_the_banks_page_and_needs_their_number(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope(['uid' => 'b1', 'status' => 'PENDING', 'targetUrl' => 'https://checkout.stewardpay.co.zw/payments/payment/b1?t=tok-1'])),
            self::BANK.'/sdk/b1' => Http::response($this->envelope(['uid' => 'b1', 'status' => 'SUCCESS', 'amount' => '48.00', 'debitReference' => 'FT1'])),
        ]);
        $id = $this->payment();

        $this->postJson("/p/{$id}/pay", ['method' => 'bank'])->assertStatus(422);
        $this->postJson("/p/{$id}/pay", ['method' => 'bank', 'phone' => '12345'])->assertStatus(422);

        $this->postJson("/p/{$id}/pay", ['method' => 'bank', 'phone' => '+263 77 123 4567'])
            ->assertOk()->assertJsonPath('next.kind', 'open')
            ->assertJsonPath('next.url', 'https://checkout.stewardpay.co.zw/payments/payment/b1?t=tok-1');

        // The page address carries our token: it goes to the browser, never into the log.
        $this->assertStringNotContainsString('tok-1', json_encode(Payment::findPublic($id)->events->toArray()));

        $this->travel(5)->seconds();
        $this->getJson("/p/{$id}/status")->assertJsonPath('payment.status', 'paid');
    }

    public function test_a_success_for_a_different_amount_is_not_accepted(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope(['uid' => 'e1', 'status' => 'SUCCESS', 'amount' => '4.80'])),
        ]);
        $id = $this->payment();

        $this->postJson("/p/{$id}/pay", ['method' => 'ecocash', 'phone' => '0771234567']);

        $this->assertSame('pending', Payment::findPublic($id)->status);
        $this->assertSame('failed', PaymentAttempt::first()->status);
    }

    public function test_the_banks_notice_makes_us_ask_the_bank_rather_than_trusting_the_notice(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope(['uid' => 'b1', 'status' => 'PENDING', 'targetUrl' => 'https://checkout.stewardpay.co.zw/p/b1'])),
            self::BANK.'/sdk/b1' => Http::sequence()
                ->push($this->envelope(['uid' => 'b1', 'status' => 'PENDING', 'amount' => '48.00']))
                ->push($this->envelope(['uid' => 'b1', 'status' => 'SUCCESS', 'amount' => '48.00', 'debitReference' => 'FT9'])),
        ]);
        $id = $this->payment();
        $this->postJson("/p/{$id}/pay", ['method' => 'bank', 'phone' => '0771234567']);
        $reference = PaymentAttempt::first()->reference;

        // A forged "SUCCESS" while the bank itself still says pending: nothing happens.
        $this->postJson('/hooks/stewardpay', ['status' => 'SUCCESS', 'billerReference' => $reference, 'amount' => 48, 'hash' => 'forged'])->assertOk();
        $this->assertSame('pending', Payment::findPublic($id)->status);

        // A notice for something we do not know is answered the same way.
        $this->postJson('/hooks/stewardpay', ['status' => 'SUCCESS', 'billerReference' => 'NOPE'])->assertOk()->assertExactJson(['received' => true]);

        // The real one: we ask the bank, and the bank says paid.
        $this->postJson('/hooks/stewardpay', ['status' => 'SUCCESS', 'billerReference' => $reference, 'amount' => 48])->assertOk();
        $this->assertSame('paid', Payment::findPublic($id)->status);
        $this->assertSame('FT9', Payment::findPublic($id)->gateway_reference);
    }

    public function test_when_the_bank_cannot_be_reached_only_a_correctly_signed_notice_counts(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope(['uid' => 'b1', 'status' => 'PENDING', 'targetUrl' => 'https://checkout.stewardpay.co.zw/p/b1'])),
            self::BANK.'/sdk/b1' => Http::response('down', 502),
        ]);
        $id = $this->payment();
        $this->postJson("/p/{$id}/pay", ['method' => 'bank', 'phone' => '0771234567']);
        $reference = PaymentAttempt::first()->reference;
        $notice = ['status' => 'SUCCESS', 'billerReference' => $reference, 'amount' => 48, 'trace' => 'T1', 'reference' => 'R1', 'debitReference' => 'FT7'];

        $this->postJson('/hooks/stewardpay', $notice + ['hash' => hash('sha256', 'wrong-key'.'48'.'T1'.'R1')])->assertOk();
        $this->assertSame('pending', Payment::findPublic($id)->status);

        $this->postJson('/hooks/stewardpay', $notice + ['hash' => hash('sha256', 'enc-key'.'48'.'T1'.'R1')])->assertOk();
        $this->assertSame('paid', Payment::findPublic($id)->status);
        $this->assertSame('FT7', Payment::findPublic($id)->gateway_reference);
    }

    public function test_an_old_try_approved_after_another_was_paid_is_flagged_for_refund(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::sequence()
                ->push($this->envelope(['uid' => 'b1', 'status' => 'PENDING', 'targetUrl' => 'https://checkout.stewardpay.co.zw/p/b1']))
                ->push($this->envelope(['uid' => 'e2', 'status' => 'SUCCESS', 'amount' => '48.00'])),
            self::BANK.'/sdk/b1' => Http::response($this->envelope(['uid' => 'b1', 'status' => 'SUCCESS', 'amount' => '48.00'])),
        ]);
        $id = $this->payment();

        $this->postJson("/p/{$id}/pay", ['method' => 'bank', 'phone' => '0771234567']);           // left open at the bank
        $this->postJson("/p/{$id}/pay", ['method' => 'ecocash', 'phone' => '0771234567']);        // paid by EcoCash
        $this->assertSame('paid', Payment::findPublic($id)->status);

        $this->travel(5)->seconds();
        $this->getJson("/p/{$id}/status");                                                        // the old try turns out paid too

        $this->assertContains('attempt.paid_twice', Payment::findPublic($id)->events->pluck('type')->all());
        $this->assertSame(1, Http::recorded(fn (ClientRequest $r) => $r->url() === 'https://shop.test/hooks/pay')->count()); // the shop is told once
    }

    public function test_a_company_can_pass_the_fee_to_the_shopper(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope(['uid' => 'u1', 'status' => 'PENDING', 'sessionId' => 'S1', 'targetUrl' => 'https://na.gateway.mastercard.com/x'])),
        ]);
        Company::first()->update(['fee_bearer' => 'customer']);

        $created = $this->withToken($this->live)->postJson('/api/v1/payments', [
            'amount' => '48.00', 'currency' => 'USD', 'reference' => 'F1', 'return_url' => 'https://shop.test/thanks',
        ])->assertStatus(201)->assertJsonPath('amount', '48.00')->assertJsonPath('fee', '0.49')->assertJsonPath('charged', '48.49');

        $this->postJson('/p/'.$created->json('id').'/pay', ['method' => 'card'])->assertOk();
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/sdk/action') && $r['amount'] === '48.49');

        // After the bank keeps 1% of 48.49, the company still receives at least the 48.00.
        $this->assertGreaterThanOrEqual(48.00, 48.49 - (float) Fees::bankCut('48.49'));
        $this->assertSame('0.20', Fees::onTop('5.00'));  // the minimum applies to small amounts
    }

    public function test_only_allowed_sites_may_frame_the_checkout(): void
    {
        $this->bank([]);
        $id = $this->payment();
        Company::first()->update(['embed_origins' => ['https://partner.test']]);

        $self = "frame-ancestors 'self'";
        $this->get("/p/{$id}")->assertOk()->assertHeader('Content-Security-Policy', $self);
        $this->get("/p/{$id}?embed=https://evil.test")->assertHeader('Content-Security-Policy', $self);
        $this->get("/p/{$id}?embed=https://shop.test")->assertHeader('Content-Security-Policy', $self.' https://shop.test');           // where the shopper returns to
        $this->get("/p/{$id}?embed=https://blessluxe.com")->assertHeader('Content-Security-Policy', $self.' https://blessluxe.com');   // the company's site
        $this->get("/p/{$id}?embed=https://partner.test")->assertHeader('Content-Security-Policy', $self.' https://partner.test');     // listed by the company
        $this->get("/p/{$id}?embed=https://shop.test/x' *")->assertHeader('Content-Security-Policy', $self);                           // not a bare origin
    }

    public function test_a_payment_closes_when_its_time_runs_out_and_can_be_cancelled(): void
    {
        $this->bank([]);
        $id = $this->payment();

        $this->travel(31)->minutes();
        $this->getJson("/p/{$id}/status")->assertJsonPath('payment.status', 'failed')->assertJsonPath('methods', []);
        $this->postJson("/p/{$id}/pay", ['method' => 'card'])->assertStatus(422);

        $other = $this->payment(['reference' => 'C1']);
        $this->postJson("/p/{$other}/cancel")->assertOk()->assertJsonPath('payment.status', 'cancelled');
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://shop.test/hooks/pay' && $r['event'] === 'payment.cancelled');
    }

    public function test_phone_numbers_and_the_card_return_page(): void
    {
        $this->assertSame('263771234567', Phone::zimbabwe('077 123 4567'));
        $this->assertSame('263771234567', Phone::zimbabwe('+263-77-123-4567'));
        $this->assertSame('263771234567', Phone::zimbabwe('771234567'));
        $this->assertNull(Phone::zimbabwe('0242 123456'));
        $this->assertNull(Phone::zimbabwe('12345'));

        $this->bank([]);
        $id = $this->payment();
        $this->get("/p/{$id}/card-return")->assertOk()->assertSee('blessedpay-card', false)->assertHeaderMissing('Content-Security-Policy');
        $this->get('/p/pay_nope/card-return')->assertStatus(404);

        $this->assertTrue(app(Client::class)->callbackHashMatches(['amount' => 1290, 'trace' => 'T', 'reference' => 'R', 'hash' => strtoupper(hash('sha256', 'enc-key1290TR'))]));
        $this->assertFalse(app(Client::class)->callbackHashMatches(['amount' => 1290, 'trace' => 'T', 'reference' => 'R']));
    }
}
