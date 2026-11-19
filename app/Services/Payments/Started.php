<?php

namespace App\Services\Payments;

/** What the gateway gave back when a payment was opened. */
final class Started
{
    /** @param  array<string, string|null>  $display  for gateways paid in their own app: qr (data URL), code, app_link */
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $redirectUrl = null,
        public readonly array $display = [],
    ) {}
}
