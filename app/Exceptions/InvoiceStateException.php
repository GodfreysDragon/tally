<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\InvoiceStatus;
use App\Support\Money;
use DomainException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request that is well-formed but not allowed by the invoice's current state.
 */
final class InvoiceStateException extends DomainException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalidTransition(InvoiceStatus $from, InvoiceStatus $to): self
    {
        return new self("An invoice cannot move from {$from->value} to {$to->value}.", Response::HTTP_CONFLICT);
    }

    public static function notPayable(InvoiceStatus $status): self
    {
        return new self("Payments can only be recorded against sent invoices; this one is {$status->value}.", Response::HTTP_CONFLICT);
    }

    public static function exceedsBalance(Money $payment, Money $balance): self
    {
        return new self("A payment of {$payment->format()} exceeds the outstanding balance of {$balance->format()}.", Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function hasPayments(): self
    {
        return new self('An invoice with recorded payments cannot be voided.', Response::HTTP_CONFLICT);
    }

    public static function empty(): self
    {
        return new self('An invoice must have at least one line before it is sent.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
