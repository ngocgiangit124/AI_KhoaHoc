<?php

namespace App\Services\Auth;

use InvalidArgumentException;
use Stringable;

/**
 * Số điện thoại di động Việt Nam — chuẩn hoá về dạng `0xxxxxxxxx` (10 số,
 * data-model §3.1 `users.phone`) trước khi lưu/tra cứu, chấp nhận đầu vào
 * `+84`/`84` hoặc đã ở dạng `0xxxxxxxxx` (US-001 BR1).
 *
 * Chỉ chấp nhận đầu số di động theo quy hoạch hiện hành (03, 05, 07, 08, 09)
 * — không chấp nhận số cố định (khác phạm vi US-001, story chỉ nói "SĐT" cho
 * đăng ký/đăng nhập học sinh).
 */
final class PhoneNumber implements Stringable
{
    private function __construct(private readonly string $value) {}

    /**
     * @throws InvalidArgumentException Khi số điện thoại không đúng định dạng.
     */
    public static function fromInput(string $raw): self
    {
        $normalized = self::normalize($raw);

        if (! self::matchesPattern($normalized)) {
            throw new InvalidArgumentException("Số điện thoại không hợp lệ: {$raw}");
        }

        return new self($normalized);
    }

    public static function isValidInput(string $raw): bool
    {
        return self::matchesPattern(self::normalize($raw));
    }

    private static function normalize(string $raw): string
    {
        $digits = preg_replace('/[^\d+]/', '', trim($raw)) ?? '';

        if (str_starts_with($digits, '+84')) {
            return '0'.substr($digits, 3);
        }

        if (str_starts_with($digits, '84') && strlen($digits) === 11) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }

    private static function matchesPattern(string $value): bool
    {
        return (bool) preg_match('/^0(3|5|7|8|9)\d{8}$/', $value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
