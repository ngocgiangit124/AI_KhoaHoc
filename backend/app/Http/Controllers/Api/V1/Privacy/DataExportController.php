<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\DataExportRequest;
use App\Models\User;
use App\Services\Privacy\DataExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Xuất dữ liệu cá nhân của chính học sinh (api-contract §2.8.4). Không nhận id nào: chỉ `$request->user()`.
 */
class DataExportController extends Controller
{
    public function status(Request $request, DataExportService $exports): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($exports->status($user));
    }

    public function store(DataExportRequest $request, DataExportService $exports): Response
    {
        /** @var User $user */
        $user = $request->user();

        $file = $exports->export($user, (string) $request->validated('current_password'));

        return response($file['json'], 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
