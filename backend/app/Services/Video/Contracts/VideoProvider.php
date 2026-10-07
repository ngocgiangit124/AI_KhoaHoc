<?php

namespace App\Services\Video\Contracts;

use App\Models\VideoAsset;
use App\Services\Video\Data\PlaybackContext;
use App\Services\Video\Data\PlaybackInfo;
use App\Services\Video\Data\ProviderVideo;
use App\Services\Video\Data\UploadTarget;
use App\Services\Video\Exceptions\VideoNotFoundException;
use App\Services\Video\Exceptions\VideoProviderException;
use Illuminate\Http\Request;

/**
 * Hợp đồng nhà cung cấp video (ADR-002 §2). Nghiệp vụ chỉ biết `video_assets` + các DTO này; đổi VideoLab ↔
 * Bunny = đổi adapter, không đổi nghiệp vụ. Mọi lỗi phía nhà cung cấp ném VideoProviderException.
 *
 * BẮT BUỘC cho adapter thật: mọi lời gọi HTTP phải đặt timeout ≤ 10s (`Http::timeout(10)->connectTimeout(5)`),
 * vì request/job/webhook chờ trực tiếp vào đó.
 */
interface VideoProvider
{
    /** 'internal' | 'bunny' | 'fake' */
    public function name(): string;

    /**
     * Mã thư viện của nhà cung cấp, ghi vào `video_assets.provider_library_id` (mỗi nhà cung cấp một nguồn cấu hình).
     *
     * @throws VideoProviderException chưa cấu hình
     */
    public function libraryId(): string;

    /**
     * @param  int|null  $maxBytes  Kích thước tệp đã khai (T12-2): nhà cung cấp dùng làm trần upload cho video này
     *                              (adapter không hỗ trợ có thể bỏ qua; luôn bị kẹp bởi trần chung `video.max_upload_mb`).
     *
     * @throws VideoProviderException
     */
    public function createVideo(string $title, ?int $maxBytes = null): ProviderVideo;

    /** @throws VideoProviderException */
    public function uploadTarget(ProviderVideo $video, int $ttlSeconds): UploadTarget;

    /**
     * Trạng thái chuẩn hoá + thời lượng lấy từ nhà cung cấp qua API có xác thực (nguồn sự thật duy nhất).
     *
     * @throws VideoNotFoundException
     * @throws VideoProviderException
     */
    public function getVideo(string $providerVideoId): ProviderVideo;

    /** @throws VideoProviderException */
    public function playback(VideoAsset $asset, PlaybackContext $ctx): PlaybackInfo;

    /**
     * Xoá video ở nhà cung cấp. Không tồn tại = coi như đã xoá (không ném).
     *
     * @throws VideoProviderException
     */
    public function deleteVideo(string $providerVideoId): void;

    /**
     * Chỉ trả `provider_video_id` từ payload webhook (KHÔNG tin trạng thái trong payload); null nếu không hợp lệ.
     */
    public function parseWebhook(Request $request): ?string;
}
