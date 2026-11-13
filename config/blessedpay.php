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

];
