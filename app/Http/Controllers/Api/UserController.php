<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Support\ApiResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        $actor = request()->user();
        $query = User::query()->latest();

        if ($actor->hasRole(UserRole::MANAGER)) {
            $query->where(function ($builder) use ($actor): void {
                $builder
                    ->whereIn('role', [UserRole::FINANCE->value, UserRole::USER->value])
                    ->orWhere('id', $actor->id);
            });
        }

        $users = $query->paginate(request('per_page', 15));

        return ApiResponse::success([
            'users' => ApiResponse::paginated($users, UserResource::class),
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $actor = $request->user();
        $data = $request->validated();
        $role = UserRole::from($data['role']);

        if (! $actor->canAssignRole($role)) {
            return ApiResponse::error('forbidden', 'You cannot assign this role.', [], 403);
        }

        $user = User::query()->create($data);

        return ApiResponse::success(['user' => UserResource::make($user)], 201);
    }

    public function show(User $user)
    {
        if (! $this->canInspectUser(request()->user(), $user)) {
            return ApiResponse::error('forbidden', 'You do not have permission to inspect this user.', [], 403);
        }

        return ApiResponse::success(['user' => UserResource::make($user)]);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $actor = $request->user();
        if (! $actor->canManageUser($user)) {
            return ApiResponse::error('forbidden', 'You do not have permission to update this user.', [], 403);
        }

        $data = $request->validated();
        if (array_key_exists('role', $data) && ! $actor->canAssignRole(UserRole::from($data['role']))) {
            return ApiResponse::error('forbidden', 'You cannot assign this role.', [], 403);
        }

        $user->update($data);

        return ApiResponse::success(['user' => UserResource::make($user->fresh())]);
    }

    public function destroy(Request $request, User $user)
    {
        $actor = $request->user();
        if ($actor->is($user)) {
            return ApiResponse::error('invalid_operation', 'You cannot delete your own account.', [], 422);
        }

        if (! $actor->canManageUser($user)) {
            return ApiResponse::error('forbidden', 'You do not have permission to delete this user.', [], 403);
        }

        $user->delete();

        return response()->noContent();
    }

    private function canInspectUser(User $actor, User $target): bool
    {
        return $actor->hasRole(UserRole::ADMIN) || $actor->canManageUser($target) || $actor->is($target);
    }
}
