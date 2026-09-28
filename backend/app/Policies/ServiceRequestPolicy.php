<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class ServiceRequestPolicy
{
    public function manage(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::ItStaff;
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::Employee;
    }
}
