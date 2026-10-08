<?php

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\ParentNoticeUnsubscribeRequest;
use App\Services\Privacy\ParentNoticeService;
use Illuminate\Http\JsonResponse;

/**
 * Công khai (không session/cookie/CSRF như webhook). LUÔN trả cùng một body để không lộ token/tài khoản có hợp lệ hay không.
 */
class ParentNoticeUnsubscribeController extends Controller
{
    public const MESSAGE = 'Đã ghi nhận. Bạn sẽ không nhận thêm thông báo từ VitaminVui về học sinh này.';

    public function __invoke(ParentNoticeUnsubscribeRequest $request, ParentNoticeService $service): JsonResponse
    {
        $service->optOut((string) $request->validated('token'));

        return response()->json(['message' => self::MESSAGE]);
    }
}
