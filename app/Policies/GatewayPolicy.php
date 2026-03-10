<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Gateway;
use App\Models\User;

class GatewayPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::ADMIN, UserRole::MANAGER, UserRole::FINANCE], true);
    }

    public function manageSettings(User $user, Gateway $gateway): bool
    {
        return $user->hasRole(UserRole::ADMIN);
    }
}
