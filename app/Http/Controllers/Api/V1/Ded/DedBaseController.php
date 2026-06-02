<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Http\Controllers\Controller;
use App\Services\Api\DedApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class DedBaseController extends Controller
{
    public function __construct(protected DedApiService $ded) {}

    protected function admin(Request $request)
    {
        return $this->ded->admin($request);
    }

    protected function success($data = [], string $message = 'Success.', array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data, 'meta' => (object) $meta], $status);
    }

    protected function error(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => (object) $errors], $status);
    }
}
