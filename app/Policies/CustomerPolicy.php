<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

final class CustomerPolicy
{
    public function view(User $user, Customer $customer): bool
    {
        return $customer->user_id === $user->id;
    }
}
