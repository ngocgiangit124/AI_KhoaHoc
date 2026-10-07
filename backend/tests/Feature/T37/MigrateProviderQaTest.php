<?php

// QA T37-1: bổ sung cho MigrateProviderTest. Khoá Redis THẬT (prefix riêng, không đụng khoá dev), rollback các nhánh từ chối,
// mã thoát, dry-run, --limit, --delete-source cần --force, không hai sổ dở. Helper mp* nằm ở MigrateProviderTest.php
// (cùng nạp trong một lần chạy); file này có helper riêng `qa*` để chạy độc lập.

use App\Enums\VideoAssetStatus;
use App\Models\VideoAsset;
use App\Models\VideoProviderMigration;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T12/helpers.php';

const QA_NEW = 'aaaaaaaa-1111-cccc-dddd-eeeeeeeeeeee';
const QA_LOCK = 'videos:migrate-provider';

function qaSetup(): string
{
    bzConfigure([], ['bunny', 'internal']);
    config([
        'video.provider' => 'internal', 'videolab.enabled' => true, 'video.library_id' => 'default',
        'video.migration.poll_interval_seconds' => 0, 'video.migration.wait_seconds' => 0,
    ]);
    Cache::lock(QA_LOCK)->forceRelease();

    return vlUseTempStorage();
}

function qaAsset(string $dir, bool $withSource = true): VideoAsset
{
    [, , $lesson] = vvContentSet();
    $guid = (string) Str::uuid();
    $asset = VideoAsset::factory()->create([
        'provider' => 'internal', 'provider_library_id' => 'default', 'provider_video_id' => $guid,
        'status' => VideoAssetStatus::Ready, 'duration_seconds' => 60, 'lesson_id' => $lesson->id,
    ]);
    $lesson->forceFill(['video_source' => 'upload', 'video_asset_id' => $asset->id])->save();

    if ($withSource) {
        File::ensureDirectoryExists($dir.'/source');
        file_put_contents($dir.'/source/'.$guid.'.bin', 'QA-BYTES');
    }

    return $asset;
}

/** Bunny giả: mỗi POST trả guid kế tiếp (QA_NEW với hậu tố tăng dần); PUT xong thì ready. */
function qaRoutes(array $over = [], int $remoteStatus = 4): void
{
    $seq = 0;
    $done = [];
    bzFakeApi($over + [
        'POST '.bzVideosPath() => function () use (&$seq) {
            return Http::response(bzVideoBody(substr(QA_NEW, 0, -2).sprintf('%02d', ++$seq)), 200);
        },
    ] + qaGuidRoutes($done, $remoteStatus));
}

/** Route theo guid: tạo sẵn 1..5 cho PUT/GET/DELETE. */
function qaGuidRoutes(array &$done, int $remoteStatus): array
{
    $routes = [];

    foreach (range(1, 5) as $i) {
        $g = substr(QA_NEW, 0, -2).sprintf('%02d', $i);
        $routes['PUT '.bzVideosPath('/'.$g)] = function () use (&$done, $g) {
            $done[$g] = true;

            return Http::response(['success' => true], 200);
        };
        $routes['GET '.bzVideosPath('/'.$g)] = function () use (&$done, $g, $remoteStatus) {
            return Http::response(isset($done[$g]) ? bzVideoBody($g, $remoteStatus, 90) : bzVideoBody($g, 0), 200);
        };
        $routes['DELETE '.bzVideosPath('/'.$g)] = Http::response([], 200);
    }

    return $routes;
}

function qaRun(array $opts = []): array
{
    return [Artisan::call('videos:migrate-provider', $opts), Artisan::output()];
}

function qaGuid(int $i): string
{
    return substr(QA_NEW, 0, -2).sprintf('%02d', $i);
}

/** Dùng Redis thật cho cache/lock với prefix riêng; trả prefix. */
function qaUseRedis(): string
{
    $prefix = 'qa_t371_'.bin2hex(random_bytes(4));
    config(['cache.default' => 'redis', 'cache.prefix' => $prefix]);
    app('cache')->forgetDriver('redis');
    app('cache')->forgetDriver('array');

    return $prefix;
}

function qaRedisKeys(string $prefix): array
{
    return Redis::connection(config('cache.stores.redis.lock_connection', 'default'))->keys('*'.$prefix.'*');
}

function qaLockRedisConn()
{
    return Redis::connection(config('cache.stores.redis.lock_connection', 'default'));
}

afterEach(function () {
    $root = config('filesystems.disks.videolab.root');

    if (is_string($root) && str_contains($root, 'vl-test-')) {
        File::deleteDirectory($root);
    }

    if (config('cache.default') === 'redis' && str_starts_with((string) config('cache.prefix'), 'qa_t371_')) {
        Cache::lock(QA_LOCK)->forceRelease();
    }
});

test('QA R1: khoá Redis thật: refresh đưa TTL về đầy trước mỗi asset, nhả khoá khi xong', function () {
    $dir = qaSetup();
    config(['video.migration.upload_timeout_seconds' => 100]); // TTL kỳ vọng = 100 + 0 + 900 = 1000
    $prefix = qaUseRedis();
    qaAsset($dir);
    qaAsset($dir);
    $ttls = [];
    $n = 0;
    qaRoutes(['POST '.bzVideosPath() => function () use (&$n, &$ttls, $prefix) {
        $keys = qaRedisKeys($prefix);
        $conn = qaLockRedisConn();
        $key = isset($keys[0]) ? preg_replace('/^.*?(?='.preg_quote($prefix, '/').')/', '', $keys[0]) : null;
        $ttls[] = $key === null ? null : $conn->ttl($key);
        if ($key !== null) {
            $conn->expire($key, 30); // giả lập TTL sắp hết giữa lúc chạy
        }

        return Http::response(bzVideoBody(qaGuid(++$n)), 200);
    }]);

    [$code] = qaRun();

    expect($code)->toBe(0)->and($ttls)->toHaveCount(2)
        ->and($ttls[0])->toBeGreaterThan(900)
        ->and($ttls[1])->toBeGreaterThan(900) // asset 2: refresh đã kéo từ 30 lên lại ~1000
        ->and(qaRedisKeys($prefix))->toBe([]); // đã nhả khoá
});

test('QA R1: hai tiến trình thật: tiến trình khác giữ khoá Redis thì lệnh từ chối; kill -9 để lại khoá, --unlock nhả', function () {
    $dir = qaSetup();
    $prefix = qaUseRedis();
    qaAsset($dir);
    Http::fake();

    $php = 'Cache::lock("'.QA_LOCK.'", 600)->get(); posix_kill(getmypid(), 9);';
    $child = new Process([PHP_BINARY, 'artisan', 'tinker', '--execute='.$php], base_path(), [
        'CACHE_STORE' => 'redis', 'CACHE_PREFIX' => $prefix, 'APP_ENV' => 'testing',
    ]);
    $child->start();

    while ($child->isRunning()) {
        usleep(100000);
    }

    expect($child->getTermSignal())->toBe(9) // bị kill -9: `finally` không chạy
        ->and(qaRedisKeys($prefix))->not->toBe([]); // khoá do tiến trình khác tạo và chưa được nhả

    [$code, $out] = qaRun();
    expect($code)->toBe(1)->and($out)->toContain('khác');
    Http::assertNothingSent();
    expect(VideoProviderMigration::query()->count())->toBe(0);

    expect(qaRun(['--unlock' => true, '--force' => true])[0])->toBe(0)->and(qaRedisKeys($prefix))->toBe([]);
    qaRoutes();
    expect(qaRun()[0])->toBe(0);
});

test('QA R1: --unlock của tiến trình khác nhả được khoá do tiến trình này giữ (cùng Redis)', function () {
    qaSetup();
    $prefix = qaUseRedis();
    $lock = Cache::lock(QA_LOCK, 600);
    expect($lock->get())->toBeTrue();

    $child = new Process([PHP_BINARY, 'artisan', 'videos:migrate-provider', '--unlock', '--force'], base_path(), [
        'CACHE_STORE' => 'redis', 'CACHE_PREFIX' => $prefix, 'APP_ENV' => 'testing',
    ]);
    $child->run();

    expect($child->getExitCode())->toBe(0)->and(qaRedisKeys($prefix))->toBe([]);
});

test('QA R3: rollback các nhánh từ chối: không có asset, chưa chuyển, asset đã đổi, VideoLab không còn ready', function () {
    $dir = qaSetup();
    $asset = qaAsset($dir);
    $guid = $asset->provider_video_id;
    qaRoutes();

    [$c, $o] = qaRun(['--rollback' => true, '--asset' => '999999']);
    expect($c)->toBe(1)->and($o)->toContain('không có asset');

    [$c, $o] = qaRun(['--rollback' => true, '--asset' => (string) $asset->id]);
    expect($c)->toBe(1)->and($o)->toContain('không có lần chuyển hoàn tất')->and($asset->fresh()->provider)->toBe('internal');

    qaRun(['--asset' => (string) $asset->id]);
    expect($asset->fresh()->provider)->toBe('bunny');

    // asset bị trỏ sang video khác
    VideoAsset::query()->whereKey($asset->id)->update(['provider_video_id' => 'khac-'.Str::uuid()]);
    [$c, $o] = qaRun(['--rollback' => true, '--asset' => (string) $asset->id]);
    expect($c)->toBe(1)->and($o)->toContain('không còn trỏ')->and(VideoProviderMigration::query()->sole()->status)->toBe('completed');
    VideoAsset::query()->whereKey($asset->id)->update(['provider_video_id' => qaGuid(1)]);

    // VideoLab không còn ready
    bzFakeApi(['GET /videolab/library/default/videos/'.$guid => Http::response(['guid' => $guid, 'status' => 5], 200)]);
    [$c, $o] = qaRun(['--rollback' => true, '--asset' => (string) $asset->id]);
    expect($c)->toBe(1)->and($o)->toContain('không còn ready')->and($asset->fresh()->provider)->toBe('bunny');

    // --asset sai kiểu
    expect(qaRun(['--rollback' => true, '--asset' => 'x'])[0])->toBe(1);
});

test('QA R3: rollback hai lần: lần hai không có gì để hoàn tác, không đổi asset', function () {
    $dir = qaSetup();
    $asset = qaAsset($dir);
    $guid = $asset->provider_video_id;
    qaRoutes(['GET /videolab/library/default/videos/'.$guid => Http::response(['guid' => $guid, 'status' => 4, 'length' => 61], 200)]);
    qaRun();

    expect(qaRun(['--rollback' => true, '--asset' => (string) $asset->id])[0])->toBe(0)->and($asset->fresh()->provider)->toBe('internal');
    [$c, $o] = qaRun(['--rollback' => true, '--asset' => (string) $asset->id]);
    expect($c)->toBe(1)->and($o)->toContain('không có lần chuyển hoàn tất')->and($asset->fresh()->provider_video_id)->toBe($guid);
});

test('QA mã thoát 2: còn asset chờ Bunny mã hoá (không lỗi)', function () {
    $dir = qaSetup();
    qaAsset($dir);
    qaRoutes(remoteStatus: 3);

    expect(qaRun()[0])->toBe(2);
});

test('QA mã thoát 1: có asset lỗi PUT; chạy lại khi Bunny ổn thì 0', function () {
    $dir = qaSetup();
    qaAsset($dir);
    qaRoutes(['PUT '.bzVideosPath('/'.qaGuid(1)) => Http::response('x', 500)]);
    expect(qaRun()[0])->toBe(1);

    qaRoutes();
    expect(qaRun()[0])->toBe(0);
});

test('QA mã thoát: lỗi thắng chờ (một asset chờ + một asset lỗi => 1)', function () {
    $dir = qaSetup();
    qaAsset($dir);
    qaAsset($dir);
    $n = 0;
    $done = [];
    $routes = qaGuidRoutes($done, 3) + ['POST '.bzVideosPath() => function () use (&$n) {
        return ++$n === 1 ? Http::response(bzVideoBody(qaGuid(1)), 200) : Http::response('err', 500);
    }];
    bzFakeApi($routes);

    expect(qaRun()[0])->toBe(1);
});

test('QA dry-run: không đổi DB/mạng/tệp kể cả khi có sổ dở và kèm --delete-source', function () {
    $dir = qaSetup();
    $asset = qaAsset($dir);
    VideoProviderMigration::factory()->create([
        'video_asset_id' => $asset->id, 'from_video_id' => $asset->provider_video_id, 'to_video_id' => qaGuid(1), 'to_library_id' => BZ_LIB,
    ]);
    Http::fake();
    $before = [DB::table('video_assets')->get()->toJson(), DB::table('video_provider_migrations')->get()->toJson(), DB::table('lessons')->get()->toJson()];

    [$code, $out] = qaRun(['--dry-run' => true, '--delete-source' => true]);

    expect($code)->toBe(0)->and($out)->toContain('tiếp tục lần chuyển dở')
        ->and([DB::table('video_assets')->get()->toJson(), DB::table('video_provider_migrations')->get()->toJson(), DB::table('lessons')->get()->toJson()])->toBe($before)
        ->and(is_file($dir.'/source/'.$asset->provider_video_id.'.bin'))->toBeTrue();
    Http::assertNothingSent();
    // dry-run không giữ khoá
    expect(Cache::lock(QA_LOCK, 10)->get())->toBeTrue();
});

test('QA --limit: đúng N, --limit=0 và âm bị từ chối; chạy tiếp lấy phần còn lại, không trùng', function () {
    $dir = qaSetup();
    $ids = [qaAsset($dir)->id, qaAsset($dir)->id, qaAsset($dir)->id];
    qaRoutes();

    expect(qaRun(['--limit' => '0'])[0])->toBe(1)->and(qaRun(['--limit' => '-1'])[0])->toBe(1)->and(qaRun(['--asset' => '0'])[0])->toBe(1);
    Http::assertNothingSent();

    qaRun(['--limit' => '2']);
    expect(VideoAsset::query()->whereIn('id', $ids)->where('provider', 'bunny')->count())->toBe(2);

    qaRun(['--limit' => '2']);
    expect(VideoAsset::query()->whereIn('id', $ids)->where('provider', 'bunny')->count())->toBe(3)
        ->and(VideoProviderMigration::query()->count())->toBe(3)
        ->and(VideoProviderMigration::query()->distinct()->count('to_video_id'))->toBe(3);
});

test('QA --delete-source không có --force và không tương tác: không xoá, thoát 0', function () {
    $dir = qaSetup();
    $asset = qaAsset($dir);
    $guid = $asset->provider_video_id;
    qaRoutes(['DELETE /videolab/library/default/videos/'.$guid => Http::response([], 200)]);
    qaRun();

    [$code, $out] = qaRun(['--delete-source' => true]);

    expect($code)->toBe(0)->and($out)->toContain('Đã huỷ xoá nguồn')->and(VideoProviderMigration::query()->sole()->source_deleted_at)->toBeNull();
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/videolab/'));

});

test('QA không có hai sổ dở cho một asset: chạy lặp, chờ, rồi hoàn tất', function () {
    $dir = qaSetup();
    $asset = qaAsset($dir);
    qaRoutes(remoteStatus: 3);

    foreach (range(1, 3) as $_) {
        qaRun();
        expect(VideoProviderMigration::query()->where('video_asset_id', $asset->id)->whereIn('status', ['pending', 'uploaded'])->count())->toBe(1);
    }

    qaRoutes();
    $done = [qaGuid(1) => true];
    qaRoutes(['GET '.bzVideosPath('/'.qaGuid(1)) => Http::response(bzVideoBody(qaGuid(1), 4, 90), 200)]);
    expect(qaRun()[0])->toBe(0)->and(VideoProviderMigration::query()->where('video_asset_id', $asset->id)->count())->toBe(1);
    Http::assertNotSent(fn (HttpRequest $r) => in_array($r->method(), ['POST', 'PUT'], true));
    unset($done);
});

test('QA idempotent: chạy lại sau hoàn tất không đổi bài học/updated_at/số audit', function () {
    $dir = qaSetup();
    $asset = qaAsset($dir);
    qaRoutes();
    qaRun();
    $snap = [DB::table('video_assets')->where('id', $asset->id)->first(), DB::table('lessons')->where('id', $asset->lesson_id)->first(), DB::table('audit_logs')->where('subject_id', $asset->id)->count()];

    qaRoutes();
    qaRun();
    qaRun(['--asset' => (string) $asset->id]);

    expect(DB::table('video_assets')->where('id', $asset->id)->first())->toEqual($snap[0])
        ->and(DB::table('lessons')->where('id', $asset->lesson_id)->first())->toEqual($snap[1])
        ->and(DB::table('audit_logs')->where('subject_id', $asset->id)->count())->toBe($snap[2]);
});
