<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\IndexUserRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index(IndexUserRequest $request)
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();
        $users = User::query()
            ->visibleTo($actor)
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'users' => ApiResponse::paginated($users, UserResource::class),
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $this->authorize('create', User::class);

        $data = $request->validated();
        $role = UserRole::from($data['role']);
        Gate::authorize('assignRole', [User::class, $role]);

        $user = User::query()->create($data);

        return ApiResponse::success(['user' => UserResource::make($user)], 201);
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        return ApiResponse::success(['user' => UserResource::make($user)]);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        if (array_key_exists('role', $data)) {
            Gate::authorize('assignRole', [User::class, UserRole::from($data['role'])]);
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

        $this->authorize('delete', $user);

        $user->delete();

        return response()->noContent();
    }
}
