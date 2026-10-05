<?php

namespace App\Services\Payments\Gateways\Momo;

use InvalidArgumentException;

/**
 * Ký/kiểm chữ ký MoMo API v2: `hex(HMAC_SHA256(secretKey, rawSignature))`, rawSignature là chuỗi
 * `key=value` nối bằng `&`, key theo thứ tự alphabet đúng danh sách từng API (ADR-001 §2).
 *
 * Class thuần (không đọc config/env) để unit test với vector mẫu. Danh sách trường nằm ở hằng số
 * để chỉnh một chỗ khi đối chiếu sandbox.
 */
final class MoMoSigner
{
    public const CREATE_FIELDS = ['accessKey', 'amount', 'extraData', 'ipnUrl', 'orderId', 'orderInfo', 'partnerCode', 'redirectUrl', 'requestId', 'requestType'];

    public const IPN_FIELDS = ['accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId'];

    public const QUERY_REQUEST_FIELDS = ['accessKey', 'orderId', 'partnerCode', 'requestId'];

    /**
     * CHƯA đối chiếu sandbox (ADR-001 §2 yêu cầu Dev kiểm): dùng cùng danh sách với IPN. Sai danh sách
     * → verify thất bại → fail-closed (coi như lỗi, không hành động), không bao giờ nhầm sang thành công.
     */
    public const QUERY_RESPONSE_FIELDS = self::IPN_FIELDS;

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields  danh sách key đã sắp alphabet
     */
    public function rawSignature(array $data, array $fields): string
    {
        $parts = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Thiếu trường ký: {$field}");
            }

            $value = $data[$field];

            if (is_bool($value) || (! is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException("Giá trị trường ký không hợp lệ: {$field}");
            }

            $parts[] = $field.'='.($value === null ? '' : (string) $value);
        }

        return implode('&', $parts);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     */
    public function sign(array $data, array $fields, string $secretKey): string
    {
        return hash_hmac('sha256', $this->rawSignature($data, $fields), $secretKey);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     */
    public function verify(array $data, array $fields, string $secretKey, mixed $signature): bool
    {
        if (! is_string($signature) || $signature === '' || $secretKey === '') {
            return false;
        }

        try {
            $expected = $this->sign($data, $fields, $secretKey);
        } catch (InvalidArgumentException) {
            return false;
        }

        return hash_equals($expected, strtolower($signature));
    }
}
