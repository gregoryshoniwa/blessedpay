<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Fees;
use App\Services\Gateways\StewardPay\Client;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
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

        config(['blessedpay.methods' => ['ecocash', 'card', 'bank']]); // every way of paying, including the one switched off for now

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

        // The second try is the one the checkout is told about: not the failed first one again.
        $this->postJson("/p/{$id}/pay", ['method' => 'card'])->assertOk()->assertJsonPath('next.kind', 'card')
            ->assertJsonPath('attempt.method', 'card')->assertJsonPath('attempt.status', 'pending')->assertJsonPath('attempt.error', null);
        $this->assertSame(['failed', 'pending'], PaymentAttempt::orderBy('id')->pluck('status')->all());
        Http::assertNotSent(fn (ClientRequest $r) => $r->url() === 'https://shop.test/hooks/pay'); // nothing to tell the shop yet
    }

    public function test_a_way_of_paying_can_be_switched_off(): void
    {
        config(['blessedpay.methods' => ['ecocash', 'card']]);
        $this->bank([]);
        $id = $this->payment();

        $this->assertSame(['ecocash', 'card'], collect($this->getJson("/p/{$id}/status")->json('methods'))->pluck('id')->all());
        // Not just hidden: it cannot be started by asking for it directly either.
        $this->postJson("/p/{$id}/pay", ['method' => 'bank', 'phone' => '0771234567'])->assertStatus(422);
        Http::assertNotSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/sdk/action'));
    }

    public function test_an_ecocash_refusal_before_any_prompt_is_told_apart_from_an_unanswered_one(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::sequence()
                ->push($this->envelope(['uid' => 'e1', 'status' => 'FAILED', 'errorMessage' => 'Transaction failed', 'seconds' => '1.4', 'amount' => '48.00']))
                ->push($this->envelope(['uid' => 'e2', 'status' => 'FAILED', 'errorMessage' => 'Transaction failed', 'seconds' => '61.2', 'amount' => '48.00'])),
        ]);
        $id = $this->payment();

        $this->postJson("/p/{$id}/pay", ['method' => 'ecocash', 'phone' => '0771234567'])->assertOk();
        $this->app->terminate();
        $this->assertStringContainsString('before any prompt reached the phone', $this->getJson("/p/{$id}/status")->json('attempt.error'));

        $this->postJson("/p/{$id}/pay", ['method' => 'ecocash', 'phone' => '0771234567'])->assertOk();
        $this->app->terminate();
        $this->assertStringContainsString('did not approve', $this->getJson("/p/{$id}/status")->json('attempt.error'));
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

    public function test_a_company_can_pass_the_fee_to_the_shopper_and_it_depends_on_the_way_of_paying(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope(['uid' => 'u1', 'status' => 'PENDING', 'sessionId' => 'S1', 'targetUrl' => 'https://na.gateway.mastercard.com/x'])),
        ]);
        Company::first()->update(['fee_bearer' => 'customer']);

        // Nothing is added until the shopper chooses how to pay…
        $id = $this->withToken($this->live)->postJson('/api/v1/payments', [
            'amount' => '5.00', 'currency' => 'USD', 'reference' => 'F1', 'return_url' => 'https://shop.test/thanks',
        ])->assertStatus(201)->assertJsonPath('amount', '5.00')->assertJsonPath('fee', '0.00')->json('id');

        // …and the checkout shows each way with its own total: the bank's 0.20 minimum is for cards only.
        $methods = collect($this->getJson("/p/{$id}/status")->json('methods'))->keyBy('id');
        $this->assertSame(['0.05', '5.05'], [$methods['ecocash']['fee'], $methods['ecocash']['total']]);
        $this->assertSame(['0.20', '5.20'], [$methods['card']['fee'], $methods['card']['total']]);
        // The rule is shown beside the fee: the percentage, or the bank's minimum when that is what is charged.
        $this->assertSame(['1%', 'USD 0.20'], [$methods['ecocash']['fee_rate'], $methods['card']['fee_rate']]);
        $this->assertSame('1%', Fees::quote('500.00', 'USD', 'card', 'customer')['rate']);
        $this->assertNull(Fees::quote('500.00', 'USD', 'card', 'merchant')['rate']);

        \App\Models\Setting::write('charges', ['USD' => ['margin' => ['percent' => 0.5, 'fixed' => 0]]]);
        $this->assertSame(['1.5%', '0.5% + USD 0.20'], [Fees::quote('2.00', 'USD', 'ecocash', 'customer')['rate'], Fees::quote('2.00', 'USD', 'card', 'customer')['rate']]);
        $this->assertSame('2.03', Fees::quote('2.00', 'USD', 'ecocash', 'customer')['charged']);
        \App\Models\Setting::write('charges', []);

        $this->postJson("/p/{$id}/pay", ['method' => 'card'])->assertOk()->assertJsonPath('payment.charged', '5.20');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/sdk/action') && $r['amount'] === '5.20');
    }

    public function test_charges_always_add_up_and_tally_with_what_the_bank_credits(): void
    {
        // The bank's own rule: cards 1% with a 0.20 minimum; EcoCash and bank account 1%.
        $this->assertSame('0.20', Fees::bankCut('5.20', 'USD', 'card'));
        $this->assertSame('0.05', Fees::bankCut('5.05', 'USD', 'ecocash'));

        foreach (['1.00', '5.00', '19.99', '48.00', '333.33', '1000.00'] as $amount) {
            foreach (Fees::METHODS as $method) {
                $q = Fees::quote($amount, 'USD', $method, 'customer');
                // On top: the company is left with at least the full order amount, to the cent…
                $this->assertGreaterThanOrEqual((float) $amount, (float) $q['company_keeps'], "{$amount} by {$method}");
                $this->assertLessThanOrEqual((float) $amount + 0.005, (float) $q['company_keeps'], "{$amount} by {$method}"); // and not a cent more
                $this->assertSame('0.00', $q['margin']); // no margin is set, so none appears
                // …and nothing goes missing between the shopper, the bank, the gateway and the company.
                $this->assertEqualsWithDelta((float) $q['charged'], (float) $q['bank_fee'] + (float) $q['margin'] + (float) $q['company_keeps'], 0.001);
                $this->assertSame($q['bank_fee'], Fees::bankCut($q['charged'], 'USD', $method));

                $absorbed = Fees::quote($amount, 'USD', $method, 'merchant');
                $this->assertSame([$amount, '0.00'], [$absorbed['charged'], $absorbed['fee']]);
            }
        }
    }

    public function test_staff_set_the_charges_and_a_margin_becomes_gateway_profit(): void
    {
        $admin = User::factory()->create(['company_id' => null, 'role' => 'super_admin', 'status' => 'active']);
        $finance = User::factory()->create(['company_id' => Company::first()->id, 'role' => 'finance', 'status' => 'active']);
        $rules = ['currency' => 'USD', 'bank' => [
            'card' => ['percent' => 1, 'minimum' => 0.20], 'ecocash' => ['percent' => 1, 'minimum' => 0], 'bank' => ['percent' => 1, 'minimum' => 0],
        ], 'margin' => ['percent' => 0.5, 'fixed' => 0.10]];

        $this->actingAs($finance)->putJson('/console-api/charges', $rules)->assertStatus(403);   // a company cannot set them
        $this->actingAs($admin)->putJson('/console-api/charges', ['margin' => ['percent' => 40, 'fixed' => 0]] + $rules)->assertStatus(422);
        $this->actingAs($admin)->putJson('/console-api/charges', $rules)->assertOk()->assertJsonPath('rules.USD.margin.percent', 0.5);
        $this->assertDatabaseHas('activity_logs', ['action' => 'charges.changed']);

        // 100.00 by EcoCash, fee on top: margin 0.60, and the bank's 1% of the total.
        Company::first()->update(['fee_bearer' => 'customer']);
        $this->bank([
            self::BANK.'/sdk/action' => fn (ClientRequest $r) => Http::response($this->envelope(['uid' => 'e1', 'status' => 'SUCCESS', 'amount' => $r['amount'], 'transaction' => ['debitReference' => 'FT1']])),
        ]);
        $id = $this->payment(['amount' => '100.00', 'reference' => 'M-1']);
        $this->postJson("/p/{$id}/pay", ['method' => 'ecocash', 'phone' => '0771234567'])->assertOk();
        $this->app->terminate();

        $payment = Payment::findPublic($id);
        $this->assertSame(['paid', '1.62', '1.02', '0.60'], [$payment->status, $payment->fee, $payment->bank_fee, $payment->margin]);

        $seen = $this->actingAs($admin)->getJson("/console-api/payments/{$id}")->json('payment');
        $this->assertSame(['101.62', '100.60', '100.00'], [$seen['charged'], $seen['bank_credits'], $seen['company_keeps']]);

        $charges = $this->actingAs($admin)->getJson('/console-api/charges?mode=live')->assertOk();
        $this->assertSame(['100.60', '0.60', '1.02'], [$charges->json('month.0.bank_credited'), $charges->json('month.0.gateway_profit'), $charges->json('month.0.bank_kept')]);
        $overview = $this->actingAs($admin)->getJson('/console-api/overview?mode=live')->assertOk();
        $this->assertSame(['1.02', '0.60'], [$overview->json('bank_revenue.0.total'), $overview->json('gateway_profit.0.total')]);
        $this->assertSame(['1.02', '0.60'], [$overview->json('companies.0.bank_revenue.0.total'), $overview->json('companies.0.gateway_profit.0.total')]);
        $this->assertSame([], $this->actingAs($admin)->getJson('/console-api/overview?mode=live&from=2020-01-01&to=2020-01-31')->json('paid')); // outside the dates
        $this->assertSame('0.60', $this->actingAs($admin)->getJson('/console-api/payments?mode=live')->json('summary.paid.0.gateway_profit'));

        // Charges are the super admin's alone: nobody else is sent the page, the bank's cut or the margin.
        $operations = User::factory()->create(['company_id' => null, 'role' => 'operations', 'status' => 'active']);
        foreach ([$finance, $operations] as $other) {
            $this->actingAs($other)->getJson('/console-api/charges?mode=live')->assertStatus(403);
            $payload = json_encode([
                $this->actingAs($other)->getJson('/console-api/overview?mode=live')->assertOk()->json(),
                $this->actingAs($other)->getJson('/console-api/payments?mode=live')->assertOk()->json(),
                $this->actingAs($other)->getJson("/console-api/payments/{$id}")->assertOk()->json(),
            ]);
            foreach (['bank_fee', 'margin', 'bank_revenue', 'gateway_profit', 'company_keeps'] as $private) {
                $this->assertStringNotContainsString('"'.$private.'"', $payload);
            }
        }

        // Absorbed instead: the shopper pays the order amount; both come out of what the company receives.
        Company::first()->update(['fee_bearer' => 'merchant']);
        $second = $this->payment(['amount' => '100.00', 'reference' => 'M-2']);
        $this->postJson("/p/{$second}/pay", ['method' => 'ecocash', 'phone' => '0771234567'])->assertOk();
        $this->app->terminate();
        $absorbed = Payment::findPublic($second);
        $this->assertSame(['0.00', '1.00', '0.60'], [$absorbed->fee, $absorbed->bank_fee, $absorbed->margin]);
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

    // ── The audit trail ─────────────────────────────────────────────────────

    private function trail(string $id): TestResponse
    {
        $staff = User::factory()->create(['company_id' => null, 'role' => 'super_admin', 'status' => 'active']);

        return $this->actingAs($staff)->getJson("/console-api/payments/{$id}")->assertOk();
    }

    public function test_the_trail_keeps_every_exchange_with_the_bank_without_its_secrets(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope([
                'uid' => 'u1', 'status' => 'PENDING', 'amount' => '48.00', 'clientSecret' => 'shh-secret',
                'sessionId' => 'SESSION0002', 'targetUrl' => 'https://na.gateway.mastercard.com/checkout/pay/SESSION0002',
            ])),
            self::BANK.'/sdk/mpgs/poll/u1' => Http::sequence()
                ->push($this->envelope(['uid' => 'u1', 'status' => 'PENDING', 'amount' => '48.00']))
                ->push($this->envelope(['uid' => 'u1', 'status' => 'PENDING', 'amount' => '48.00']))
                ->push($this->envelope(['uid' => 'u1', 'status' => 'PENDING', 'amount' => '48.00']))
                ->push($this->envelope(['uid' => 'u1', 'status' => 'FAILED', 'errorMessage' => 'Do not honour', 'amount' => '48.00'])),
        ]);
        $id = $this->payment();
        $this->get("/p/{$id}")->assertOk();
        $this->postJson("/p/{$id}/pay", ['method' => 'card'])->assertOk();
        foreach (range(1, 4) as $i) {
            $this->travel(5)->seconds();
            $this->getJson("/p/{$id}/status")->assertOk();
        }

        $payment = $this->trail($id)->json('payment');
        $events = collect($payment['events']);

        // Who did what, in order. Three identical "still pending" answers are ONE line, counted.
        $this->assertSame(
            ['payment.created', 'api.request', 'checkout.opened', 'attempt.started', 'bank.token', 'bank.request', 'bank.check', 'bank.check', 'attempt.failed'],
            $events->pluck('type')->all(),
        );
        $this->assertSame(['system', 'merchant', 'shopper', 'shopper', 'bank', 'bank', 'bank', 'bank', 'bank'], $events->pluck('actor')->all());
        $this->assertSame(3, $events[6]['data']['repeats']);
        $this->assertSame('error', $events[7]['level']);

        // What was sent and what came back are both there, with how long it took…
        $start = $events[5];
        $this->assertSame('POST /merchant/sdk/action', $start['data']['request']);
        $this->assertSame('48.00', $start['data']['sent']['amount']);
        $this->assertSame(200, $start['data']['http_status']);
        $this->assertSame('PENDING', $start['data']['received']['body']['status']);
        $this->assertIsInt($start['ms']);
        $this->assertSame('Do not honour', $events[7]['data']['received']['body']['errorMessage']);

        // …but nothing that works like a password.
        $all = json_encode($payment);
        foreach (['shh-secret', 'enc-key', 'tok-1', 'SESSION0002', 'checkout/pay'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertStringNotContainsString('"hash"', $all);

        // And the verdict points at the bank, with the bank's own words.
        $this->assertSame('bank', $payment['diagnosis']['where']);
        $this->assertStringContainsString('Do not honour', $payment['diagnosis']['detail']);
    }

    public function test_the_trail_tells_the_bank_being_down_from_our_own_fault(): void
    {
        // The bank cannot be reached at all.
        $this->bank([self::BANK.'/sdk/action' => fn () => throw new ConnectionException('Connection timed out after 8000 ms')]);
        $id = $this->payment();
        $this->postJson("/p/{$id}/pay", ['method' => 'card'])->assertStatus(422);

        $payment = $this->trail($id)->json('payment');
        $this->assertSame('bank', $payment['diagnosis']['where']);
        $call = collect($payment['events'])->firstWhere('type', 'bank.request');
        $this->assertSame('error', $call['level']);
        $this->assertStringContainsString('timed out', $call['data']['connection_error']);

        // Our own code breaks: that is said plainly, and the shopper gets a calm message.
        $this->mock(Client::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('observe');
            $mock->shouldReceive('initialize')->andThrow(new \LogicException('Undefined array key "x"'));
        });
        $second = $this->payment(['reference' => 'BLX-2']);
        $this->postJson("/p/{$second}/pay", ['method' => 'card'])->assertStatus(422)->assertJsonPath('message', 'Something went wrong on our side. Please try again.');

        $payment = $this->trail($second)->json('payment');
        $this->assertSame('system', $payment['diagnosis']['where']);
        $this->assertStringContainsString('LogicException', collect($payment['events'])->firstWhere('type', 'system.error')['data']['error']);
    }

    public function test_the_trail_blames_the_companys_system_when_only_its_callback_fails(): void
    {
        Http::fake([
            self::BANK.'/client/token' => Http::response($this->envelope(['access_token' => 'tok-1', 'expires_in' => 300])),
            self::BANK.'/sdk/action' => Http::response($this->envelope(['uid' => 'e1', 'status' => 'SUCCESS', 'amount' => '48.00', 'transaction' => ['debitReference' => 'FT1']])),
            'shop.test/*' => Http::response('<h1>500 Server Error</h1>', 500),
        ]);
        $id = $this->payment();
        $this->postJson("/p/{$id}/pay", ['method' => 'ecocash', 'phone' => '0771234567'])->assertOk();
        $this->app->terminate();

        $payment = $this->trail($id)->json('payment');
        $this->assertSame('paid', $payment['status']);
        $this->assertSame('merchant', $payment['diagnosis']['where']);
        $callback = collect($payment['events'])->firstWhere('type', 'callback.failed');
        $this->assertSame('merchant', $callback['actor']);
        $this->assertStringContainsString('500 Server Error', $callback['data']['their_answer']);
        $this->assertSame('payment.paid', $callback['data']['sent']['body']['event']);
        $this->assertSame(500, $callback['data']['http_status']);
    }

    public function test_transactions_can_be_filtered_sorted_and_paged(): void
    {
        $this->bank([
            self::BANK.'/sdk/action' => Http::response($this->envelope(['uid' => 'e1', 'status' => 'SUCCESS', 'amount' => '12.00', 'transaction' => ['debitReference' => 'FT1']])),
        ]);
        $paid = $this->payment(['amount' => '12.00', 'reference' => 'A-1']);
        $this->postJson("/p/{$paid}/pay", ['method' => 'ecocash', 'phone' => '0771234567'])->assertOk();
        $this->app->terminate();
        $this->payment(['amount' => '90.00', 'reference' => 'A-2']);
        $this->payment(['amount' => '500.00', 'reference' => 'A-3', 'currency' => 'ZWG']);

        $staff = User::factory()->create(['company_id' => null, 'role' => 'auditor', 'status' => 'active']);
        $list = fn (array $query) => $this->actingAs($staff)->getJson('/console-api/payments?'.http_build_query($query + ['mode' => 'live']))->assertOk();

        $all = $list([]);
        $this->assertSame(3, $all->json('total'));
        $this->assertSame(['paid' => 1, 'pending' => 2], $all->json('summary.by_status'));
        $this->assertSame([['currency' => 'USD', 'count' => 1, 'total' => '12.00']], $all->json('summary.paid'));

        $this->assertSame(['A-1'], $list(['method' => 'ecocash'])->json('payments.*.reference'));
        $this->assertSame(['A-3'], $list(['currency' => 'ZWG'])->json('payments.*.reference'));
        $this->assertSame(['A-2'], $list(['min' => '50', 'max' => '100'])->json('payments.*.reference'));
        $this->assertSame(['A-1'], $list(['callback' => 'delivered'])->json('payments.*.reference'));
        $this->assertSame(['A-1'], $list(['q' => '0771234567'])->json('payments.*.reference'));      // by the shopper's number
        $this->assertSame(['A-1'], $list(['q' => PaymentAttempt::first()->reference])->json('payments.*.reference')); // by what the bank calls it
        $this->assertSame(['A-3', 'A-2', 'A-1'], $list(['sort' => 'amount_high'])->json('payments.*.reference'));

        $second = $list(['per_page' => 10, 'sort' => 'oldest', 'page' => 1]);
        $this->assertSame([1, 3, 1], [$second->json('from'), $second->json('to'), $second->json('pages')]);
        $this->actingAs($staff)->getJson('/console-api/payments?method=cheque')->assertStatus(422);
    }

    public function test_the_api_log_keeps_each_request_and_answer_in_full_per_company(): void
    {
        $this->bank([]);
        $id = $this->payment(['reference' => 'LOG-1']);
        foreach (range(1, 3) as $i) {
            $this->withToken($this->live)->getJson("/api/v1/payments/{$id}")->assertOk(); // a shop polling
        }
        $this->withToken($this->live)->postJson('/api/v1/payments', ['amount' => '-1', 'currency' => 'USD'])->assertStatus(422); // never became a payment
        $this->withToken('sk_live_wrong')->getJson("/api/v1/payments/{$id}")->assertStatus(401);

        $other = Company::create(['name' => 'Kopje', 'slug' => 'kopje', 'status' => 'active', 'callback_secret' => 'x']);
        $staff = User::factory()->create(['company_id' => null, 'role' => 'support', 'status' => 'active']);
        $outsider = User::factory()->create(['company_id' => $other->id, 'role' => 'owner', 'status' => 'active']);
        $owner = User::factory()->create(['company_id' => Company::where('slug', 'blessluxe')->value('id'), 'role' => 'developer', 'status' => 'active']);

        $log = $this->actingAs($staff)->getJson('/console-api/api-log?mode=live')->assertOk();
        $this->assertSame([401, 422, 200, 201], $log->json('requests.*.status')); // newest first; three identical reads are one line
        $this->assertSame(3, $log->json('requests.2.repeats'));
        $this->assertNull($log->json('requests.0.company'));

        $created = $log->json('requests.3');
        $this->assertSame('LOG-1', $created['request']['body']['reference']);
        $this->assertSame($id, $created['response']['id']);
        $this->assertSame($id, $created['payment_id']);
        $this->assertStringNotContainsString($this->live, json_encode($log->json())); // never the whole key
        $this->assertSame('The amount field format is invalid.', $log->json('requests.1.response.errors.amount.0'));

        // A company sees its own calls only, and not the ones made with an unknown key.
        $this->assertSame([422, 200, 201], $this->actingAs($owner)->getJson('/console-api/api-log?mode=live')->json('requests.*.status'));
        $this->assertSame([], $this->actingAs($outsider)->getJson('/console-api/api-log?mode=live')->json('requests'));
        $this->assertSame([401, 422], $this->actingAs($staff)->getJson('/console-api/api-log?mode=live&result=failed')->json('requests.*.status'));

        // The same exchange is a step in the payment's own trail, and staff see the figures per company.
        $step = collect($this->trail($id)->json('payment.events'))->firstWhere('type', 'api.request');
        $this->assertSame('POST /api/v1/payments', $step['data']['request']);
        $this->assertSame(201, $step['data']['http_status']);
        $overview = $this->actingAs($staff)->getJson('/console-api/overview?mode=live')->assertOk();
        $this->assertSame(['BlessLuxe' => 1, 'Kopje' => 0], collect($overview->json('companies'))->pluck('transactions', 'name')->all());
        $this->assertNull($this->actingAs($owner)->getJson('/console-api/overview?mode=live')->json('companies'));
    }
}
