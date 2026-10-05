<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Gateways\Momo\MoMoGateway;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolve adapter theo tên, CHỈ trong allowlist `config('payments.enabled_gateways')` (ADR-001 §1, S4).
 * `fake` không có driver tích hợp sẵn: AppServiceProvider chỉ `extend('fake')` khi local/testing.
 *
 * @method PaymentGateway driver(?string $driver = null)
 */
class PaymentGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $enabled = $this->enabled();

        if ($enabled === []) {
            throw new InvalidArgumentException('payments.enabled_gateways rỗng.');
        }

        return $enabled[0];
    }

    /** @return list<string> */
    public function enabled(): array
    {
        return array_values(array_map(
            static fn ($g) => mb_strtolower((string) $g),
            (array) $this->config->get('payments.enabled_gateways', [])
        ));
    }

    public function driver($driver = null)
    {
        $name = $driver === null ? $this->getDefaultDriver() : mb_strtolower((string) $driver);

        if (! in_array($name, $this->enabled(), true)) {
            throw new InvalidArgumentException("Cổng thanh toán [{$name}] không nằm trong allowlist.");
        }

        return parent::driver($name);
    }

    protected function createMomoDriver(): PaymentGateway
    {
        return new MoMoGateway;
    }
}
