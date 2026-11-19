<?php

namespace App\Services\Payments;

use App\Models\OnlinePayment;
use Illuminate\Http\Request;

/**
 * One Iraqi payment gateway. The system never trusts what the browser brings back: a payment counts only
 * after status() asks the gateway directly and it says paid, for the same amount.
 */
interface Gateway
{
    public function key(): string;

    public function label(): string;

    /** True when its merchant credentials are set. */
    public function enabled(): bool;

    /** Opens the payment at the gateway. $returnUrl is where the payer comes back; $notifyUrl receives the gateway's server call. */
    public function start(OnlinePayment $payment, string $returnUrl, string $notifyUrl): Started;

    /** Asks the gateway for the payment's current state. */
    public function status(OnlinePayment $payment): Status;

    /**
     * The gateway's id for a payment, read from the payer's return or the gateway's server call, if it carries one.
     * Used only to find the payment; its state is then read with status().
     */
    public function externalIdFrom(Request $request): ?string;
}
