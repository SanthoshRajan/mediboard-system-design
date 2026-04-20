<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

abstract class ApiController extends Controller
{
    use ApiResponse;

    /**
     * @param  array   $rules     Laravel validation rules.
     * @param  array   $messages  Custom error messages (optional).
     * @param  string  $code      Error code sent to client on failure.
     * @return array|JsonResponse Validated data array on pass; 422 response on fail.
     */
    protected function validateRequest(
        array $request,
        array   $rules,
        array   $messages = [],
        string  $code     = 'validation_error'
    ): array|JsonResponse {
        $v = Validator::make($request, $rules, $messages);

        if ($v->fails()) {
            return $this->validationError($v->errors(), $code);
        }

        return $v->validated();
    }
}
