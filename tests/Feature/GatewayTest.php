<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Payment;
use App\Models\User;
use App\Services\CallbackSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name = 'BlessLuxe'): Company
    {
        return Company::create(['name' => $name, 'slug' => strtolower($name), 'status' => 'active', 'callback_secret' => 'whsec_test']);
    }

    private function staff(string $role = 'super_admin'): User
    {
        return User::factory()->create(['company_id' => null, 'role' => $role, 'status' => 'active']);
    }

    private function member(Company $company, string $role): User
    {
        return User::factory()->create(['company_id' => $company->id, 'role' => $role, 'status' => 'active']);
    }

    private function body(array $over = []): array
    {
        return $over + [
            'amount' => '48.00', 'currency' => 'USD', 'reference' => 'BLX-10482',
            'return_url' => 'https://shop.test/thanks', 'callback_url' => 'https://shop.test/hooks/pay',
        ];
    }

    // ── The company-facing API ───────────────────────────────────────────────

    public function test_api_needs_a_working_key(): void
    {
        $this->postJson('/api/v1/payments', $this->body())->assertStatus(401);
        $this->withToken('sk_test_nope')->postJson('/api/v1/payments', $this->body())->assertStatus(401);

        [$key, $plain] = ApiKey::issue($this->company(), 'Shop', 'test');
        $key->update(['revoked_at' => now()]);
        $this->withToken($plain)->postJson('/api/v1/payments', $this->body())->assertStatus(401);
    }

    public function test_a_suspended_company_cannot_use_its_key(): void
    {
        $company = $this->company();
        [, $plain] = ApiKey::issue($company, 'Shop', 'test');
        $company->update(['status' => 'suspended']);

        $this->withToken($plain)->postJson('/api/v1/payments', $this->body())->assertStatus(401);
    }

    public function test_start_a_payment_and_read_it_back(): void
    {
        [, $plain] = ApiKey::issue($this->company(), 'Shop', 'test');

        $created = $this->withToken($plain)->postJson('/api/v1/payments', $this->body())
            ->assertStatus(201)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('amount', '48.00')
            ->assertJsonPath('mode', 'test');

        $id = $created->json('id');
        $this->assertStringStartsWith('pay_', $id);
        $this->assertStringContainsString('/p/'.$id, $created->json('redirect_url'));

        $this->withToken($plain)->getJson("/api/v1/payments/{$id}")->assertOk()->assertJsonPath('reference', 'BLX-10482');
    }

    public function test_bad_amounts_are_refused(): void
    {
        [, $plain] = ApiKey::issue($this->company(), 'Shop', 'test');

        foreach (['0', '0.00', '-5', '12.345', 'abc', ''] as $amount) {
            $this->withToken($plain)->postJson('/api/v1/payments', $this->body(['amount' => $amount]))->assertStatus(422);
        }
    }

    public function test_the_same_idempotency_key_never_makes_a_second_payment(): void
    {
        [, $plain] = ApiKey::issue($this->company(), 'Shop', 'test');
        $headers = ['Idempotency-Key' => 'order-10482'];

        $first = $this->withToken($plain)->postJson('/api/v1/payments', $this->body(), $headers)->assertStatus(201);
        $again = $this->withToken($plain)->postJson('/api/v1/payments', $this->body(), $headers)->assertStatus(200);

        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, Payment::count());

        // Same key, different money: refused rather than silently returning the old payment.
        $this->withToken($plain)->postJson('/api/v1/payments', $this->body(['amount' => '99.00']), $headers)->assertStatus(409);
    }

    public function test_one_company_cannot_read_anothers_payment(): void
    {
        [, $a] = ApiKey::issue($this->company('Alpha'), 'A', 'test');
        [, $b] = ApiKey::issue($this->company('Beta'), 'B', 'test');

        $id = $this->withToken($a)->postJson('/api/v1/payments', $this->body())->json('id');

        $this->withToken($b)->getJson("/api/v1/payments/{$id}")->assertStatus(404);
    }

    public function test_live_keys_are_refused_until_the_bank_integration_exists(): void
    {
        [, $plain] = ApiKey::issue($this->company(), 'Shop', 'live');

        $this->withToken($plain)->postJson('/api/v1/payments', $this->body())
            ->assertStatus(503)->assertJsonPath('error.code', 'live_not_available');
        $this->assertSame(0, Payment::count());
    }

    // ── The shopper's journey and the callback ───────────────────────────────

    public function test_sandbox_payment_is_settled_and_the_company_is_told_with_a_valid_signature(): void
    {
        Http::fake(['shop.test/*' => Http::response('ok')]);
        [, $plain] = ApiKey::issue($this->company(), 'Shop', 'test');
        $id = $this->withToken($plain)->postJson('/api/v1/payments', $this->body())->json('id');

        $this->get("/p/{$id}")->assertOk()->assertSee('BlessLuxe')->assertSee('48.00');
        $this->post("/p/{$id}/continue")->assertRedirectContains("/sandbox/{$id}");
        $this->get("/sandbox/{$id}")->assertOk();

        $back = $this->post("/sandbox/{$id}", ['outcome' => 'paid']);
        $back->assertRedirectContains('https://shop.test/thanks?payment_id='.$id);
        $back->assertRedirectContains('status=paid');

        $payment = Payment::findPublic($id);
        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('delivered', $payment->callback_status);

        Http::assertSent(function (ClientRequest $request) use ($id) {
            preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $request->header('X-BlessedPay-Signature')[0], $m);

            return $request->url() === 'https://shop.test/hooks/pay'
                && $m
                && hash_equals(CallbackSender::sign((int) $m[1], $request->body(), 'whsec_test'), $m[2])
                && $request['event'] === 'payment.paid'
                && $request['payment']['id'] === $id
                && $request->header('X-BlessedPay-Event')[0] === $id.'.paid';
        });

        $this->assertSame(
            ['payment.created', 'shopper.redirected', 'payment.paid', 'callback.delivered'],
            $payment->events->pluck('type')->all(),
        );
    }

    public function test_once_paid_stays_paid_and_tells_the_company_only_once(): void
    {
        Http::fake(['shop.test/*' => Http::response('ok')]);
        [, $plain] = ApiKey::issue($this->company(), 'Shop', 'test');
        $id = $this->withToken($plain)->postJson('/api/v1/payments', $this->body())->json('id');

        $this->post("/sandbox/{$id}", ['outcome' => 'paid']);
        $this->post("/sandbox/{$id}", ['outcome' => 'failed']);
        $this->post("/sandbox/{$id}", ['outcome' => 'paid']);

        $this->assertSame('paid', Payment::findPublic($id)->status);
        Http::assertSentCount(1);
    }

    public function test_a_failed_callback_is_recorded_and_can_be_retried(): void
    {
        Http::fake(['shop.test/*' => Http::sequence()->push('down', 500)->push('ok', 200)]);
        [, $plain] = ApiKey::issue($this->company(), 'Shop', 'test');
        $id = $this->withToken($plain)->postJson('/api/v1/payments', $this->body())->json('id');

        $this->post("/sandbox/{$id}", ['outcome' => 'paid']);
        $this->assertSame('failed', Payment::findPublic($id)->callback_status);

        $this->artisan('payments:callbacks')->assertSuccessful();
        $payment = Payment::findPublic($id);
        $this->assertSame('delivered', $payment->callback_status);
        $this->assertSame(2, $payment->callback_attempts);
    }

    public function test_callbacks_to_private_addresses_are_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        foreach (['http://127.0.0.1/hook', 'http://10.0.0.5/hook', 'http://169.254.169.254/latest', 'http://localhost/hook', 'http://[::1]/hook', 'not a url'] as $url) {
            $this->assertFalse(CallbackSender::isPublicAddress($url), $url);
        }
        $this->assertTrue(CallbackSender::isPublicAddress('https://1.1.1.1/hook'));
    }

    public function test_the_sandbox_page_does_not_exist_for_live_payments(): void
    {
        $payment = Payment::create([
            'company_id' => $this->company()->id, 'mode' => 'live', 'reference' => 'R1', 'amount' => '5.00',
            'currency' => 'USD', 'gateway' => 'stewardpay', 'return_url' => 'https://shop.test/thanks',
        ]);

        $this->get('/sandbox/'.$payment->publicId())->assertStatus(404);
        $this->post('/sandbox/'.$payment->publicId(), ['outcome' => 'paid'])->assertStatus(404);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    // ── The console ──────────────────────────────────────────────────────────

    public function test_sign_in_and_out(): void
    {
        $user = $this->staff();

        $this->getJson('/console-api/me')->assertStatus(401);
        $this->postJson('/console-api/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/console-api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.role', 'super_admin')->assertJsonPath('user.is_staff', true);
        $this->getJson('/console-api/me')->assertOk();
        $this->postJson('/console-api/logout')->assertOk();
        $this->getJson('/console-api/me')->assertStatus(401);
    }

    public function test_a_suspended_user_is_shut_out(): void
    {
        $user = $this->staff('support');
        $user->update(['status' => 'suspended']);

        $this->postJson('/console-api/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(403);
        $this->actingAs($user)->getJson('/console-api/me')->assertStatus(401);
    }

    public function test_staff_create_a_company_and_issue_a_key_that_works(): void
    {
        $admin = $this->staff();

        $companyId = $this->actingAs($admin)->postJson('/console-api/companies', ['name' => 'BlessLuxe', 'website' => 'https://blessluxe.com'])
            ->assertStatus(201)->json('company.id');

        $key = $this->actingAs($admin)->postJson("/console-api/companies/{$companyId}/keys", ['name' => 'Shop', 'mode' => 'test'])
            ->assertStatus(201);
        $plain = $key->json('plain');
        $this->assertStringStartsWith('sk_test_', $plain);

        // The list never shows the full key again.
        $list = $this->actingAs($admin)->getJson("/console-api/companies/{$companyId}/keys")->assertOk();
        $this->assertStringNotContainsString($plain, $list->getContent());

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/payments', $this->body())->assertStatus(201);
    }

    public function test_members_see_only_their_own_company(): void
    {
        $alpha = $this->company('Alpha');
        $beta = $this->company('Beta');
        $viewer = $this->member($alpha, 'viewer');

        $mine = Payment::create(['company_id' => $alpha->id, 'mode' => 'live', 'reference' => 'A-1', 'amount' => '1.00', 'currency' => 'USD', 'gateway' => 'sandbox', 'return_url' => 'https://a.test']);
        $theirs = Payment::create(['company_id' => $beta->id, 'mode' => 'live', 'reference' => 'B-1', 'amount' => '2.00', 'currency' => 'USD', 'gateway' => 'sandbox', 'return_url' => 'https://b.test']);

        $list = $this->actingAs($viewer)->getJson('/console-api/payments?company_id='.$beta->id)->assertOk();
        $this->assertSame(['A-1'], collect($list->json('payments'))->pluck('reference')->all());

        $this->actingAs($viewer)->getJson('/console-api/payments/'.$mine->publicId())->assertOk();
        $this->actingAs($viewer)->getJson('/console-api/payments/'.$theirs->publicId())->assertStatus(404);
        $this->actingAs($viewer)->getJson('/console-api/companies/'.$beta->id)->assertStatus(404);
        $this->assertCount(1, $this->actingAs($viewer)->getJson('/console-api/companies')->json('companies'));
    }

    public function test_roles_limit_what_a_member_can_do(): void
    {
        $company = $this->company();
        $viewer = $this->member($company, 'viewer');
        $finance = $this->member($company, 'finance');
        $developer = $this->member($company, 'developer');

        $this->actingAs($viewer)->postJson('/console-api/companies', ['name' => 'Mine'])->assertStatus(403);
        $this->actingAs($viewer)->getJson("/console-api/companies/{$company->id}/keys")->assertStatus(403);
        $this->actingAs($viewer)->get('/console-api/payments/export')->assertStatus(403);
        $this->actingAs($finance)->get('/console-api/payments/export')->assertOk();
        $this->actingAs($finance)->postJson("/console-api/companies/{$company->id}/keys", ['name' => 'x', 'mode' => 'test'])->assertStatus(403);
        $this->actingAs($developer)->postJson("/console-api/companies/{$company->id}/keys", ['name' => 'x', 'mode' => 'test'])->assertStatus(201);
        $this->actingAs($developer)->postJson('/console-api/invitations', ['email' => 'new@a.test', 'role' => 'viewer'])->assertStatus(403);
        $this->actingAs($developer)->putJson("/console-api/companies/{$company->id}", ['status' => 'suspended'])->assertStatus(403);
    }

    public function test_invitation_from_send_to_sign_in(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');

        $sent = $this->actingAs($owner)->postJson('/console-api/invitations', ['email' => 'Finance@Shop.test', 'role' => 'finance'])->assertStatus(201);
        $token = basename($sent->json('link'));

        // An owner cannot hand out ownership, or reach into staff.
        $this->actingAs($owner)->postJson('/console-api/invitations', ['email' => 'x@shop.test', 'role' => 'owner'])->assertStatus(422);
        $this->actingAs($owner)->postJson('/console-api/invitations', ['email' => 'y@shop.test', 'role' => 'super_admin'])->assertStatus(422);

        $this->postJson('/console-api/logout');
        $this->app['auth']->forgetGuards();

        $this->getJson("/console-api/invitations/{$token}")->assertOk()->assertJsonPath('company', 'BlessLuxe');
        $this->postJson("/console-api/invitations/{$token}", ['name' => 'Fin', 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->postJson("/console-api/invitations/{$token}", ['name' => 'Fin', 'password' => 'a-long-passphrase', 'password_confirmation' => 'a-long-passphrase'])
            ->assertOk()->assertJsonPath('user.role', 'finance')->assertJsonPath('user.company.name', 'BlessLuxe');

        $this->assertDatabaseHas('users', ['email' => 'finance@shop.test', 'company_id' => $company->id, 'role' => 'finance']);

        // Single use.
        $this->postJson("/console-api/invitations/{$token}", ['name' => 'Again', 'password' => 'a-long-passphrase', 'password_confirmation' => 'a-long-passphrase'])->assertStatus(404);
    }

    public function test_expired_and_revoked_invitations_do_not_work(): void
    {
        $admin = $this->staff();
        $sent = $this->actingAs($admin)->postJson('/console-api/invitations', ['email' => 'ops@pay.test', 'role' => 'operations'])->assertStatus(201);
        $token = basename($sent->json('link'));

        Invitation::first()->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/console-api/invitations/{$token}")->assertStatus(404);

        $resent = $this->actingAs($admin)->postJson('/console-api/invitations/'.Invitation::first()->id.'/resend')->assertOk();
        $newToken = basename($resent->json('link'));
        $this->getJson("/console-api/invitations/{$token}")->assertStatus(404);   // old link dead
        $this->getJson("/console-api/invitations/{$newToken}")->assertOk();

        $this->actingAs($admin)->deleteJson('/console-api/invitations/'.Invitation::first()->id)->assertOk();
        $this->getJson("/console-api/invitations/{$newToken}")->assertStatus(404);
    }

    public function test_team_rules_protect_owners_self_and_the_last_super_admin(): void
    {
        $company = $this->company();
        $owner = $this->member($company, 'owner');
        $admin = $this->member($company, 'admin');
        $viewer = $this->member($company, 'viewer');
        $super = $this->staff();
        $otherCompanyUser = $this->member($this->company('Other'), 'viewer');

        $this->actingAs($admin)->putJson("/console-api/team/{$viewer->id}", ['role' => 'finance'])->assertOk();
        $this->actingAs($admin)->putJson("/console-api/team/{$owner->id}", ['role' => 'viewer'])->assertStatus(422);
        $this->actingAs($admin)->putJson("/console-api/team/{$admin->id}", ['role' => 'viewer'])->assertStatus(422);
        $this->actingAs($admin)->putJson("/console-api/team/{$viewer->id}", ['role' => 'super_admin'])->assertStatus(422);
        $this->actingAs($admin)->putJson("/console-api/team/{$otherCompanyUser->id}", ['role' => 'finance'])->assertStatus(404);
        $this->actingAs($admin)->putJson("/console-api/team/{$super->id}", ['role' => 'support'])->assertStatus(404);

        $second = $this->staff();
        $this->actingAs($second)->deleteJson("/console-api/team/{$super->id}")->assertOk();
        $third = $this->staff('operations');
        $this->actingAs($third)->putJson("/console-api/team/{$second->id}", ['status' => 'suspended'])->assertStatus(403);
        $this->assertSame('active', $second->fresh()->status);
    }

    // ── Self sign-up ─────────────────────────────────────────────────────────

    private function signup(array $over = []): array
    {
        return $over + [
            'company_name' => 'Kopje Hardware', 'website' => 'https://kopje.test', 'name' => 'Tari',
            'email' => 'Tari@Kopje.test', 'password' => 'a-long-passphrase', 'password_confirmation' => 'a-long-passphrase',
        ];
    }

    public function test_someone_can_sign_up_and_create_their_own_company_which_starts_pending(): void
    {
        $this->postJson('/console-api/register', $this->signup())
            ->assertStatus(201)
            ->assertJsonPath('user.role', 'owner')
            ->assertJsonPath('user.is_staff', false)
            ->assertJsonPath('user.company.name', 'Kopje Hardware')
            ->assertJsonPath('user.company.status', 'pending');

        $this->assertDatabaseHas('users', ['email' => 'tari@kopje.test', 'role' => 'owner']);
        $this->getJson('/console-api/me')->assertOk();                         // signed in straight away
        $this->assertCount(1, $this->getJson('/console-api/companies')->json('companies'));
    }

    public function test_sign_up_refuses_duplicates_weak_passwords_and_cannot_choose_a_role(): void
    {
        $this->company('Kopje Hardware');
        $this->staff()->update(['email' => 'taken@pay.test']);

        $this->postJson('/console-api/register', $this->signup())->assertStatus(422)->assertJsonValidationErrors('company_name');
        $this->postJson('/console-api/register', $this->signup(['company_name' => 'New Co', 'email' => 'taken@pay.test']))->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/console-api/register', $this->signup(['company_name' => 'New Co', 'password' => 'short', 'password_confirmation' => 'short']))->assertStatus(422);
        $this->assertSame(1, Company::count());

        // Extra fields are ignored: nobody can sign up as staff or pre-approved.
        $this->postJson('/console-api/register', $this->signup(['company_name' => 'New Co', 'role' => 'super_admin', 'company_id' => null, 'status' => 'active']))->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'tari@kopje.test', 'role' => 'owner']);
        $this->assertSame('pending', Company::where('name', 'New Co')->value('status'));
    }

    public function test_a_pending_company_is_test_only_until_staff_approve_it(): void
    {
        $companyId = $this->postJson('/console-api/register', $this->signup())->json('user.company.id');
        $owner = User::where('email', 'tari@kopje.test')->first();

        // Test keys work at once; live keys cannot even be created.
        $plain = $this->postJson("/console-api/companies/{$companyId}/keys", ['name' => 'Try', 'mode' => 'test'])->assertStatus(201)->json('plain');
        $this->postJson("/console-api/companies/{$companyId}/keys", ['name' => 'Real', 'mode' => 'live'])->assertStatus(422);

        // The owner cannot approve their own company.
        $this->putJson("/console-api/companies/{$companyId}", ['status' => 'active'])->assertStatus(403);
        $this->assertSame('pending', Company::find($companyId)->status);

        // A live key that somehow exists is still refused while pending.
        [, $live] = ApiKey::issue(Company::find($companyId), 'Old', 'live');
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/payments', $this->body())->assertStatus(201);
        $this->withToken($live)->postJson('/api/v1/payments', $this->body())->assertStatus(403)->assertJsonPath('error.code', 'company_not_approved');

        // Staff approve it; the owner can then create live keys.
        $this->actingAs($this->staff('operations'))->putJson("/console-api/companies/{$companyId}", ['status' => 'active'])->assertOk()->assertJsonPath('company.status', 'active');
        $this->actingAs($owner)->postJson("/console-api/companies/{$companyId}/keys", ['name' => 'Real', 'mode' => 'live'])->assertStatus(201);
        $this->assertDatabaseHas('activity_logs', ['action' => 'company.approved', 'company_id' => $companyId]);
    }

    public function test_the_deploy_step_creates_the_owner_admin_once_and_never_overwrites_it(): void
    {
        // No password configured: nothing is created, and the deploy does not fail.
        $this->artisan('blessedpay:admin', ['--ensure' => true])->assertSuccessful();
        $this->assertSame(0, User::count());

        config(['blessedpay.admin.password' => 'first-deploy-passphrase']);
        $this->artisan('blessedpay:admin', ['--ensure' => true])->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'gshoniwa@gmail.com', 'name' => 'Gregory Shoniwa', 'role' => 'super_admin', 'company_id' => null]);

        // A later deploy with a different value must not reset the password.
        config(['blessedpay.admin.password' => 'something-else-entirely']);
        $this->artisan('blessedpay:admin', ['--ensure' => true])->assertSuccessful();
        $this->postJson('/console-api/login', ['email' => 'gshoniwa@gmail.com', 'password' => 'first-deploy-passphrase'])->assertOk();
        $this->assertSame(1, User::count());
    }

    public function test_the_admin_command_creates_and_resets_a_super_admin(): void
    {
        $this->artisan('blessedpay:admin', ['email' => 'me@pay.test', '--name' => 'Me', '--password' => 'a-long-passphrase'])->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'me@pay.test', 'role' => 'super_admin', 'company_id' => null]);

        $this->artisan('blessedpay:admin', ['email' => 'me@pay.test', '--password' => 'another-passphrase'])->assertSuccessful();
        $this->postJson('/console-api/login', ['email' => 'me@pay.test', 'password' => 'another-passphrase'])->assertOk();

        $this->artisan('blessedpay:admin', ['email' => 'me@pay.test', '--password' => 'short'])->assertFailed();
    }

    public function test_pages_load(): void
    {
        $this->get('/')->assertOk()->assertSee('BlessedPay');
        $this->get('/console')->assertOk();
        $this->get('/console/payments')->assertOk();
    }
}
