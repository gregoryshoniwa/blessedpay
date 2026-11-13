<?php

namespace App\Services;

use RuntimeException;

/** An Idempotency-Key was reused for a payment with a different amount, currency or reference. */
class IdempotencyConflict extends RuntimeException {}
