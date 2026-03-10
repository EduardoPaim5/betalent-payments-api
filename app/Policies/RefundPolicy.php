<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class RefundPolicy
{
    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::ADMIN, UserRole::FINANCE], true);
    }
}
