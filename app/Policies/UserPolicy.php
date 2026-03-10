<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::ADMIN, UserRole::MANAGER], true);
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasRole(UserRole::ADMIN) || $user->canManageUser($target) || $user->is($target);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $target): bool
    {
        return $user->canManageUser($target);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->canManageUser($target);
    }

    public function assignRole(User $user, UserRole $role): bool
    {
        return $user->canAssignRole($role);
    }
}
