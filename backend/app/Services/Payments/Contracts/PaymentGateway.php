<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\Data\GatewayNotification;
use App\Services\Payments\Data\PaymentAttemptReference;
use App\Services\Payments\Data\PaymentInitResult;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Hợp đồng cổng thanh toán (ADR-001 §1). Nghiệp vụ (CheckoutService,
 * PaymentWebhookService — T18/T19) chỉ phụ thuộc interface này, không biết
 * chi tiết MoMo/cổng khác. Thêm cổng mới = thêm 1 class implement + config.
 */
interface PaymentGateway
{
    /**
     * Mã định danh cổng dùng trong `payments.enabled_gateways`, route
     * webhook `{gateway}` và cột `payment_attempts.gateway` (vd `'momo'`).
     */
    public function code(): string;

    /**
     * Tạo giao dịch phía cổng.
     *
     * @throws GatewayUnavailableException Timeout/lỗi mạng/5xx/`resultCode != 0`/phản hồi không khớp.
     */
    public function createPayment(PaymentRequest $request): PaymentInitResult;

    /**
     * Xác thực chữ ký + chuẩn hoá IPN thành `GatewayNotification`.
     *
     * @throws InvalidSignatureException Chữ ký sai/thiếu trường bắt buộc.
     */
    public function parseNotification(Request $request): GatewayNotification;

    /**
     * Phản hồi HTTP cổng yêu cầu sau khi xử lý IPN (MoMo: 204 No Content).
     */
    public function acknowledge(bool $accepted): Response;

    /**
     * Tra cứu trạng thái chủ động server-to-server (đối soát — ADR-001 §7b).
     * Ký request bằng config; verify chữ ký + `partnerCode`/`orderId` của
     * phản hồi trước khi tin bất kỳ trường nào khác.
     *
     * @throws InvalidSignatureException Phản hồi không có/sai chữ ký, hoặc `partnerCode`/`orderId` không khớp — coi như lỗi, không hành động.
     * @throws GatewayUnavailableException Timeout/lỗi mạng/5xx.
     */
    public function queryStatus(PaymentAttemptReference $attempt): GatewayNotification;
}
