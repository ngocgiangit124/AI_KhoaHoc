<?php

namespace App\Services\Payments\Gateways\MoMo;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\GatewayNotification;
use App\Services\Payments\Data\PaymentAttemptReference;
use App\Services\Payments\Data\PaymentInitResult;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use App\Services\Payments\Support\StrictAmountParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Adapter MoMo API v2 (`/v2/gateway/api/create`, `/v2/gateway/api/query`) —
 * ADR-001 §2. Danh sách trường ký lấy từ ADR-001 (đã đối chiếu với tài liệu
 * công khai của MoMo); DANH SÁCH TRƯỜNG KÝ CHO QUERY RESPONSE và các mã
 * `resultCode` "đang xử lý"/"không tồn tại" CẦN Dev xác nhận lại với tài
 * liệu MoMo hiện hành / sandbox thật (mạng test-payment.momo.vn bị chặn ở
 * môi trường này — xem báo cáo T17).
 */
final class MoMoGateway implements PaymentGateway
{
    /**
     * @param  array{partner_code?: ?string, access_key?: ?string, secret_key?: ?string, endpoint?: ?string, request_type?: ?string}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly MoMoSigner $signer,
    ) {}

    public function code(): string
    {
        return 'momo';
    }

    public function createPayment(PaymentRequest $request): PaymentInitResult
    {
        $requestType = (string) ($this->config['request_type'] ?? 'captureWallet');

        $signedFields = [
            'accessKey' => (string) $this->config['access_key'],
            'amount' => (string) $request->amount,
            'extraData' => '',
            'ipnUrl' => $request->notifyUrl,
            'orderId' => $request->gatewayOrderId,
            'orderInfo' => $request->description,
            'partnerCode' => (string) $this->config['partner_code'],
            'redirectUrl' => $request->returnUrl,
            'requestId' => $request->requestId,
            'requestType' => $requestType,
        ];

        $signature = $this->signer->sign($signedFields, (string) $this->config['secret_key']);

        $payload = [
            'partnerCode' => $this->config['partner_code'],
            'partnerName' => 'VitaminVui',
            'storeId' => $this->config['partner_code'],
            'requestId' => $request->requestId,
            'amount' => $request->amount,
            'orderId' => $request->gatewayOrderId,
            'orderInfo' => $request->description,
            'redirectUrl' => $request->returnUrl,
            'ipnUrl' => $request->notifyUrl,
            'lang' => 'vi',
            'requestType' => $requestType,
            'autoCapture' => true,
            'extraData' => '',
            'signature' => $signature,
        ];

        $body = $this->post('/v2/gateway/api/create', $payload, 'create', $request->requestId);

        if (
            (string) ($body['partnerCode'] ?? '') !== (string) $this->config['partner_code']
            || (string) ($body['orderId'] ?? '') !== $request->gatewayOrderId
            || (string) ($body['requestId'] ?? '') !== $request->requestId
        ) {
            Log::channel('payments')->warning('momo.create.mismatch', [
                'request_id' => $request->requestId,
                'gateway_order_id' => $request->gatewayOrderId,
            ]);

            throw new GatewayUnavailableException('Phản hồi tạo giao dịch MoMo không hợp lệ.');
        }

        $resultCode = (string) ($body['resultCode'] ?? '');

        if ($resultCode !== '0') {
            Log::channel('payments')->info('momo.create.rejected', [
                'request_id' => $request->requestId,
                'result_code' => $resultCode,
            ]);

            throw new GatewayUnavailableException('MoMo từ chối tạo giao dịch.');
        }

        $payUrl = (string) ($body['payUrl'] ?? '');

        if ($payUrl === '' || ! str_starts_with($payUrl, 'https://')) {
            throw new GatewayUnavailableException('Phản hồi tạo giao dịch MoMo thiếu payUrl hợp lệ.');
        }

        return new PaymentInitResult(
            payUrl: $payUrl,
            expiresAt: $request->expiresAt,
            rawResponse: $this->withoutSecrets($body),
        );
    }

    public function parseNotification(Request $request): GatewayNotification
    {
        $payload = $request->all();

        $required = [
            'orderId', 'requestId', 'amount', 'orderInfo', 'orderType',
            'partnerCode', 'payType', 'responseTime', 'resultCode',
            'transId', 'message', 'signature',
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)) {
                Log::channel('payments')->warning('momo.ipn.missing_field', ['field' => $field]);

                throw new InvalidSignatureException('Thiếu trường bắt buộc trong IPN.');
            }
        }

        $signedFields = [
            'accessKey' => (string) $this->config['access_key'],
            'amount' => (string) $payload['amount'],
            'extraData' => (string) ($payload['extraData'] ?? ''),
            'message' => (string) $payload['message'],
            'orderId' => (string) $payload['orderId'],
            'orderInfo' => (string) $payload['orderInfo'],
            'orderType' => (string) $payload['orderType'],
            'partnerCode' => (string) $payload['partnerCode'],
            'payType' => (string) $payload['payType'],
            'requestId' => (string) $payload['requestId'],
            'responseTime' => (string) $payload['responseTime'],
            'resultCode' => (string) $payload['resultCode'],
            'transId' => (string) $payload['transId'],
        ];

        if (! $this->signer->verify($signedFields, (string) $this->config['secret_key'], (string) $payload['signature'])) {
            Log::channel('payments')->warning('momo.ipn.rejected_signature', [
                'order_id' => (string) $payload['orderId'],
            ]);

            throw new InvalidSignatureException;
        }

        // Chỉ đọc các trường "nghiệp vụ" SAU khi chữ ký đã hợp lệ (S12.2).
        if ((string) $payload['partnerCode'] !== (string) $this->config['partner_code']) {
            Log::channel('payments')->warning('momo.ipn.partner_mismatch', [
                'order_id' => (string) $payload['orderId'],
            ]);

            throw new InvalidSignatureException('partnerCode không khớp cấu hình.');
        }

        $amount = StrictAmountParser::parse($payload['amount']);

        if ($amount === null) {
            Log::channel('payments')->warning('momo.ipn.invalid_amount', [
                'order_id' => (string) $payload['orderId'],
            ]);

            throw new InvalidSignatureException('Số tiền không hợp lệ.');
        }

        return new GatewayNotification(
            gatewayOrderId: (string) $payload['orderId'],
            requestId: (string) $payload['requestId'],
            transactionId: (string) $payload['transId'],
            amount: $amount,
            status: MoMoResultCode::toStatus((string) $payload['resultCode']),
            resultCode: (string) $payload['resultCode'],
            message: (string) $payload['message'],
            raw: $this->withoutSecrets($payload),
        );
    }

    public function acknowledge(bool $accepted): Response
    {
        // MoMo chỉ cần biết là đã nhận (204) — không có thân phản hồi khác
        // biệt cho "chấp nhận/từ chối" theo tài liệu hiện hành (ADR-001 §1).
        unset($accepted);

        return new Response(status: 204);
    }

    public function queryStatus(PaymentAttemptReference $attempt): GatewayNotification
    {
        $requestId = (string) Str::uuid();

        $signedFields = [
            'accessKey' => (string) $this->config['access_key'],
            'orderId' => $attempt->gatewayOrderId,
            'partnerCode' => (string) $this->config['partner_code'],
            'requestId' => $requestId,
        ];

        $signature = $this->signer->sign($signedFields, (string) $this->config['secret_key']);

        $payload = [
            'partnerCode' => $this->config['partner_code'],
            'requestId' => $requestId,
            'orderId' => $attempt->gatewayOrderId,
            'lang' => 'vi',
            'signature' => $signature,
        ];

        $body = $this->post('/v2/gateway/api/query', $payload, 'query', $requestId);

        $responseSignedFields = [
            'accessKey' => (string) $this->config['access_key'],
            'amount' => (string) ($body['amount'] ?? ''),
            'extraData' => (string) ($body['extraData'] ?? ''),
            'message' => (string) ($body['message'] ?? ''),
            'orderId' => (string) ($body['orderId'] ?? ''),
            'orderInfo' => (string) ($body['orderInfo'] ?? ''),
            'orderType' => (string) ($body['orderType'] ?? ''),
            'partnerCode' => (string) ($body['partnerCode'] ?? ''),
            'payType' => (string) ($body['payType'] ?? ''),
            'requestId' => (string) ($body['requestId'] ?? ''),
            'responseTime' => (string) ($body['responseTime'] ?? ''),
            'resultCode' => (string) ($body['resultCode'] ?? ''),
            'transId' => (string) ($body['transId'] ?? ''),
        ];

        if (! $this->signer->verify($responseSignedFields, (string) $this->config['secret_key'], (string) ($body['signature'] ?? ''))) {
            Log::channel('payments')->warning('momo.query.rejected_signature', [
                'gateway_order_id' => $attempt->gatewayOrderId,
            ]);

            throw new InvalidSignatureException;
        }

        if (
            (string) ($body['partnerCode'] ?? '') !== (string) $this->config['partner_code']
            || (string) ($body['orderId'] ?? '') !== $attempt->gatewayOrderId
        ) {
            Log::channel('payments')->warning('momo.query.mismatch', [
                'gateway_order_id' => $attempt->gatewayOrderId,
            ]);

            throw new InvalidSignatureException('partnerCode/orderId không khớp.');
        }

        $amount = StrictAmountParser::parse($body['amount'] ?? null);

        if ($amount === null) {
            throw new InvalidSignatureException('Số tiền không hợp lệ.');
        }

        return new GatewayNotification(
            gatewayOrderId: (string) $body['orderId'],
            requestId: (string) ($body['requestId'] ?? $requestId),
            transactionId: isset($body['transId']) ? (string) $body['transId'] : null,
            amount: $amount,
            status: MoMoResultCode::toStatus((string) ($body['resultCode'] ?? '')),
            resultCode: (string) ($body['resultCode'] ?? ''),
            message: (string) ($body['message'] ?? ''),
            raw: $this->withoutSecrets($body),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, string $operation, string $requestId): array
    {
        $endpoint = (string) $this->config['endpoint'];

        if (! str_starts_with($endpoint, 'https://')) {
            // Không bao giờ gọi cổng thanh toán qua kênh không mã hoá.
            throw new GatewayUnavailableException('Endpoint MoMo cấu hình sai (không phải https).');
        }

        try {
            $response = $this->client($endpoint)->post($path, $payload);
        } catch (ConnectionException $e) {
            Log::channel('payments')->warning('momo.'.$operation.'.network_error', [
                'request_id' => $requestId,
            ]);

            throw new GatewayUnavailableException(previous: $e);
        }

        if ($response->serverError() || $response->status() >= 500) {
            Log::channel('payments')->warning('momo.'.$operation.'.server_error', [
                'request_id' => $requestId,
                'status' => $response->status(),
            ]);

            throw new GatewayUnavailableException;
        }

        $body = $response->json();

        if (! is_array($body)) {
            Log::channel('payments')->warning('momo.'.$operation.'.invalid_response', [
                'request_id' => $requestId,
                'status' => $response->status(),
            ]);

            throw new GatewayUnavailableException('Phản hồi MoMo không phải JSON hợp lệ.');
        }

        Log::channel('payments')->info('momo.'.$operation.'.response', [
            'request_id' => $requestId,
            'result_code' => $body['resultCode'] ?? null,
        ]);

        return $body;
    }

    /**
     * `Http::timeout(10)->connectTimeout(5)`, không retry tự động (ADR-001 §2 —
     * tránh tạo 2 giao dịch); luôn verify TLS (không `withoutVerifying()`).
     */
    private function client(string $endpoint): PendingRequest
    {
        return Http::baseUrl($endpoint)
            ->timeout(10)
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $data): array
    {
        unset($data['signature']);

        return $data;
    }
}
