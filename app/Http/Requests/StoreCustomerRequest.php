<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCustomerRequest extends FormRequest
{
    // A constant isn't an executable line, so mutation testing can't see it covered.
    // CustomerTest accepts each currency explicitly.
    public const array CURRENCIES = ['GBP', 'EUR', 'USD']; // @pest-mutate-ignore

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('customers')->where('user_id', $user->id)],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'webhook_url' => ['nullable', 'url:https', 'max:2048', 'required_with:webhook_secret'],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:255', 'required_with:webhook_url'],
        ];
    }
}
