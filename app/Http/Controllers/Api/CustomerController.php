<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class CustomerController extends Controller
{
    public function index(#[CurrentUser] User $user): AnonymousResourceCollection
    {
        return CustomerResource::collection($user->customers()->orderBy('name')->paginate());
    }

    public function store(StoreCustomerRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $customer = $user->customers()->create($request->validated());

        return CustomerResource::make($customer)->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return CustomerResource::make($customer);
    }
}
