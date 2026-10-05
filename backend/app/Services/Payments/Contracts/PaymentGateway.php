<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\Data\GatewayNotification;
use App\Services\Payments\Data\PaymentInitResult;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Data\PaymentStatusQuery;
use App\Services\Payments\Exceptions\AmountOutOfRangeException;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Hợp đồng cổng thanh toán (ADR-001 §1). Nghiệp vụ (checkout, webhook, đối soát) chỉ phụ thuộc
 * interface này, không biết MoMo.
 *
 * Webhook và đối soát PHẢI resolve bằng `PaymentGatewayManager::driver($attempt->gateway)` (hoặc `{gateway}`
 * của route), KHÔNG inject interface này: binding mặc định chỉ trả cổng đầu tiên trong `enabled_gateways`
 * (dành cho checkout dùng cổng mặc định).
 */
interface PaymentGateway
{
    /** Mã cổng, trùng `payment_attempts.gateway` và phân đoạn `{gateway}` của route webhook. */
    public function code(): string;

    /**
     * Tạo giao dịch phía cổng. Không retry tự động.
     *
     * @throws GatewayUnavailableException timeout/lỗi mạng/resultCode != 0/pay_url sai host/cấu hình thiếu
     * @throws AmountOutOfRangeException số tiền ngoài hạn mức cổng
     */
    public function createPayment(PaymentRequest $request): PaymentInitResult;

    /**
     * Xác thực chữ ký + chuẩn hoá IPN. Chỉ đọc các trường khác sau khi chữ ký hợp lệ.
     * KHÔNG kiểm orderId/requestId có khớp attempt không — việc đó thuộc PaymentWebhookService (T19).
     *
     * @throws InvalidSignatureException chữ ký sai/thiếu, thiếu trường, partnerCode lạ, amount không hợp lệ
     */
    public function parseNotification(Request $request): GatewayNotification;

    /** Phản hồi HTTP cổng yêu cầu sau khi xử lý (MoMo: 204 khi accepted, 400 khi không). */
    public function acknowledge(bool $accepted): Response;

    /**
     * Tra cứu trạng thái chủ động server-to-server (đối soát — ADR-001 §7b). Phản hồi phải có chữ ký
     * hợp lệ, partnerCode/orderId/requestId khớp; không thì coi là lỗi (không hành động).
     *
     * @throws InvalidSignatureException
     * @throws GatewayUnavailableException
     */
    public function queryStatus(PaymentStatusQuery $query): GatewayNotification;
}
