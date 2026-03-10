<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    public function canAssignRole(UserRole $role): bool
    {
        return match ($this->role) {
            UserRole::ADMIN => true,
            UserRole::MANAGER => in_array($role, [UserRole::FINANCE, UserRole::USER], true),
            default => false,
        };
    }

    public function canManageUser(self $target): bool
    {
        return match ($this->role) {
            UserRole::ADMIN => true,
            UserRole::MANAGER => in_array($target->role, [UserRole::FINANCE, UserRole::USER], true),
            default => false,
        };
    }

    public function scopeVisibleTo(Builder $query, self $actor): void
    {
        if (! $actor->hasRole(UserRole::MANAGER)) {
            return;
        }

        $query->where(function (Builder $builder) use ($actor): void {
            $builder
                ->whereIn('role', [UserRole::FINANCE->value, UserRole::USER->value])
                ->orWhere('id', $actor->id);
        });
    }
}
