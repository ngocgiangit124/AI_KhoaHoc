<?php

namespace App\Services\Payments\Gateways\Fake;

use App\Enums\GatewayPaymentStatus;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\GatewayNotification;
use App\Services\Payments\Data\PaymentInitResult;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Data\PaymentStatusQuery;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Cổng giả cho local/testing (S4). CHỈ được đăng ký ở local/testing (AppServiceProvider) và bị boot
 * guard cấm ở production. Giả lập tạo link, IPN (ký HMAC bằng `payments.gateways.fake.secret`) và query.
 *
 * Kịch bản test/dev: `FakeGateway::ipnPayload(...)` dựng IPN đã ký; `FakeGateway::fakeQueryResult(...)`
 * đặt kết quả cho lần `queryStatus` kế tiếp (mặc định Pending).
 */
class FakeGateway implements PaymentGateway
{
    public const SIGNED_FIELDS = ['amount', 'gatewayOrderId', 'message', 'requestId', 'resultCode', 'transId'];

    public function code(): string
    {
        return 'fake';
    }

    public function createPayment(PaymentRequest $request): PaymentInitResult
    {
        $base = rtrim((string) config('payments.gateways.fake.pay_url_base'), '/');

        return new PaymentInitResult(
            payUrl: $base.'/'.rawurlencode($request->gatewayOrderId),
            expiresAt: $request->expiresAt,
            rawResponse: ['orderId' => $request->gatewayOrderId, 'requestId' => $request->requestId, 'resultCode' => 0],
        );
    }

    public function parseNotification(Request $request): GatewayNotification
    {
        return $this->normalize($request->json()->all());
    }

    public function acknowledge(bool $accepted): Response
    {
        return new Response('', $accepted ? 204 : 400);
    }

    public function queryStatus(PaymentStatusQuery $query): GatewayNotification
    {
        $stored = Cache::get($this->cacheKey($query->gatewayOrderId));

        if (! is_array($stored)) {
            return new GatewayNotification($query->gatewayOrderId, '', '0', $query->amount, GatewayPaymentStatus::Pending, 7000, 'pending', []);
        }

        return $this->normalize(self::ipnPayload(
            $query->gatewayOrderId,
            (string) $stored['requestId'],
            (int) $stored['amount'],
            (int) $stored['resultCode'],
            (string) $stored['transId'],
        ));
    }

    /** Đặt kết quả cho lần query kế tiếp của `$gatewayOrderId` (chỉ dùng test/dev). */
    public static function fakeQueryResult(string $gatewayOrderId, string $requestId, int $amount, int $resultCode, string $transId = 'FAKE-1'): void
    {
        Cache::put(self::cacheKey($gatewayOrderId), compact('requestId', 'amount', 'resultCode', 'transId'), 3600);
    }

    /**
     * Dựng payload IPN đã ký (chữ ký đúng theo secret hiện hành).
     *
     * @return array<string, mixed>
     */
    public static function ipnPayload(string $gatewayOrderId, string $requestId, int $amount, int $resultCode = 0, string $transId = 'FAKE-1', string $message = 'ok'): array
    {
        $data = [
            'amount' => (string) $amount,
            'gatewayOrderId' => $gatewayOrderId,
            'message' => $message,
            'requestId' => $requestId,
            'resultCode' => $resultCode,
            'transId' => $transId,
        ];
        $data['signature'] = self::sign($data);

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private static function sign(array $data): string
    {
        $parts = [];
        foreach (self::SIGNED_FIELDS as $field) {
            $parts[] = $field.'='.(string) ($data[$field] ?? '');
        }

        return hash_hmac('sha256', implode('&', $parts), (string) config('payments.gateways.fake.secret'));
    }

    /** @param  array<mixed>  $payload */
    private function normalize(array $payload): GatewayNotification
    {
        foreach (self::SIGNED_FIELDS as $field) {
            if (! isset($payload[$field]) || ! is_scalar($payload[$field])) {
                throw new InvalidSignatureException('missing_field_'.$field);
            }
        }

        $signature = $payload['signature'] ?? null;
        if (! is_string($signature) || ! hash_equals(self::sign($payload), strtolower($signature))) {
            throw new InvalidSignatureException('bad_signature');
        }

        if (! ctype_digit((string) $payload['amount']) || ! preg_match('/^\d+$/', (string) $payload['resultCode'])) {
            throw new InvalidSignatureException('invalid_field');
        }

        $resultCode = (int) $payload['resultCode'];
        $status = match ($resultCode) {
            0 => GatewayPaymentStatus::Succeeded,
            7000 => GatewayPaymentStatus::Pending,
            default => GatewayPaymentStatus::Failed,
        };

        unset($payload['signature']);

        return new GatewayNotification(
            gatewayOrderId: (string) $payload['gatewayOrderId'],
            requestId: (string) $payload['requestId'],
            transactionId: (string) $payload['transId'],
            amount: (int) $payload['amount'],
            status: $status,
            resultCode: $resultCode,
            message: (string) $payload['message'],
            raw: array_intersect_key($payload, array_flip(self::SIGNED_FIELDS)),
        );
    }

    private static function cacheKey(string $gatewayOrderId): string
    {
        return 'fake-gateway.query.'.$gatewayOrderId;
    }
}
