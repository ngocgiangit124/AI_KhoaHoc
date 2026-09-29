<?php

namespace App\Console\Commands\Payments;

use App\Services\Payments\Data\PaymentAttemptReference;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Exceptions\GatewayUnavailableException;
use App\Services\Payments\Exceptions\InvalidSignatureException;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Kiểm chứng thủ công adapter MoMo với sandbox THẬT (T17, ADR-001 §2 "Việc
 * Dev phải kiểm chứng ở T17 với tài liệu/sandbox MoMo hiện hành").
 *
 * CHƯA CHẠY ĐƯỢC trong môi trường viết code này: không có credential sandbox
 * và mạng ra `test-payment.momo.vn` bị chặn theo chính sách của môi trường
 * agent. PO/Dev chạy lệnh này SAU KHI có credential sandbox hợp lệ, trên máy
 * có thể ra Internet (Docker local) — KHÔNG BAO GIỜ chạy ở production (lệnh
 * tự chặn nếu `app()->isProduction()`).
 *
 * Cách chạy (xem thêm báo cáo T17):
 * 1. Trong `.env` (KHÔNG commit): `PAYMENT_GATEWAYS=momo,fake`,
 *    `MOMO_ENDPOINT=https://test-payment.momo.vn`, `MOMO_PARTNER_CODE`,
 *    `MOMO_ACCESS_KEY`, `MOMO_SECRET_KEY` = credential sandbox MoMo cấp.
 * 2. `docker compose exec php php artisan payments:momo:verify-sandbox`.
 * 3. Đối chiếu output với tài liệu MoMo hiện hành:
 *    - Danh sách trường ký `create`/`query` (request + response) có đổi
 *      không so với `MoMoGateway`/ADR-001 §2.
 *    - `resultCode` trả về cho giao dịch "chưa thanh toán" (kỳ vọng theo
 *      ADR-001: 1000/7000/7002/9000 = Pending) — cập nhật
 *      `MoMoResultCode::PENDING_CODES` nếu tài liệu/sandbox hiện hành khác.
 *    - Hạn mức số tiền tối thiểu/tối đa áp dụng cho tài khoản sandbox.
 *    - Mã kết quả cho "giao dịch không tồn tại/đã hết hạn" khi query một
 *      `orderId` không có thật (chạy lại lệnh với `--order-id` tuỳ ý).
 *    Ghi lại kết quả vào `docs/board.md` (mục T17) — CHƯA kiểm chứng tại
 *    thời điểm bàn giao T17.
 */
class VerifyMomoSandboxCommand extends Command
{
    protected $signature = 'payments:momo:verify-sandbox
        {--amount=10000 : Số tiền VNĐ dùng để tạo giao dịch thử}
        {--order-id= : orderId tuỳ chọn (mặc định tự sinh SANDBOX-VERIFY-<timestamp>)}';

    protected $description = 'Gọi thử sandbox MoMo thật (createPayment + queryStatus) để kiểm chứng adapter T17 — không chạy ở production.';

    public function handle(PaymentGatewayManager $gateways): int
    {
        if (app()->isProduction()) {
            $this->components->error('Lệnh này chỉ để kiểm chứng sandbox — không được chạy ở production.');

            return self::FAILURE;
        }

        if (! in_array('momo', (array) config('payments.enabled_gateways', []), true)) {
            $this->components->error('Thêm "momo" vào PAYMENT_GATEWAYS trong .env trước khi chạy lệnh này.');

            return self::FAILURE;
        }

        $momoConfig = (array) config('payments.gateways.momo', []);
        $missing = array_values(array_filter(
            ['partner_code', 'access_key', 'secret_key', 'endpoint'],
            fn (string $key) => blank($momoConfig[$key] ?? null)
        ));

        if ($missing !== []) {
            $this->components->error('Thiếu cấu hình MOMO_'.strtoupper(implode(', MOMO_', $missing)).' trong .env.');

            return self::FAILURE;
        }

        // L3 (review bảo mật T17) — chặn theo HOST của endpoint, KHÔNG phụ
        // thuộc `APP_ENV`: lệnh có thể chạy trên máy dev có `.env` chép từ
        // production (endpoint + credential thật), lúc đó `isProduction()`
        // vẫn là `false` (APP_ENV=local) nhưng vẫn tạo được giao dịch THẬT
        // trên MoMo production.
        $endpointHost = parse_url((string) $momoConfig['endpoint'], PHP_URL_HOST);

        if ($endpointHost !== 'test-payment.momo.vn') {
            $this->components->error('MOMO_ENDPOINT phải là host sandbox "test-payment.momo.vn" khi chạy lệnh này — không được trỏ vào production.');

            return self::FAILURE;
        }

        $amountOption = (string) $this->option('amount');

        if (! ctype_digit($amountOption) || (int) $amountOption < 1) {
            $this->components->error('--amount phải là số nguyên dương.');

            return self::FAILURE;
        }

        $orderId = (string) ($this->option('order-id') ?: 'SANDBOX-VERIFY-'.now()->format('YmdHis'));
        $requestId = (string) Str::uuid();
        $amount = (int) $amountOption;

        $this->components->info("Tạo giao dịch thử sandbox: orderId={$orderId}, amount={$amount}");

        try {
            $result = $gateways->driver('momo')->createPayment(new PaymentRequest(
                gatewayOrderId: $orderId,
                requestId: $requestId,
                amount: $amount,
                description: "Kiem chung sandbox {$orderId}",
                returnUrl: (string) config('payments.gateways.momo.redirect_url', 'https://vitaminvui.test/checkout/ket-qua'),
                notifyUrl: (string) config('payments.gateways.momo.ipn_url', 'https://api.vitaminvui.test/webhooks/payments/momo'),
            ));
        } catch (GatewayUnavailableException $e) {
            $this->components->error('createPayment thất bại: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('createPayment OK — payUrl: '.$result->payUrl);
        $this->line('rawResponse (đã bỏ signature): '.json_encode($result->rawResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->components->info('Gọi queryStatus ngay sau khi tạo (kỳ vọng theo ADR-001: Pending, chưa thanh toán)...');

        try {
            $status = $gateways->driver('momo')->queryStatus(new PaymentAttemptReference(
                gatewayOrderId: $orderId,
                requestId: $requestId,
                amount: $amount,
            ));
        } catch (InvalidSignatureException|GatewayUnavailableException $e) {
            $this->components->error('queryStatus thất bại: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'queryStatus OK — status=%s resultCode=%s message=%s',
            $status->status->value,
            $status->resultCode,
            $status->message,
        ));

        $this->newLine();
        $this->components->warn('CHƯA kiểm chứng tự động: đối chiếu thủ công danh sách trường ký, bảng mã resultCode, hạn mức số tiền với tài liệu MoMo hiện hành (ADR-001 §2), rồi ghi kết quả vào docs/board.md.');

        return self::SUCCESS;
    }
}
