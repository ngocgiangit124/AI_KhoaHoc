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
    /**
     * T04 security review (Info, vòng 2) — allowlist header được phép copy từ
     * `DomainException::headers()` (xem `render()`).
     *
     * @var list<string>
     */
    private const ALLOWED_DOMAIN_EXCEPTION_HEADERS = ['Retry-After'];

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

        // T04 security review L3 — `DomainException` không phải
        // `HttpExceptionInterface` (không đi qua middleware `throttle:`), nên
        // 429 `TOO_MANY_ATTEMPTS` ném từ tầng Service (`OtpService`) trước đây
        // KHÔNG có `Retry-After` dù cùng mã lỗi với 429 của `throttle:`. Copy
        // header tường minh do exception tự khai (không lẫn vào `errors` của
        // body — xem `DomainException::headers()`).
        //
        // T04 security review (Info, vòng 2) — chỉ copy header nằm trong
        // ALLOWLIST cố định, không copy nguyên `headers()` dù hiện tại không
        // có đường nào đưa dữ liệu request vào tên/giá trị header (chỉ
        // `OtpService::tooManySendException()` gọi, giá trị luôn là số
        // nguyên). Phòng xa: sau này thêm `DomainException` khác có gọi
        // `headers()` với dữ liệu không kiểm soát cũng không tự động lọt qua
        // renderer chung này.
        if ($e instanceof DomainException) {
            foreach ($e->headers() as $name => $value) {
                if (in_array($name, self::ALLOWED_DOMAIN_EXCEPTION_HEADERS, true)) {
                    $response->headers->set($name, $value);
                }
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
            return [422, 'VALIDATION_ERROR', self::validationMessage($e), $e->errors()];
        }

        if ($e instanceof AuthenticationException) {
            return self::resolveAuthenticationException($request);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return [$status, self::codeForStatus($status), self::messageForStatus($status, $e), null];
        }

        return [500, 'INTERNAL_ERROR', 'Đã có lỗi xảy ra. Vui lòng thử lại sau.', null];
    }

    /**
     * T05 (ADR-003) — `auth:sanctum` ném `AuthenticationException` khi phiên
     * cũ đã bị `StudentSessionService` xoá khỏi store (trường hợp thường gặp
     * SAU khi thiết bị khác đăng nhập). Tra tombstone bằng session id lấy
     * TRỰC TIẾP TỪ COOKIE ĐÃ GIẢI MÃ (`$request->cookies`, do
     * `App\Http\Middleware\EncryptCookies` đã decrypt TRƯỚC ĐÓ trong cùng
     * pipeline) — KHÔNG dùng `$request->session()->getId()`: với driver
     * "array"/StartSession, một session id không tồn tại trong store vẫn có
     * thể được GIỮ NGUYÊN thay vì cấp id mới (chỉ đổi khi sai ĐỊNH DẠNG), nên
     * 2 cách đọc thường ra cùng giá trị — nhưng ADR-003 chốt rõ dùng cookie
     * thô để không phụ thuộc hành vi nội bộ đó của `Store`.
     *
     * Không có tombstone (phiên chưa từng bị thay/thu hồi — vd chưa đăng nhập
     * bao giờ, hoặc TTL tombstone đã hết) → `UNAUTHENTICATED` mặc định.
     *
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>|null}
     */
    private static function resolveAuthenticationException(Request $request): array
    {
        $unauthenticated = [401, 'UNAUTHENTICATED', 'Vui lòng đăng nhập để tiếp tục.', null];

        $sessionId = $request->cookies->get((string) config('session.cookie'));

        if (! is_string($sessionId) || $sessionId === '') {
            return $unauthenticated;
        }

        $tombstone = SessionTombstoneStore::get($sessionId);

        if ($tombstone === null) {
            return $unauthenticated;
        }

        return match ($tombstone['reason']) {
            'replaced' => self::resolveReplacedTombstone($request, $tombstone),
            'password_changed' => [401, 'SESSION_REVOKED', 'Mật khẩu đã được thay đổi, vui lòng đăng nhập lại.', null],
            'locked' => [403, 'ACCOUNT_LOCKED', 'Tài khoản của bạn đã bị khoá.', null],
            default => $unauthenticated,
        };
    }

    /**
     * @param  array{reason: string, new_device_id: string|null, at: string}  $tombstone
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>|null}
     */
    private static function resolveReplacedTombstone(Request $request, array $tombstone): array
    {
        $deviceId = DeviceId::normalize($request->header('X-Device-Id'));

        if ($deviceId !== null && $deviceId === $tombstone['new_device_id']) {
            // Chính thiết bị này vừa đăng nhập lại (bấm 2 lần/tải lại) — KHÔNG
            // báo nhầm "thiết bị khác" (ADR-003).
            return [401, 'SESSION_EXPIRED', 'Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại.', null];
        }

        return [401, 'SESSION_REPLACED', 'Tài khoản của bạn đã đăng nhập ở thiết bị khác. Nếu không phải bạn, hãy đổi mật khẩu ngay.', null];
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
