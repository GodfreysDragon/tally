<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateInvoice;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class InvoiceController extends Controller
{
    public function index(Request $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'overdue' => ['nullable', 'boolean'],
        ]);

        $query = $user->invoices()->getQuery()->with('customer')->latest('id');

        if (($status = $request->enum('status', InvoiceStatus::class)) !== null) {
            $query->where('status', $status);
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        return InvoiceResource::collection($query->paginate());
    }

    public function store(StoreInvoiceRequest $request, CreateInvoice $createInvoice): JsonResponse
    {
        $customer = Customer::query()->findOrFail($request->integer('customer_id'));

        $invoice = $createInvoice->handle(
            $customer,
            $request->lines(),
            $request->integer('tax_rate'),
            $request->dueAt(),
            $request->string('notes')->toString() ?: null,
        );

        return InvoiceResource::make($invoice->load('customer', 'lines'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return InvoiceResource::make($invoice->load('customer', 'lines', 'payments'));
    }
}
