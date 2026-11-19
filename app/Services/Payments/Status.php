<?php

namespace App\Services\Payments;

/** The gateway's answer about one payment. */
final class Status
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $state,
        public readonly ?int $amount = null,
        public readonly ?string $message = null,
        public readonly ?string $reference = null,
    ) {}
}
