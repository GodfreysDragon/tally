<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\LineInput;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class StoreInvoiceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            // Scoped to the caller, so another user's customer reads as "not found", not "forbidden".
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('user_id', $user->id)],
            'tax_rate' => ['required', 'integer', 'between:0,10000'],
            'due_at' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'between:1,1000000'],
            'lines.*.unit_price' => ['required', 'integer', 'between:0,100000000'],
            'lines.*.discount_rate' => ['nullable', 'integer', 'between:0,10000'],
        ];
    }

    /**
     * @return list<LineInput>
     */
    public function lines(): array
    {
        /** @var list<array{description: string, quantity: int, unit_price: int, discount_rate?: int|null}> $lines */
        $lines = $this->validated('lines');

        return array_map(LineInput::fromArray(...), $lines);
    }

    public function dueAt(): ?Carbon
    {
        return $this->date('due_at');
    }
}
