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
     * Danh sách trường của IPN (S12.4/L2 — review bảo mật T17): CHỈ những
     * trường này được giữ lại trong `GatewayNotification::$raw` (bỏ
     * `signature` — đã có ở `$required` khi kiểm đủ trường, nhưng không phải
     * dữ liệu nghiệp vụ nên không liệt kê ở đây). Chặn kịch bản nhồi thêm
     * khoá lạ/tham số query string vào bản ghi audit (`payment_webhook_events`)
     * dù chữ ký của các trường đã biết vẫn hợp lệ.
     *
     * @var list<string>
     */
    private const IPN_KNOWN_FIELDS = [
        'orderId', 'requestId', 'amount', 'orderInfo', 'orderType',
        'partnerCode', 'payType', 'responseTime', 'resultCode',
        'transId', 'message', 'extraData',
    ];

    /**
     * Cùng ý nghĩa với {@see self::IPN_KNOWN_FIELDS} nhưng cho phản hồi
     * `query` (S12.4/L2).
     *
     * @var list<string>
     */
    private const QUERY_RESPONSE_KNOWN_FIELDS = [
        'amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType',
        'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId',
    ];

    /**
     * @param  array{partner_code?: ?string, access_key?: ?string, secret_key?: ?string, endpoint?: ?string, request_type?: ?string, pay_url_hosts?: list<string>}  $config
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

        // L1 (review bảo mật T17) — kiểm scalar TRƯỚC khi ép (string), tránh
        // `ErrorException: Array to string conversion` nếu MoMo (hoặc 1 host
        // giả) trả về mảng/object thay vì chuỗi cho các trường này.
        foreach (['partnerCode', 'orderId', 'requestId', 'resultCode', 'payUrl'] as $field) {
            if (array_key_exists($field, $body) && ! is_string($body[$field]) && ! is_int($body[$field])) {
                Log::channel('payments')->warning('momo.create.invalid_field_type', [
                    'request_id' => $request->requestId,
                    'field' => $field,
                ]);

                throw new GatewayUnavailableException('Phản hồi tạo giao dịch MoMo không hợp lệ.');
            }
        }

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

        // M1 (review bảo mật T17) — không chỉ kiểm tiền tố `https://`: pin
        // đúng HOST của `payUrl` theo allowlist cấu hình (mặc định 2 host
        // thật của MoMo). Không có allowlist này, một phản hồi hợp lệ về mặt
        // `partnerCode`/`orderId`/`requestId` (3 giá trị này client tự gửi
        // lên, host nhận redirect/MITM tầng hạ tầng biết sẵn) nhưng có
        // `payUrl` trỏ tới domain lừa đảo vẫn được chấp nhận và đưa người
        // dùng thật tới đó.
        if ($payUrl === '' || ! $this->isTrustedPayUrl($payUrl)) {
            Log::channel('payments')->warning('momo.create.untrusted_pay_url', [
                'request_id' => $request->requestId,
            ]);

            throw new GatewayUnavailableException('Phản hồi tạo giao dịch MoMo có payUrl không hợp lệ.');
        }

        return new PaymentInitResult(
            payUrl: $payUrl,
            expiresAt: $request->expiresAt,
            rawResponse: $this->withoutSecrets($body),
        );
    }

    public function parseNotification(Request $request): GatewayNotification
    {
        // L2 (review bảo mật T17) — CHỈ đọc body JSON (`$request->json()`),
        // KHÔNG dùng `$request->all()`: hàm đó luôn gộp thêm query string
        // (`$this->query->all()`) vào kết quả BẤT KỂ Content-Type, nên nếu
        // dùng để dựng `GatewayNotification::$raw` sẽ vô tình lưu cả tham số
        // trên URL (không nằm trong chữ ký) như thể là dữ liệu đã xác thực.
        // IPN thật của MoMo là JSON POST.
        $payload = $request->json()->all();

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

        // L1 (review bảo mật T17) — kiểm scalar TRƯỚC KHI verify chữ ký/ép
        // (string): payload JSON có thể chứa mảng/object cho bất kỳ trường
        // nào (vd `"message": ["a"]`), ép `(string)` trên giá trị đó gây
        // `ErrorException: Array to string conversion` (500) thay vì 400, và
        // dữ liệu chưa xác thực không cần thiết phải làm crash tiến trình.
        // `extraData` không nằm trong `$required` (có mặc định `''`) nên
        // kiểm riêng khi có mặt.
        foreach ([...$required, 'extraData'] as $field) {
            if (array_key_exists($field, $payload) && ! is_string($payload[$field]) && ! is_int($payload[$field])) {
                Log::channel('payments')->warning('momo.ipn.invalid_field_type', ['field' => $field]);

                throw new InvalidSignatureException('Trường IPN không hợp lệ.');
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
            // L2 (review bảo mật T17) — CHỈ giữ trường đã biết/đã ký
            // (`self::IPN_KNOWN_FIELDS`), KHÔNG dump nguyên `$payload`: chữ
            // ký chỉ phủ các trường trong danh sách này, nên bên gửi có thể
            // nhồi thêm khoá lạ (hoặc query string bị `$request->all()` gộp
            // vào) mà chữ ký vẫn hợp lệ — nếu lưu nguyên `$payload` vào
            // `payment_webhook_events.payload` (T19), dữ liệu chưa xác thực
            // đó sẽ bị coi như đã tin cậy (S12.4).
            raw: array_intersect_key($payload, array_flip(self::IPN_KNOWN_FIELDS)),
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

        // L1 (review bảo mật T17) — kiểm scalar TRƯỚC KHI ép (string), cùng
        // lý do với `parseNotification()`.
        foreach ([...self::QUERY_RESPONSE_KNOWN_FIELDS, 'signature'] as $field) {
            if (array_key_exists($field, $body) && ! is_string($body[$field]) && ! is_int($body[$field])) {
                Log::channel('payments')->warning('momo.query.invalid_field_type', [
                    'gateway_order_id' => $attempt->gatewayOrderId,
                    'field' => $field,
                ]);

                throw new InvalidSignatureException('Trường phản hồi query không hợp lệ.');
            }
        }

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
            || (string) ($body['requestId'] ?? '') !== $requestId
        ) {
            Log::channel('payments')->warning('momo.query.mismatch', [
                'gateway_order_id' => $attempt->gatewayOrderId,
            ]);

            throw new InvalidSignatureException('partnerCode/orderId/requestId không khớp.');
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
            // L2 (review bảo mật T17) — xem giải thích ở `parseNotification()`.
            raw: array_intersect_key($body, array_flip(self::QUERY_RESPONSE_KNOWN_FIELDS)),
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

        // M1 (review bảo mật T17) — `client()` đã tắt theo redirect tự động
        // (`withoutRedirecting()`), nhưng vẫn kiểm tường minh ở đây: Guzzle
        // trả thẳng response 3xx thay vì theo redirect. Bất kỳ 3xx nào từ
        // cổng thanh toán đều bị coi là lỗi (không có API MoMo v2 nào hợp lệ
        // trả 3xx) — không bao giờ cho phép ứng dụng tự ý theo redirect sang
        // một host khác khi đang gọi cổng thanh toán.
        if ($response->redirect()) {
            Log::channel('payments')->warning('momo.'.$operation.'.unexpected_redirect', [
                'request_id' => $requestId,
                'status' => $response->status(),
            ]);

            throw new GatewayUnavailableException('Phản hồi cổng thanh toán là redirect không hợp lệ.');
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
     * `withoutRedirecting()` (M1, review bảo mật T17) — không bao giờ tự
     * theo 3xx: mặc định Guzzle theo tối đa 5 redirect (kể cả sang `http://`
     * hay host khác) và với 307/308 còn gửi lại NGUYÊN body POST (có
     * `accessKey`, `partnerCode`, `signature`) tới host đó.
     */
    private function client(string $endpoint): PendingRequest
    {
        return Http::baseUrl($endpoint)
            ->withoutRedirecting()
            ->timeout(10)
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson();
    }

    /**
     * M1 (review bảo mật T17) — `payUrl` chỉ được chấp nhận khi `https://`
     * VÀ host nằm đúng trong allowlist cấu hình (`payments.gateways.momo.pay_url_hosts`,
     * mặc định 2 host thật của MoMo). Không dùng `str_contains`/tiền tố —
     * so khớp CHÍNH XÁC host sau khi `parse_url()` để không lọt các biến thể
     * kiểu `payment.momo.vn.evil.com` hay `evil.com#payment.momo.vn`.
     */
    private function isTrustedPayUrl(string $payUrl): bool
    {
        $scheme = parse_url($payUrl, PHP_URL_SCHEME);
        $host = parse_url($payUrl, PHP_URL_HOST);

        if ($scheme !== 'https' || $host === null || $host === '') {
            return false;
        }

        /** @var list<string> $allowedHosts */
        $allowedHosts = (array) ($this->config['pay_url_hosts'] ?? []);

        return in_array($host, $allowedHosts, true);
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
