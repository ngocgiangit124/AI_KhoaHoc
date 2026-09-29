<?php

namespace App\Support;

use App\Exceptions\DomainException;
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

        [$status, $code, $message, $errors] = self::resolve($e);

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

        // T04 security review L3 — `DomainException` không phải
        // `HttpExceptionInterface` (không đi qua middleware `throttle:`), nên
        // 429 `TOO_MANY_ATTEMPTS` ném từ tầng Service (`OtpService`) trước đây
        // KHÔNG có `Retry-After` dù cùng mã lỗi với 429 của `throttle:`. Copy
        // header tường minh do exception tự khai (không lẫn vào `errors` của
        // body — xem `DomainException::headers()`).
        if ($e instanceof DomainException) {
            foreach ($e->headers() as $name => $value) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>|null}
     */
    private static function resolve(Throwable $e): array
    {
        if ($e instanceof DomainException) {
            return [$e->status(), $e->code(), $e->getMessage(), $e->context() ?: null];
        }

        if ($e instanceof ValidationException) {
            return [422, 'VALIDATION_ERROR', self::validationMessage($e), $e->errors()];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'UNAUTHENTICATED', 'Vui lòng đăng nhập để tiếp tục.', null];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return [$status, self::codeForStatus($status), self::messageForStatus($status, $e), null];
        }

        return [500, 'INTERNAL_ERROR', 'Đã có lỗi xảy ra. Vui lòng thử lại sau.', null];
    }

    /**
     * R1 (review docs/qa/review-T03-FW1.md) — frontend hiển thị `message`
     * top-level làm banner (vd. `LoginForm.tsx`). Trước đây mọi
     * `ValidationException` đều bị hard-code cùng 1 câu chung, kể cả khi
     * `LoginService::genericFailure()` chủ đích gán thông điệp nghiệp vụ có
     * ý nghĩa (BR5/S20 — "Thông tin đăng nhập hoặc mật khẩu không đúng.").
     *
     * Chỉ nâng thông điệp field lên top-level khi lỗi CHỈ có đúng 1 field và
     * field đó CHỈ có đúng 1 message — an toàn vì:
     * - Thông điệp validate luôn do chính ứng dụng soạn (Form Request/Rule),
     *   không bao giờ là chi tiết kỹ thuật/nội bộ (khác exception 500).
     * - Lỗi nhiều field (form đăng ký điền thiếu nhiều ô...) vẫn giữ câu
     *   chung — không tự ý chọn "lỗi đầu tiên" đại diện cho cả nhóm lỗi.
     */
    private static function validationMessage(ValidationException $e): string
    {
        $errors = $e->errors();

        if (count($errors) === 1) {
            $onlyFieldMessages = reset($errors);

            if (is_array($onlyFieldMessages) && count($onlyFieldMessages) === 1) {
                return (string) $onlyFieldMessages[0];
            }
        }

        return 'Dữ liệu gửi lên không hợp lệ.';
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
