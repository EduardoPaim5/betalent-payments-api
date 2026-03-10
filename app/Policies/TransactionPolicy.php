<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::ADMIN, UserRole::MANAGER, UserRole::FINANCE], true);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $this->viewAny($user);
    }
}
