<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Support\ApiResponse;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        if (! Auth::attempt($request->validated())) {
            return ApiResponse::error('invalid_credentials', 'Invalid credentials.', [], 401);
        }

        $user = $request->user();
        $token = $user->createToken('api-token')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => UserResource::make($user),
        ]);
    }
}
