<?php

return [

    /*
    | The first super admin. `php artisan blessedpay:admin --ensure` (run on
    | every deploy) creates this account once, if it does not exist yet, using
    | BLESSEDPAY_ADMIN_PASSWORD. The password is never kept in the code: set it
    | as an environment variable, sign in, then remove the variable.
    */
    'admin' => [
        'email' => env('BLESSEDPAY_ADMIN_EMAIL', 'gshoniwa@gmail.com'),
        'name' => env('BLESSEDPAY_ADMIN_NAME', 'Gregory Shoniwa'),
        'password' => env('BLESSEDPAY_ADMIN_PASSWORD'),
    ],

    /*
    | The bank's processing fee (see App\Services\Fees). Told to us by the bank:
    | 1% on every method, with a US$0.20 minimum on cards.
    */
    'fee' => [
        'percent' => (float) env('BLESSEDPAY_FEE_PERCENT', 1.0),
        'minimum' => (float) env('BLESSEDPAY_FEE_MINIMUM', 0.20),
    ],

    /*
    | The ways of paying the checkout offers, in order: ecocash, card, bank.
    | "bank" (pay from a TN CyberTech Bank account) is built but switched off
    | for now at the owner's request (13 Nov 2026): add it back here, or set
    | BLESSEDPAY_METHODS=ecocash,card,bank, to show it again.
    */
    'methods' => array_values(array_filter(array_map('trim', explode(',', (string) env('BLESSEDPAY_METHODS', 'ecocash,card'))))),

    // How long a shopper has to finish paying before the payment is closed.
    'payment_minutes' => (int) env('BLESSEDPAY_PAYMENT_MINUTES', 30),

];
