<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The way in when nobody can sign in: creates a super admin, or gives an
 * existing one a new password. Everyone else is invited from the console or
 * signs their own company up.
 *
 *   php artisan blessedpay:admin                 the owner in config/blessedpay.php; asks for a password
 *   php artisan blessedpay:admin a@b.c           someone else
 *   php artisan blessedpay:admin --ensure        for deploys: create the owner if missing, using
 *                                                BLESSEDPAY_ADMIN_PASSWORD; never touches an existing account
 */
class MakeAdmin extends Command
{
    protected $signature = 'blessedpay:admin
        {email? : Defaults to the owner in config/blessedpay.php}
        {--name= : Defaults to the owner\'s name}
        {--password= : Leave out to be asked (not shown as you type)}
        {--ensure : Only create the account if it is missing; safe to run on every deploy}';

    protected $description = "Create a super admin, or reset one's password";

    public function handle(): int
    {
        $email = strtolower($this->argument('email') ?: (string) config('blessedpay.admin.email'));
        $user = User::where('email', $email)->first();

        if ($this->option('ensure')) {
            if ($user) {
                $this->info("{$email} already exists. Nothing changed.");

                return self::SUCCESS;
            }

            $password = $this->option('password') ?: config('blessedpay.admin.password');

            if (! $password) {
                // Not a failure: a deploy must not break because the variable was removed after first use.
                $this->warn("{$email} does not exist yet and BLESSEDPAY_ADMIN_PASSWORD is not set, so no admin was created.");

                return self::SUCCESS;
            }
        } else {
            $password = $this->option('password') ?: $this->secret('Password (12 characters or more)');
        }

        $check = Validator::make(compact('email', 'password'), [
            'email' => ['required', 'email'],
            'password' => ['required', Password::min(12)],
        ]);

        if ($check->fails()) {
            foreach ($check->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($user && ! $user->isStaff()) {
            $this->error('That email belongs to a company member, not to BlessedPay staff.');

            return self::FAILURE;
        }

        $existed = (bool) $user;
        $defaultName = $email === strtolower((string) config('blessedpay.admin.email')) ? config('blessedpay.admin.name') : 'Admin';
        $user ??= new User(['email' => $email, 'name' => $defaultName]);
        $user->forceFill([
            'name' => $this->option('name') ?: $user->name,
            'password' => $password,
            'company_id' => null,
            'role' => 'super_admin',
            'status' => 'active',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->info($existed ? "Password reset for {$email} (super admin)." : "Super admin {$user->name} <{$email}> created.");

        return self::SUCCESS;
    }
}
