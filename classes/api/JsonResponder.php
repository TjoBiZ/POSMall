<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use October\Rain\Exception\ValidationException;
use Throwable;

class JsonResponder
{
    public function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $data,
        ], $status);
    }

    public function error(string $code, string $message, int $status = 400, array $meta = []): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'meta' => $meta ?: null,
            ]),
        ], $status);
    }

    public function exception(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return $this->error('validation_failed', $exception->getMessage(), 422);
        }

        if ($exception instanceof ModelNotFoundException) {
            return $this->error('not_found', 'The requested POSMall resource was not found.', 404);
        }

        if ($exception instanceof AuthorizationException) {
            return $this->error('forbidden', $exception->getMessage() ?: 'The POSMall API token is not allowed to access this resource.', 403);
        }

        if (app()->hasDebugModeEnabled()) {
            return $this->error('server_error', $exception->getMessage(), 500);
        }

        return $this->error('server_error', 'The request could not be processed.', 500);
    }
}
