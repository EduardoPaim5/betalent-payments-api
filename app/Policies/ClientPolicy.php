<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::ADMIN, UserRole::MANAGER, UserRole::FINANCE], true);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->viewAny($user);
    }
}
