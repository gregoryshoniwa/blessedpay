<?php

namespace App\Services\Gateways;

use App\Services\Gateways\Contracts\BankGateway;

/** The one place processors are wired. */
final class Gateways
{
    /** @return array<string, BankGateway> */
    public static function all(): array
    {
        return [
            'sandbox' => new SandboxGateway,
            'stewardpay' => new StewardPayGateway,
        ];
    }

    public static function get(string $id): BankGateway
    {
        return self::all()[$id] ?? throw new GatewayUnavailable("Unknown gateway [{$id}].");
    }

    /** Test keys always go to the sandbox; live keys go to the bank. */
    public static function forMode(string $mode): BankGateway
    {
        return self::get($mode === 'live' ? 'stewardpay' : 'sandbox');
    }
}
