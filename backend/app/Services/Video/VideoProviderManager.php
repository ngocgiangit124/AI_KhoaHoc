<?php

namespace App\Services\Video;

use App\Services\Video\Contracts\VideoProvider;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\Providers\InternalVideoProvider;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolve adapter theo tên, CHỈ trong allowlist `config('video.enabled_providers')` (ADR-002 §1).
 * `fake` do VideoServiceProvider `extend` ở local/testing. `internal` (VideoLab, T12) cần `videolab.enabled`;
 * `bunny` chưa có adapter: resolve sẽ ném VideoProviderException (→ 503).
 *
 * @method VideoProvider driver(?string $driver = null)
 */
class VideoProviderManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return mb_strtolower((string) $this->config->get('video.provider'));
    }

    /** @return list<string> */
    public function enabled(): array
    {
        return array_values(array_map(
            static fn ($p) => mb_strtolower((string) $p),
            (array) $this->config->get('video.enabled_providers', [])
        ));
    }

    public function isEnabled(string $name): bool
    {
        return in_array(mb_strtolower($name), $this->enabled(), true);
    }

    /** @throws InvalidArgumentException tên ngoài allowlist */
    public function driver($driver = null)
    {
        $name = $driver === null ? $this->getDefaultDriver() : mb_strtolower((string) $driver);

        if (! $this->isEnabled($name)) {
            throw new InvalidArgumentException("Nhà cung cấp video [{$name}] không nằm trong allowlist.");
        }

        return parent::driver($name);
    }

    protected function createInternalDriver(): VideoProvider
    {
        // Chỉ khả dụng khi module VideoLab bật (route/migration cùng cờ `videolab.enabled`).
        if (! $this->config->get('videolab.enabled')) {
            throw new VideoProviderException('VideoLab đang tắt (VIDEOLAB_ENABLED).');
        }

        return $this->container->make(InternalVideoProvider::class);
    }

    protected function createBunnyDriver(): VideoProvider
    {
        throw new VideoProviderException('BunnyStreamProvider chưa được cài đặt/cấu hình (chờ tài khoản Bunny).');
    }
}
