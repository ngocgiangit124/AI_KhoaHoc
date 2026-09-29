<?php

namespace App\Services\Payments\Gateways\MoMo;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * HMAC-SHA256 theo quy tắc MoMo (ADR-001 §2): `rawSignature` là chuỗi
 * `key=value` nối bằng `&`, **key sắp theo alphabet**. Danh sách trường cụ
 * thể cho mỗi API (create/IPN/query) do lớp gọi (`MoMoGateway`) truyền vào —
 * lớp này chỉ lo đúng thuật toán nối chuỗi + ký/so sánh.
 */
final class MoMoSigner
{
    /**
     * @param  array<string, string>  $fields
     */
    public function buildRawSignature(array $fields): string
    {
        ksort($fields, SORT_STRING);

        $parts = [];

        foreach ($fields as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return implode('&', $parts);
    }

    /**
     * M2 (review bảo mật T17) — từ chối ký bằng khoá rỗng: `hash_hmac` với
     * `secretKey = ''` vẫn ra một chữ ký hợp lệ mà AI CŨNG TÍNH ĐƯỢC (không
     * cần biết bí mật thật), nên lỗi cấu hình (thiếu `MOMO_SECRET_KEY`) phải
     * làm việc ký THẤT BẠI RÕ RÀNG thay vì âm thầm tạo ra chữ ký "hợp lệ".
     *
     * @param  array<string, string>  $fields
     *
     * @throws InvalidArgumentException `$secretKey` rỗng.
     */
    public function sign(array $fields, #[SensitiveParameter] string $secretKey): string
    {
        if ($secretKey === '') {
            throw new InvalidArgumentException('MoMoSigner::sign(): secretKey không được rỗng.');
        }

        return hash_hmac('sha256', $this->buildRawSignature($fields), $secretKey);
    }

    /**
     * M2 (review bảo mật T17) — khoá rỗng luôn trả `false` (không ném lỗi,
     * không gọi `sign()`): verify là đường xử lý dữ liệu KHÔNG ĐÁNG TIN từ
     * bên ngoài (IPN/phản hồi query), phải luôn "từ chối" khi cấu hình sai,
     * không bao giờ để lộ đường "ai cũng ký được" qua ngoại lệ hay giá trị
     * hợp lệ giả.
     *
     * @param  array<string, string>  $fields
     */
    public function verify(array $fields, #[SensitiveParameter] string $secretKey, string $signature): bool
    {
        if ($signature === '' || $secretKey === '') {
            return false;
        }

        return hash_equals($this->sign($fields, $secretKey), $signature);
    }
}
