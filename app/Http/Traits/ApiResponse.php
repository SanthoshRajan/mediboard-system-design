<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\MessageBag;

trait ApiResponse
{
    /** 200 fetch / read succeeded. */
    protected function success(mixed $data = null, ?array $meta = null): JsonResponse
    {
        $payload = ['result' => 'success', 'data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        return response()->json($payload, 200);
    }

    /** 201 resource created. Pass at minimum ['id' => $newId]. */
    protected function created(mixed $data = null): JsonResponse
    {
        return response()->json(['result' => 'success', 'data' => $data], 201);
    }

    /**
     * 200 write succeeded (UPDATE / soft-delete / PATCH).
     * Avoids leaking raw $affected row counts to clients.
     */
    protected function updated(mixed $data = null, string $message = 'Updated successfully.'): JsonResponse
    {
        return response()->json([
            'result'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], 200);
    }

    // ─── Errors ───────────────────────────────────────────────────────────────

    /**
     * 422 validation failed.
     * Replaces: new stdClass + $output->status = 'error' + response()->json($output) [was HTTP 200]
     */
    protected function validationError(
        MessageBag|array $errors,
        string $code = 'validation_error'
    ): JsonResponse {
        return response()->json([
            'result'  => 'error',
            'code'    => $code,
            'message' => 'Validation failed.',
            'errors'  => $errors instanceof MessageBag ? $errors->all() : $errors,
        ], 422);
    }

    /**
     * 401 unauthenticated.
     * For credential failures use config('mediboard.auth.fail_response') directly.
     */
    protected function unauthorized(
        string $message = 'Unauthorized.',
        string $code    = 'unauthorized'
    ): JsonResponse {
        return response()->json([
            'result'  => 'error',
            'code'    => $code,
            'message' => $message,
            'errors'  => null,
        ], 401);
    }

    /**
     * 403 authenticated but not permitted.
     * Covers: password_expiry, account_blocked, wrong-facility access.
     */
    protected function forbidden(
        string $message = 'Forbidden.',
        string $code    = 'forbidden'
    ): JsonResponse {
        return response()->json([
            'result'  => 'error',
            'code'    => $code,
            'message' => $message,
            'errors'  => null,
        ], 403);
    }

    /** 404 record not found or soft-deleted. */
    protected function notFound(
        string $message = 'Resource not found.',
        string $code    = 'not_found'
    ): JsonResponse {
        return response()->json([
            'result'  => 'error',
            'code'    => $code,
            'message' => $message,
            'errors'  => null,
        ], 404);
    }

    /**
     * 500 unhandled server error.
     * Log $e->getMessage() before calling this. Never pass the raw exception as $message.
     */
    protected function serverError(
        string $message = 'An unexpected error occurred.',
        string $code    = 'server_error'
    ): JsonResponse {
        return response()->json([
            'result'  => 'error',
            'code'    => $code,
            'message' => $message,
            'errors'  => null,
        ], 500);
    }

    /**
     * 503 upstream dependency down (Redis, Python PDF script exit code 3).
     */
    protected function serviceUnavailable(
        string $message = 'Service temporarily unavailable.',
        string $code    = 'service_unavailable'
    ): JsonResponse {
        return response()->json([
            'result'  => 'error',
            'code'    => $code,
            'message' => $message,
            'errors'  => null,
        ], 503);
    }

    /** Escape hatch for one-off status codes (409 Conflict, 429, etc.). */
    protected function errorResponse(
        string $message,
        string $code,
        int    $status,
        ?array $errors = null
    ): JsonResponse {
        return response()->json([
            'result'  => 'error',
            'code'    => $code,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }
}
