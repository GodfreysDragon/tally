<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\RecordPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request, Invoice $invoice, RecordPayment $recordPayment): JsonResponse
    {
        Gate::authorize('update', $invoice);

        $payment = $recordPayment->handle(
            $invoice,
            $request->integer('amount'),
            $request->string('reference')->toString() ?: null,
            $request->date('received_at'),
        );

        return PaymentResource::make($payment)->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
