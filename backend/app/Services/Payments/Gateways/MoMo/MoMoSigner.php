<?php

namespace App\Services\Payments\Gateways\MoMo;

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
     * @param  array<string, string>  $fields
     */
    public function sign(array $fields, string $secretKey): string
    {
        return hash_hmac('sha256', $this->buildRawSignature($fields), $secretKey);
    }

    /**
     * @param  array<string, string>  $fields
     */
    public function verify(array $fields, string $secretKey, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals($this->sign($fields, $secretKey), $signature);
    }
}
