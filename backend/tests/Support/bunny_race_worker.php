<?php

/** Tiến trình con cho race test T37 (Bunny, Http::fake trong tiến trình). Chỉ chạy trên DB `*_testing`. */

use App\Exceptions\DomainException;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Video\VideoUploadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! preg_match('/_testing(_[a-z])?$/', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "REFUSE: khong phai DB test\n");
    exit(9);
}

config([
    'video.provider' => 'bunny',
    'video.enabled_providers' => ['bunny'],
    'video.providers.bunny' => [
        'library_id' => '777', 'api_key' => 'bq-race-api-key', 'cdn_host' => 'vz-race.b-cdn.net', 'token_key' => 'bq-race-token-key',
        'webhook_token' => str_repeat('w', 40), 'api_base' => 'https://video.bunnycdn.com', 'tus_endpoint' => 'https://video.bunnycdn.com/tusupload',
    ],
]);

$mode = $argv[1];
$args = array_slice($argv, 2);
$GB = 1024 * 1024 * 1024;

$out = match ($mode) {
    // args: ledgerGb, lessonCount
    'setup' => (function () use ($args, $GB) {
        $course = Course::factory()->create();
        $chapter = Chapter::factory()->for($course)->create(['position' => 1]);
        $lessons = [];
        for ($i = 1; $i <= (int) $args[1]; $i++) {
            $lessons[] = Lesson::factory()->for($course)->for($chapter)->create(['position' => $i])->id;
        }
        $actor = User::factory()->teacher()->create();
        DB::table('video_upload_usages')->insert([
            'user_id' => $actor->id, 'usage_date' => now()->toDateString(), 'bytes' => (int) $args[0] * $GB, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['course' => $course->id, 'lessons' => $lessons, 'actor' => $actor->id, 'creator' => $course->created_by];
    })(),
    // Không xoá audit_logs (trigger L2 chặn). video_upload_usages tự xoá theo FK cascade khi xoá user.
    'cleanup' => (function () use ($args) {
        DB::table('lessons')->where('course_id', $args[0])->update(['video_asset_id' => null]);
        DB::table('video_assets')->where('created_by', $args[1])->delete();
        DB::table('lessons')->where('course_id', $args[0])->delete();
        DB::table('chapters')->where('course_id', $args[0])->delete();
        DB::table('courses')->where('id', $args[0])->delete();
        DB::table('users')->whereIn('id', [$args[1], $args[2]])->delete();

        return ['ok' => true];
    })(),
    'state' => (function () use ($args) {
        return [
            'assets' => DB::table('video_assets')->where('created_by', $args[0])->get(['id', 'provider', 'provider_library_id', 'provider_video_id', 'declared_size_bytes'])->all(),
            'ledger' => (int) DB::table('video_upload_usages')->where('user_id', $args[0])->sum('bytes'),
        ];
    })(),
    // args: course, lesson, actor, startAt, sizeMB, index
    'upload' => (function () use ($args) {
        $course = Course::findOrFail($args[0]);
        $lesson = Lesson::findOrFail($args[1]);
        $actor = User::findOrFail($args[2]);
        $guid = sprintf('aaaaaaaa-0000-4000-8000-%012d', (int) $args[1]);
        Http::fake(fn () => Http::response(['guid' => $guid, 'videoLibraryId' => 777, 'status' => 0], 200));
        while (microtime(true) < (float) $args[3]) {
            usleep(200);
        }

        try {
            app(VideoUploadService::class)->createUpload($actor, $course, $lesson, 'a.mp4', (int) $args[4] * 1024 * 1024);

            return ['result' => 'ok'];
        } catch (DomainException $e) {
            return ['result' => 'domain', 'code' => $e->code(), 'status' => $e->status()];
        } catch (Throwable $e) {
            return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 200)];
        }
    })(),
    default => ['result' => 'unknown-mode'],
};

echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
