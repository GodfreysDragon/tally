<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\SendInvoice;
use App\Actions\VoidInvoice;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;

final class InvoiceStatusController extends Controller
{
    public function send(Invoice $invoice, SendInvoice $sendInvoice): InvoiceResource
    {
        Gate::authorize('update', $invoice);

        return InvoiceResource::make($sendInvoice->handle($invoice));
    }

    public function void(Invoice $invoice, VoidInvoice $voidInvoice): InvoiceResource
    {
        Gate::authorize('update', $invoice);

        return InvoiceResource::make($voidInvoice->handle($invoice));
    }
}
