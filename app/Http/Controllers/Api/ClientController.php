<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\IndexClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\Support\ApiResponse;

class ClientController extends Controller
{
    public function index(IndexClientRequest $request)
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::query()
            ->when($request->validated('email'), function ($query, $email): void {
                $query->where('email', 'like', '%'.$email.'%');
            })
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(['clients' => ApiResponse::paginated($clients, ClientResource::class)]);
    }

    public function show(Client $client)
    {
        $this->authorize('view', $client);

        $client->load(['transactions.gateway', 'transactions.products']);

        return ApiResponse::success(['client' => ClientResource::make($client)]);
    }
}
