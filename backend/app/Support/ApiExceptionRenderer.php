<?php

namespace App\Support;

use App\Exceptions\DomainException;
use App\Exceptions\OtpValidationException;
use App\Services\Auth\StudentSessionService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Envelope lỗi thống nhất cho mọi request `api/*` (api-contract §1.7):
 * `{ message, code, errors?, request_id? }`.
 */
class ApiExceptionRenderer
{
    public static function shouldHandle(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        $requestId = $request->attributes->get('request_id');

        [$status, $code, $message, $errors] = self::resolve($e, $request);

        if ($status >= 500 && ! app()->hasDebugModeEnabled()) {
            // Không bao giờ lộ chi tiết/stack trace ở production (S22).
            $message = 'Đã có lỗi xảy ra. Vui lòng thử lại sau.';
        }

        $payload = array_filter([
            'message' => $message,
            'code' => $code,
            'errors' => $errors,
            'request_id' => $requestId,
        ], static fn ($value) => $value !== null);

        $response = response()->json($payload, $status);

        if ($requestId) {
            $response->headers->set('X-Request-Id', $requestId);
        }

        // L6 — giữ lại header chức năng của HttpException (`Retry-After` của 429,
        // `Allow` của 405...) thay vì bỏ khi dựng lại response JSON từ đầu.
        if ($e instanceof HttpExceptionInterface) {
            foreach ($e->getHeaders() as $name => $value) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>|null}
     */
    private static function resolve(Throwable $e, Request $request): array
    {
        if ($e instanceof DomainException) {
            return [$e->status(), $e->code(), $e->getMessage(), $e->context() ?: null];
        }

        if ($e instanceof ValidationException) {
            return [422, $e instanceof OtpValidationException ? $e->errorCode : 'VALIDATION_ERROR', 'Dữ liệu gửi lên không hợp lệ.', $e->errors()];
        }

        if ($e instanceof AuthenticationException) {
            return self::resolveUnauthenticated($request);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return [$status, self::codeForStatus($status), self::messageForStatus($status, $e), null];
        }

        return [500, 'INTERNAL_ERROR', 'Đã có lỗi xảy ra. Vui lòng thử lại sau.', null];
    }

    /**
     * ADR-003: phiên học sinh đã bị xoá khỏi store thì request cũ không còn đăng nhập; lấy lý do từ
     * tombstone theo session id trong cookie ĐÃ GIẢI MÃ (EncryptCookies đã chạy, StartSession chưa cấp id mới
     * vào cookie của request). Chỉ đọc, không xoá tombstone (hết hạn theo TTL).
     *
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>|null}
     */
    private static function resolveUnauthenticated(Request $request): array
    {
        $cookie = $request->cookies->get((string) config('session.cookie'));
        $tombstone = is_string($cookie) && $cookie !== '' && strlen($cookie) <= 255
            ? StudentSessionService::tombstone($cookie)
            : null;

        if ($tombstone !== null) {
            switch ($tombstone['reason']) {
                case StudentSessionService::REASON_REPLACED:
                    $device = StudentSessionService::deviceIdFromRequest($request);

                    if ($device !== null && $device === ($tombstone['new_device_id'] ?? null)) {
                        return [401, 'SESSION_EXPIRED', 'Phiên đăng nhập đã hết hiệu lực, vui lòng đăng nhập lại.', null];
                    }

                    return [401, 'SESSION_REPLACED', 'Tài khoản của bạn đã đăng nhập ở thiết bị khác. Nếu không phải bạn, hãy đổi mật khẩu ngay.', null];
                case StudentSessionService::REASON_PASSWORD_CHANGED:
                    return [401, 'SESSION_REVOKED', 'Mật khẩu đã được thay đổi, vui lòng đăng nhập lại.', null];
                case StudentSessionService::REASON_LOCKED:
                    return [403, 'ACCOUNT_LOCKED', 'Tài khoản của bạn đã bị khoá.', null];
            }
        }

        return [401, 'UNAUTHENTICATED', 'Vui lòng đăng nhập để tiếp tục.', null];
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            413 => 'PAYLOAD_TOO_LARGE',
            419 => 'CSRF_TOKEN_MISMATCH',
            429 => 'TOO_MANY_ATTEMPTS',
            default => $status >= 500 ? 'INTERNAL_ERROR' : 'HTTP_ERROR',
        };
    }

    /**
     * Luôn dùng thông điệp cố định tiếng Việt — không lộ message gốc của Symfony/Laravel
     * (có thể chứa tên route/class nội bộ).
     */
    private static function messageForStatus(int $status, HttpExceptionInterface $e): string
    {
        return match ($status) {
            403 => 'Bạn không có quyền thực hiện thao tác này.',
            404 => 'Không tìm thấy tài nguyên.',
            405 => 'Phương thức không được hỗ trợ.',
            413 => 'Dữ liệu gửi lên quá lớn.',
            419 => 'Phiên làm việc đã hết hạn, vui lòng thử lại.',
            429 => 'Bạn thao tác quá nhanh, vui lòng thử lại sau.',
            default => 'Đã có lỗi xảy ra. Vui lòng thử lại sau.',
        };
    }
}
