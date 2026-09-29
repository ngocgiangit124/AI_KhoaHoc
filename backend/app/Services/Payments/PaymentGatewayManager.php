<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Gateways\Fake\FakeGateway;
use App\Services\Payments\Gateways\MoMo\MoMoGateway;
use App\Services\Payments\Gateways\MoMo\MoMoSigner;
use Illuminate\Support\Manager;
use RuntimeException;

/**
 * Resolve adapter theo `config('payments.gateways.*')`, CHỈ trong allowlist
 * `config('payments.enabled_gateways')` (ADR-001 §1, S4). `driver()` kiểm
 * allowlist trước khi gọi `parent::driver()` để một cổng bị gỡ khỏi
 * `PAYMENT_GATEWAYS` không thể bị resolve dù class vẫn tồn tại trong code.
 */
final class PaymentGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $enabled = $this->enabledGateways();

        if ($enabled === []) {
            throw new RuntimeException('Không có cổng thanh toán nào được bật (payments.enabled_gateways).');
        }

        return $enabled[0];
    }

    /**
     * @param  string|null  $driver
     */
    public function driver($driver = null): PaymentGateway
    {
        $driver ??= $this->getDefaultDriver();

        if (! in_array($driver, $this->enabledGateways(), true)) {
            throw new RuntimeException(sprintf(
                'Cổng thanh toán "%s" không nằm trong allowlist (payments.enabled_gateways).',
                $driver,
            ));
        }

        /** @var PaymentGateway */
        return parent::driver($driver);
    }

    /**
     * M2 (review bảo mật T17) — fail-closed ở MỌI môi trường (không chỉ
     * production): `ProductionConfigGuard` chỉ chạy khi `APP_ENV=production`
     * đúng chính tả. Ở môi trường khác (staging/UAT, hoặc production đặt
     * nhầm `APP_ENV`), thiếu credential MoMo mà không chặn ở đây sẽ khiến
     * `MoMoSigner` ký/verify bằng `secretKey=''` — một chữ ký AI CŨNG TÍNH
     * ĐƯỢC (xem `MoMoSigner`), cho phép giả mạo IPN/query.
     */
    protected function createMomoDriver(): PaymentGateway
    {
        $config = (array) $this->config->get('payments.gateways.momo', []);

        foreach (['partner_code', 'access_key', 'secret_key', 'endpoint'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                throw new RuntimeException(sprintf(
                    'Thiếu cấu hình MoMo "%s" — không khởi tạo được cổng thanh toán (T17, M2).',
                    $key,
                ));
            }
        }

        return new MoMoGateway($config, new MoMoSigner);
    }

    protected function createFakeDriver(): PaymentGateway
    {
        if (! app()->environment('local', 'testing')) {
            // Không có nhánh nào khác cho phép FakeGateway boot ở production
            // (S4) — dù có lỡ nằm trong `enabled_gateways` (guard riêng ở
            // `PaymentsProductionGuard` đã chặn từ trước, đây là lớp phòng
            // thủ thứ hai).
            throw new RuntimeException('FakeGateway chỉ được dùng ở local/testing (S4).');
        }

        return new FakeGateway;
    }

    /**
     * @return list<string>
     */
    private function enabledGateways(): array
    {
        /** @var list<string> */
        return $this->config->get('payments.enabled_gateways', []);
    }
}
