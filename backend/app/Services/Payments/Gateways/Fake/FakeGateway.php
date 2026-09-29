<?php

namespace App\Services\Payments\Gateways\Fake;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\GatewayNotification;
use App\Services\Payments\Data\PaymentAttemptReference;
use App\Services\Payments\Data\PaymentInitResult;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Enums\PaymentStatus;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use App\Services\Payments\Support\StrictAmountParser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Cổng giả lập cho test/local (ADR-001 §1, S4). CHỈ được `PaymentServiceProvider`
 * đăng ký khi `app()->environment('local', 'testing')` — không bao giờ boot ở
 * production (kiểm bởi `App\Support\PaymentsProductionGuard` + test riêng).
 *
 * Chữ ký giả dùng khoá cố định `fake-gateway-secret` — không phải bí mật thật,
 * chỉ để bài test có thể tự dựng payload "ký hợp lệ"/"ký sai".
 */
final class FakeGateway implements PaymentGateway
{
    private const SECRET = 'fake-gateway-secret';

    /**
     * Cùng ý nghĩa với `MoMoGateway::IPN_KNOWN_FIELDS` (L2, review bảo mật T17).
     *
     * @var list<string>
     */
    private const NOTIFICATION_KNOWN_FIELDS = ['orderId', 'requestId', 'amount', 'status', 'transId'];

    public function code(): string
    {
        return 'fake';
    }

    public function createPayment(PaymentRequest $request): PaymentInitResult
    {
        return new PaymentInitResult(
            payUrl: sprintf('https://fake-gateway.test/pay/%s', $request->gatewayOrderId),
            expiresAt: $request->expiresAt,
            rawResponse: [
                'fake' => true,
                'orderId' => $request->gatewayOrderId,
                'requestId' => $request->requestId,
            ],
        );
    }

    public function parseNotification(Request $request): GatewayNotification
    {
        // L2 (review bảo mật T17) — chỉ đọc body JSON, giống `MoMoGateway`.
        $payload = $request->json()->all();

        $required = ['orderId', 'requestId', 'amount', 'status', 'signature'];

        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidSignatureException;
            }
        }

        // L1 (review bảo mật T17) — kiểm scalar trước khi tin/ép kiểu.
        foreach ($required as $field) {
            if (! is_string($payload[$field]) && ! is_int($payload[$field])) {
                throw new InvalidSignatureException('Trường IPN không hợp lệ.');
            }
        }

        if (! hash_equals($this->sign($payload), (string) $payload['signature'])) {
            throw new InvalidSignatureException;
        }

        $amount = StrictAmountParser::parse($payload['amount']);

        if ($amount === null) {
            throw new InvalidSignatureException('Số tiền không hợp lệ.');
        }

        $status = $payload['status'] === 'succeeded' ? PaymentStatus::Succeeded
            : ($payload['status'] === 'pending' ? PaymentStatus::Pending : PaymentStatus::Failed);

        return new GatewayNotification(
            gatewayOrderId: (string) $payload['orderId'],
            requestId: (string) $payload['requestId'],
            transactionId: isset($payload['transId']) ? (string) $payload['transId'] : (string) Str::uuid(),
            amount: $amount,
            status: $status,
            resultCode: $status === PaymentStatus::Succeeded ? '0' : '1',
            message: 'fake',
            // L2 — chỉ giữ trường đã biết (xem `MoMoGateway::IPN_KNOWN_FIELDS`).
            raw: array_intersect_key($payload, array_flip(self::NOTIFICATION_KNOWN_FIELDS)),
        );
    }

    public function acknowledge(bool $accepted): Response
    {
        unset($accepted);

        return new Response(status: 204);
    }

    public function queryStatus(PaymentAttemptReference $attempt): GatewayNotification
    {
        return new GatewayNotification(
            gatewayOrderId: $attempt->gatewayOrderId,
            requestId: $attempt->requestId,
            transactionId: null,
            amount: $attempt->amount,
            status: PaymentStatus::Pending,
            resultCode: '9000',
            message: 'fake-pending',
            raw: [],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        return hash_hmac('sha256', ($payload['orderId'] ?? '').'|'.($payload['amount'] ?? ''), self::SECRET);
    }
}
