<?php

namespace App\Services\Gateways;

use RuntimeException;

/** The processor can't take this payment right now (not configured, or unreachable). */
class GatewayUnavailable extends RuntimeException {}
