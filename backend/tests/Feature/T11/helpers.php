<?php

use App\Enums\VideoAssetStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\VideoAsset;
use App\Services\Video\Providers\FakeVideoProvider;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../T09/helpers.php';
require_once __DIR__.'/../T03/helpers.php';

/** Bật provider `fake` làm mặc định (route/provider đã đăng ký ở testing). */
function vvVideoFake(): FakeVideoProvider
{
    config(['video.provider' => 'fake', 'video.enabled_providers' => ['fake']]);

    return app(FakeVideoProvider::class);
}

function vvUploadPath(Course $course, Lesson $lesson): string
{
    return "/admin/courses/{$course->id}/lessons/{$lesson->id}/video-uploads";
}

function vvVideoPath(Course $course, Lesson $lesson): string
{
    return "/admin/courses/{$course->id}/lessons/{$lesson->id}/video";
}

function vvRequestUpload(Course $course, Lesson $lesson, array $over = []): TestResponse
{
    return vvCourseJson('POST', vvUploadPath($course, $lesson), array_merge(['filename' => 'bai1.mp4', 'size' => 50 * 1024 * 1024], $over));
}

function vvWebhook(string $provider, array $payload): TestResponse
{
    return test()->postJson(vvApiUrl("/webhooks/video/{$provider}"), $payload, ['Accept' => 'application/json']);
}

/** Bài đã gắn asset (provider fake, đã tạo ở nhà cung cấp giả). */
function vvLessonWithAsset(VideoAssetStatus $status = VideoAssetStatus::Uploading, array $assetOver = []): array
{
    $fake = vvVideoFake();
    [$course, $chapter, $lesson] = vvContentSet();
    $video = $fake->createVideo('x');
    $asset = VideoAsset::factory()->create(array_merge([
        'provider' => 'fake', 'provider_video_id' => $video->guid, 'status' => $status, 'lesson_id' => $lesson->id,
    ], $assetOver));
    $lesson->forceFill(['video_source' => 'upload', 'video_asset_id' => $asset->id])->save();

    return [$course, $lesson->fresh(), $asset, $fake];
}
