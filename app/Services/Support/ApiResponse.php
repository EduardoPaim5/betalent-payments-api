<?php

namespace App\Services\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponse
{
    public static function success(mixed $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'request_id' => self::requestId(),
        ], $status);
    }

    public static function error(string $code, string $message, array $details = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'request_id' => self::requestId(),
        ], $status);
    }

    public static function paginated(LengthAwarePaginator $paginator, string $resourceClass): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'data' => $resourceClass::collection($paginator->getCollection())->resolve(),
            'first_page_url' => $paginator->url(1),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'last_page_url' => $paginator->url($paginator->lastPage()),
            'links' => $paginator->linkCollection()->toArray(),
            'next_page_url' => $paginator->nextPageUrl(),
            'path' => $paginator->path(),
            'per_page' => $paginator->perPage(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }

    private static function requestId(): ?string
    {
        return request()->attributes->get('request_id') ?: request()->header('X-Request-ID');
    }
}
