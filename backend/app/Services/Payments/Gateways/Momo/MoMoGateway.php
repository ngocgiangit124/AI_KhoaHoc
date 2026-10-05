<?php

namespace App\Services\Payments\Gateways\Momo;

use App\Enums\GatewayPaymentStatus;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\GatewayNotification;
use App\Services\Payments\Data\PaymentInitResult;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Data\PaymentStatusQuery;
use App\Services\Payments\Exceptions\AmountOutOfRangeException;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adapter MoMo API v2 (ADR-001 §2). Mọi cấu hình đọc từ `config('payments.gateways.momo')`.
 * Không log `signature`/secret/PII; luôn verify TLS; không retry khi tạo giao dịch.
 */
class MoMoGateway implements PaymentGateway
{
    /** Chỉ `0` là thành công. */
    public const RESULT_SUCCESS = 0;

    /**
     * Chưa kết luận — không bao giờ Succeeded và không đóng đơn (ADR-001 §2). 9000/1000/7000/7002 theo ADR;
     * 8000 (chờ người dùng xác nhận) và 10/11/99 (lỗi hệ thống/tạm thời) CHƯA đối chiếu tài liệu/sandbox
     * MoMo (backlog T17-3) nên xếp Pending cho an toàn.
     */
    public const PENDING_CODES = [9000, 1000, 7000, 7002, 8000, 10, 11, 99];

    /** Link/QR hết hạn hoặc giao dịch không tồn tại (CHƯA đối chiếu): Failed + `expired = true` để T20 đặt attempt `expired`. */
    public const EXPIRED_CODES = [1005, 42];

    /** Người dùng từ chối/huỷ/không đủ tiền... (CHƯA đối chiếu đầy đủ): kết luận Failed. */
    public const FAILED_CODES = [1001, 1002, 1003, 1004, 1006, 1007];

    public function __construct(private readonly MoMoSigner $signer = new MoMoSigner) {}

    public function code(): string
    {
        return 'momo';
    }

    /**
     * @param  string  $source  'ipn' | 'query'. Mã lạ: IPN → Failed (ADR-001 §2); query → Pending (không đóng
     *                          đơn khi chưa chắc, job đối soát sẽ hỏi lại).
     */
    public static function mapResultCode(int $code, string $source = 'ipn'): GatewayPaymentStatus
    {
        return match (true) {
            $code === self::RESULT_SUCCESS => GatewayPaymentStatus::Succeeded,
            in_array($code, self::PENDING_CODES, true) => GatewayPaymentStatus::Pending,
            in_array($code, self::EXPIRED_CODES, true), in_array($code, self::FAILED_CODES, true) => GatewayPaymentStatus::Failed,
            $source === 'query' => GatewayPaymentStatus::Pending,
            default => GatewayPaymentStatus::Failed,
        };
    }

    public static function isExpiredCode(int $code): bool
    {
        return in_array($code, self::EXPIRED_CODES, true);
    }

    public function createPayment(PaymentRequest $request): PaymentInitResult
    {
        $min = (int) $this->cfg('min_amount', 1000);
        $max = (int) $this->cfg('max_amount', 50000000);

        if ($request->amount < $min) {
            throw AmountOutOfRangeException::below($min);
        }
        if ($request->amount > $max) {
            throw AmountOutOfRangeException::above($max);
        }

        $url = $this->url('create');

        $data = [
            'accessKey' => $this->secretCfg('access_key'),
            'amount' => (string) $request->amount,
            'extraData' => '',
            'ipnUrl' => $request->notifyUrl,
            'orderId' => $request->gatewayOrderId,
            'orderInfo' => $request->description,
            'partnerCode' => $this->secretCfg('partner_code'),
            'redirectUrl' => $request->returnUrl,
            'requestId' => $request->requestId,
            'requestType' => (string) $this->cfg('request_type', 'captureWallet'),
        ];

        $signature = $this->signer->sign($data, MoMoSigner::CREATE_FIELDS, $this->secretCfg('secret_key'));

        // accessKey chỉ dùng để ký, không gửi trong body.
        $body = [
            'partnerCode' => $data['partnerCode'],
            'requestType' => $data['requestType'],
            'ipnUrl' => $data['ipnUrl'],
            'redirectUrl' => $data['redirectUrl'],
            'orderId' => $data['orderId'],
            'amount' => $request->amount,
            'lang' => (string) $this->cfg('lang', 'vi'),
            'orderInfo' => $data['orderInfo'],
            'requestId' => $data['requestId'],
            'extraData' => '',
            'signature' => $signature,
        ];

        $startedAt = microtime(true);
        $json = $this->post($url, $body, 'create');

        $resultCode = $this->intOrNull($json['resultCode'] ?? null);
        $this->log('momo.create', [
            'request_id' => $request->requestId,
            'gateway_order_id' => $request->gatewayOrderId,
            'result_code' => $resultCode,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        if ($resultCode !== self::RESULT_SUCCESS) {
            throw new GatewayUnavailableException('create_rejected_'.($resultCode ?? 'unknown'));
        }

        if (($json['orderId'] ?? null) !== $request->gatewayOrderId || ($json['requestId'] ?? null) !== $request->requestId) {
            throw new GatewayUnavailableException('create_response_mismatch');
        }

        $payUrl = $json['payUrl'] ?? null;
        if (! is_string($payUrl) || ! $this->isAllowedPayUrl($payUrl)) {
            throw new GatewayUnavailableException('invalid_pay_url');
        }

        unset($json['signature']);

        return new PaymentInitResult($payUrl, $request->expiresAt, $json);
    }

    public function parseNotification(Request $request): GatewayNotification
    {
        $payload = $request->json()->all();

        return $this->normalize($payload, MoMoSigner::IPN_FIELDS, 'ipn');
    }

    public function acknowledge(bool $accepted): Response
    {
        return new Response('', $accepted ? 204 : 400);
    }

    public function queryStatus(PaymentStatusQuery $query): GatewayNotification
    {
        $url = $this->url('query');
        $requestId = (string) Str::uuid();

        $data = [
            'accessKey' => $this->secretCfg('access_key'),
            'orderId' => $query->gatewayOrderId,
            'partnerCode' => $this->secretCfg('partner_code'),
            'requestId' => $requestId,
        ];

        $body = [
            'partnerCode' => $data['partnerCode'],
            'requestId' => $requestId,
            'orderId' => $query->gatewayOrderId,
            'lang' => (string) $this->cfg('lang', 'vi'),
            'signature' => $this->signer->sign($data, MoMoSigner::QUERY_REQUEST_FIELDS, $this->secretCfg('secret_key')),
        ];

        $startedAt = microtime(true);
        $json = $this->post($url, $body, 'query');

        $this->log('momo.query', [
            'request_id' => $requestId,
            'gateway_order_id' => $query->gatewayOrderId,
            'result_code' => $this->intOrNull($json['resultCode'] ?? null),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        $notification = $this->normalize($json, MoMoSigner::QUERY_RESPONSE_FIELDS, 'query');

        // Phản hồi phải đúng là của request vừa gửi (chống replay phản hồi của đơn khác).
        if ($notification->gatewayOrderId !== $query->gatewayOrderId || $notification->requestId !== $requestId) {
            $this->rejected('query', 'response_mismatch', $query->gatewayOrderId);
        }

        return $notification;
    }

    /**
     * Verify chữ ký TRƯỚC, rồi mới đọc các trường còn lại (S12).
     *
     * @param  array<mixed>  $payload
     * @param  list<string>  $fields  danh sách trường ký (có `accessKey` — luôn lấy từ config)
     */
    private function normalize(array $payload, array $fields, string $source): GatewayNotification
    {
        $orderIdHint = is_string($payload['orderId'] ?? null)
            ? substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $payload['orderId']), 0, 64)
            : null;

        $signed = ['accessKey' => $this->secretCfg('access_key')];
        foreach ($fields as $field) {
            if ($field === 'accessKey') {
                continue;
            }
            if (! array_key_exists($field, $payload)) {
                $this->rejected($source, 'missing_field_'.$field, $orderIdHint);
            }
            $signed[$field] = $payload[$field];
        }

        if (! $this->signer->verify($signed, $fields, $this->secretCfg('secret_key'), $payload['signature'] ?? null)) {
            $this->rejected($source, 'bad_signature', $orderIdHint);
        }

        // --- Từ đây chữ ký đã hợp lệ ---
        if (! hash_equals((string) $this->secretCfg('partner_code'), (string) $payload['partnerCode'])) {
            $this->rejected($source, 'partner_mismatch', $orderIdHint);
        }

        $amount = $this->strictAmount($payload['amount']);
        $resultCode = $this->intOrNull($payload['resultCode']);

        if ($amount === null || $resultCode === null
            || ! is_string($payload['orderId']) || $payload['orderId'] === ''
            || ! is_string($payload['requestId']) || $payload['requestId'] === ''
            || ! is_scalar($payload['transId']) || is_bool($payload['transId'])
            || ! is_string($payload['message'])) {
            $this->rejected($source, 'invalid_field', $orderIdHint);
        }

        unset($payload['signature']);

        return new GatewayNotification(
            gatewayOrderId: $payload['orderId'],
            requestId: $payload['requestId'],
            transactionId: (string) $payload['transId'],
            amount: $amount,
            status: self::mapResultCode($resultCode, $source),
            resultCode: $resultCode,
            message: Str::limit($payload['message'], 255, ''),
            raw: $this->knownFields($payload),
            expired: self::isExpiredCode($resultCode),
        );
    }

    /**
     * Chỉ giữ các trường đã biết (ADR-001 §2) — payload chưa xác thực không được đầu độc log.
     *
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     */
    private function knownFields(array $payload): array
    {
        $known = array_diff(MoMoSigner::IPN_FIELDS, ['accessKey']);

        return array_intersect_key($payload, array_flip($known));
    }

    /** amount: chuỗi chỉ gồm chữ số hoặc int JSON không âm; "100000.0", "1e5", "-1" → null. */
    private function strictAmount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && strlen($value) <= 12 && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d{1,9}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function isAllowedPayUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false; // ký tự điều khiển, khoảng trắng, backslash
        }

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('mb_strtolower', (array) $this->cfg('pay_url_hosts', []));

        return $host !== '' && in_array($host, $allowed, true);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $url, array $body, string $op): array
    {
        try {
            /** @var PendingRequest $client */
            $client = Http::acceptJson()
                ->asJson()
                ->timeout((int) $this->cfg('timeout', 10))
                ->connectTimeout((int) $this->cfg('connect_timeout', 5))
                ->withoutRedirecting();

            $response = $client->post($url, $body);
        } catch (ConnectionException $e) {
            $this->log('momo.'.$op.'.network_error', ['gateway_order_id' => $body['orderId'] ?? null]);
            throw new GatewayUnavailableException('network', $e);
        } catch (Throwable $e) {
            throw new GatewayUnavailableException('http_error', $e);
        }

        $json = $response->json();

        // MoMo có thể trả 4xx kèm JSON resultCode — vẫn để luồng trên xét resultCode; chỉ 5xx/không phải JSON mới là lỗi hạ tầng.
        if (! is_array($json) || $response->serverError()) {
            throw new GatewayUnavailableException('bad_response_'.$response->status());
        }

        return $json;
    }

    private function url(string $op): string
    {
        $endpoint = (string) $this->cfg('endpoint');
        $parts = parse_url($endpoint);

        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw new GatewayUnavailableException('misconfigured_endpoint');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$parts['host'].$port.(string) $this->cfg('paths.'.$op);
    }

    private function secretCfg(string $key): string
    {
        $value = (string) $this->cfg($key, '');

        if ($value === '') {
            throw new GatewayUnavailableException('misconfigured_'.$key);
        }

        return $value;
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        return config('payments.gateways.momo.'.$key, $default);
    }

    /**
     * @return never
     */
    private function rejected(string $source, string $reason, ?string $gatewayOrderId): void
    {
        $this->log('rejected_signature', ['source' => $source, 'reason' => $reason, 'gateway_order_id' => $gatewayOrderId]);

        throw new InvalidSignatureException($reason);
    }

    /** @param  array<string, mixed>  $context */
    private function log(string $message, array $context): void
    {
        Log::channel('payments')->info($message, $context);
    }
}
