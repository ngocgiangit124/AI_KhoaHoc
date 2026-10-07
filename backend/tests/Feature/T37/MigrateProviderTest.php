<?php

use App\Enums\VideoAssetStatus;
use App\Models\AuditLog;
use App\Models\VideoAsset;
use App\Models\VideoProviderMigration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T12/helpers.php';

const MP_CONTENT = 'FAKE-ORIGINAL-VIDEO-BYTES';

/** Chuẩn bị môi trường chuyển: Bunny hợp lệ + allowlist cả hai + kho VideoLab tạm. */
function mpSetup(): string
{
    bzConfigure([], ['bunny', 'internal']);
    config([
        'video.provider' => 'internal', 'videolab.enabled' => true, 'video.library_id' => 'default',
        'video.migration.poll_interval_seconds' => 0, 'video.migration.wait_seconds' => 0,
    ]);
    Cache::lock('videos:migrate-provider')->forceRelease();

    return vlUseTempStorage();
}

/** Asset `ready` của VideoLab (+ tệp gốc nếu $withSource). */
function mpAsset(string $dir, bool $withSource = true, VideoAssetStatus $status = VideoAssetStatus::Ready): VideoAsset
{
    [, $chapter, $lesson] = vvContentSet();
    $guid = (string) Str::uuid();
    $asset = VideoAsset::factory()->create([
        'provider' => 'internal', 'provider_library_id' => 'default', 'provider_video_id' => $guid,
        'status' => $status, 'duration_seconds' => 60, 'lesson_id' => $lesson->id,
    ]);
    $lesson->forceFill(['video_source' => 'upload', 'video_asset_id' => $asset->id])->save();

    if ($withSource) {
        File::ensureDirectoryExists($dir.'/source');
        file_put_contents($dir.'/source/'.$guid.'.bin', MP_CONTENT);
    }

    return $asset;
}

const MP_NEW_GUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

/** @return array<string, mixed> */
function mpRoutes(int $putStatus = 200, int $remoteStatus = 4, ?int $createStatus = 200, string $guid = MP_NEW_GUID): array
{
    $GLOBALS['mp_put_done'] = false; // Bunny chỉ ở `created` cho tới khi nhận tệp

    return [
        'POST '.bzVideosPath() => Http::response(bzVideoBody($guid), $createStatus),
        'PUT '.bzVideosPath('/'.$guid) => function (HttpRequest $r) use ($putStatus) {
            $GLOBALS['mp_put_body'] = $r->body(); // đọc ngay: stream bị đóng sau khi gửi
            $GLOBALS['mp_put_done'] = $putStatus < 400;

            return Http::response(['success' => true], $putStatus);
        },
        'GET '.bzVideosPath('/'.$guid) => fn () => Http::response(
            $GLOBALS['mp_put_done'] ? bzVideoBody($guid, $remoteStatus, 125.4) : bzVideoBody($guid, 0), 200),
        'DELETE '.bzVideosPath('/'.$guid) => Http::response([], 200),
    ];
}

function mpRun(array $opts = []): array
{
    $code = Artisan::call('videos:migrate-provider', $opts);

    return [$code, Artisan::output()];
}

function mpCount(string $method): int
{
    $n = 0;
    Http::assertSent(function (HttpRequest $r) use (&$n, $method) {
        $n += $r->method() === $method ? 1 : 0;

        return true;
    });

    return $n;
}

afterEach(function () {
    $root = config('filesystems.disks.videolab.root');

    if (is_string($root) && str_contains($root, 'vl-test-')) {
        File::deleteDirectory($root);
    }
});

test('dry-run: chi liet ke, khong goi mang, khong ghi DB', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    $missing = mpAsset($dir, false);
    Http::fake();

    [$code, $out] = mpRun(['--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toContain("asset #{$asset->id}")->and($out)->toContain("asset #{$missing->id}: BỎ QUA");
    Http::assertNothingSent();
    expect(VideoProviderMigration::query()->count())->toBe(0)->and($asset->fresh()->provider)->toBe('internal');
});

test('chuyen thanh cong: doi provider/guid/library/thoi luong, giu video cu, audit khong chua khoa', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    $guid = $asset->provider_video_id;
    bzFakeApi(mpRoutes());
    $auditBefore = AuditLog::query()->where('action', 'video.provider_migrated')->count();

    [$code, $out] = mpRun(['--asset' => (string) $asset->id]);

    expect($code)->toBe(0);
    $fresh = $asset->fresh();
    expect($fresh->provider)->toBe('bunny')->and($fresh->provider_library_id)->toBe(BZ_LIB)->and($fresh->provider_video_id)->toBe(MP_NEW_GUID)
        ->and($fresh->duration_seconds)->toBe(125)->and($fresh->status)->toBe(VideoAssetStatus::Ready)
        ->and($fresh->lesson->fresh()->duration_seconds)->toBe(125);

    $row = VideoProviderMigration::query()->where('video_asset_id', $asset->id)->sole();
    expect($row->status)->toBe('completed')->and($row->from_video_id)->toBe($guid)->and($row->source_deleted_at)->toBeNull()
        ->and(is_file($dir.'/source/'.$guid.'.bin'))->toBeTrue();

    expect($GLOBALS['mp_put_body'])->toBe(MP_CONTENT);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->hasHeader('AccessKey', BZ_API_KEY)
        && $r->header('Content-Type')[0] === 'application/octet-stream');
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE');

    $audit = AuditLog::query()->where('action', 'video.provider_migrated')->where('subject_id', $asset->id)->sole();
    expect(AuditLog::query()->where('action', 'video.provider_migrated')->count())->toBe($auditBefore + 1);
    bzAssertNoSecret(json_encode($audit->changes).$out);
});

test('chay lai sau khi xong: idempotent, khong goi Bunny nua', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    bzFakeApi(mpRoutes());
    mpRun();

    Http::swap(new Factory);
    Http::fake();
    [$code, $out] = mpRun();

    expect($code)->toBe(0)->and($out)->toContain('Đã chuyển: 0')->and(VideoProviderMigration::query()->where('video_asset_id', $asset->id)->count())->toBe(1);
    Http::assertNothingSent();
});

test('Bunny loi giua chung (PUT 500): asset giu nguyen, sau do chay lai tiep tuc ma khong tao trung video', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    bzFakeApi(mpRoutes(putStatus: 500));

    [$code, $out] = mpRun();

    expect($code)->toBe(1)->and($out)->toContain('lần chạy sau thử lại');
    $fresh = $asset->fresh();
    expect($fresh->provider)->toBe('internal')->and($fresh->status)->toBe(VideoAssetStatus::Ready);
    $row = VideoProviderMigration::query()->where('video_asset_id', $asset->id)->sole();
    expect($row->status)->toBe('pending')->and($row->last_error)->not->toBeNull()->and($row->to_video_id)->toBe(MP_NEW_GUID);
    bzAssertNoSecret($row->last_error.$out);

    bzFakeApi(mpRoutes());
    [$code2] = mpRun();

    expect($code2)->toBe(0)->and($asset->fresh()->provider)->toBe('bunny')->and(VideoProviderMigration::query()->count())->toBe(1);
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST'); // dùng lại video đã tạo
});

test('Bunny tao video loi (500 / ngat ket noi): khong co so, asset giu nguyen', function (string $kind) {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    $reply = $kind === 'timeout'
        ? fn () => throw new ConnectionException('cURL error url=https://x?AccessKey='.BZ_API_KEY)
        : Http::response('err', 500);
    bzFakeApi(['POST '.bzVideosPath() => $reply]);

    [$code, $out] = mpRun();

    expect($code)->toBe(1)->and(VideoProviderMigration::query()->count())->toBe(0)->and($asset->fresh()->provider)->toBe('internal');
    bzAssertNoSecret($out);
})->with(['500', 'timeout']);

test('Bunny dang ma hoa: cho, asset giu nguyen; lan sau ghi nhan khi ready ma khong tai lai', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    bzFakeApi(mpRoutes(remoteStatus: 3));

    [$code, $out] = mpRun();

    expect($code)->toBe(2)->and($out)->toContain('đang chờ Bunny: 1')->and($out)->toContain('Còn 1 asset chờ')->and($asset->fresh()->provider)->toBe('internal')
        ->and(VideoProviderMigration::query()->sole()->status)->toBe('uploaded');

    bzFakeApi(mpRoutes());
    $GLOBALS['mp_put_done'] = true;
    [$code2] = mpRun();

    expect($code2)->toBe(0)->and($asset->fresh()->provider)->toBe('bunny');
    Http::assertNotSent(fn (HttpRequest $r) => in_array($r->method(), ['POST', 'PUT'], true));
});

test('Bunny bao loi ma hoa: xoa video dich, asset giu nguyen, lan sau tao lai tu dau', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    bzFakeApi(mpRoutes(remoteStatus: 5));

    [$code] = mpRun();

    expect($code)->toBe(1)->and($asset->fresh()->provider)->toBe('internal')->and(VideoProviderMigration::query()->sole()->status)->toBe('failed');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), MP_NEW_GUID));

    $second = 'bbbbbbbb-bbbb-cccc-dddd-eeeeeeeeeeee';
    bzFakeApi(mpRoutes(guid: $second));
    [$code2] = mpRun();

    expect($code2)->toBe(0)->and($asset->fresh()->provider_video_id)->toBe($second)->and(VideoProviderMigration::query()->count())->toBe(2);
});

test('khoa chong chay song song: lan chay thu hai tu choi, khong goi Bunny', function () {
    $dir = mpSetup();
    mpAsset($dir);
    Http::fake();
    $lock = Cache::lock('videos:migrate-provider', 60);
    expect($lock->get())->toBeTrue();

    [$code, $out] = mpRun();
    $lock->release();

    expect($code)->toBe(1)->and($out)->toContain('khác');
    Http::assertNothingSent();
    expect(VideoProviderMigration::query()->count())->toBe(0);

    bzFakeApi(mpRoutes());
    expect(mpRun()[0])->toBe(0); // khoá đã nhả
});

test('asset khong ready, khong o VideoLab hoac thieu tep goc bi bo qua (khong tao video o Bunny)', function () {
    $dir = mpSetup();
    mpAsset($dir, true, VideoAssetStatus::Processing);
    $noSource = mpAsset($dir, false);
    $bunnyAsset = mpAsset($dir);
    $bunnyAsset->forceFill(['provider' => 'bunny'])->save();
    Http::fake();

    [$code, $out] = mpRun();

    expect($code)->toBe(0)->and($out)->toContain("asset #{$noSource->id}: [skipped]")->and($out)->toContain('bỏ qua: 1');
    Http::assertNothingSent();
    expect(VideoProviderMigration::query()->count())->toBe(0);
});

test('--limit va --asset gioi han so asset; tham so sai bi tu choi', function () {
    $dir = mpSetup();
    $a = mpAsset($dir);
    $b = mpAsset($dir);
    bzFakeApi(mpRoutes());

    mpRun(['--limit' => '1']);
    expect($a->fresh()->provider)->toBe('bunny')->and($b->fresh()->provider)->toBe('internal');

    expect(mpRun(['--limit' => 'abc'])[0])->toBe(1)->and(mpRun(['--from' => 'bunny', '--to' => 'internal'])[0])->toBe(1);
});

test('thieu cau hinh Bunny hoac bunny khong nam trong allowlist: tu choi, khong lo khoa', function () {
    $dir = mpSetup();
    mpAsset($dir);
    bzConfigure(['token_key' => null], ['bunny', 'internal']);
    Http::fake();

    [$code, $out] = mpRun();

    expect($code)->toBe(1)->and($out)->toContain('BUNNY_TOKEN_KEY');
    bzAssertNoSecret($out);
    Http::assertNothingSent();

    bzConfigure([], ['internal']);
    expect(mpRun(['--dry-run' => true])[0])->toBe(1);
});

test('--delete-source: chi xoa nguon sau xac nhan, va chi khi Bunny van ready', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    $guid = $asset->provider_video_id;
    $internalDelete = 'DELETE /videolab/library/default/videos/'.$guid;
    $routes = mpRoutes() + [$internalDelete => Http::response([], 200)];
    bzFakeApi($routes);
    mpRun();
    expect(VideoProviderMigration::query()->sole()->source_deleted_at)->toBeNull();

    // Từ chối xác nhận: không xoá.
    $this->artisan('videos:migrate-provider', ['--delete-source' => true])->expectsConfirmation('Xoá VĨNH VIỄN 1 video nguồn ở VideoLab (đã có bản ready ở Bunny)?', 'no')->assertExitCode(0);
    expect(VideoProviderMigration::query()->sole()->source_deleted_at)->toBeNull();

    // Bunny chưa ready: không xoá.
    bzFakeApi(array_merge($routes, ['GET '.bzVideosPath('/'.MP_NEW_GUID) => Http::response(bzVideoBody(MP_NEW_GUID, 3), 200)]));
    mpRun(['--delete-source' => true, '--force' => true]);
    expect(VideoProviderMigration::query()->sole()->source_deleted_at)->toBeNull();

    bzFakeApi($routes);
    [$code] = mpRun(['--delete-source' => true, '--force' => true]);

    expect($code)->toBe(0)->and(VideoProviderMigration::query()->sole()->source_deleted_at)->not->toBeNull();
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/videolab/library/default/videos/'.$guid));
});

test('asset bi doi trong luc chuyen (provider khac): khong ghi de, xoa video dich', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    $routes = mpRoutes();
    // Giả lập bài bị đổi sang video khác ngay trước khi chốt.
    $routes['GET '.bzVideosPath('/'.MP_NEW_GUID)] = function () use ($asset) {
        VideoAsset::query()->whereKey($asset->id)->update(['provider_video_id' => (string) Str::uuid()]);

        return Http::response(bzVideoBody(MP_NEW_GUID, 4, 10), 200);
    };
    bzFakeApi($routes);

    [$code, $out] = mpRun();

    expect($asset->fresh()->provider)->toBe('internal')->and($out)->toContain('[skipped]')
        ->and(VideoProviderMigration::query()->sole()->status)->toBe('abandoned');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), MP_NEW_GUID));
});

test('R1: --unlock nha khoa ket (co xac nhan) va lenh chay lai duoc', function () {
    $dir = mpSetup();
    mpAsset($dir);
    Cache::lock('videos:migrate-provider', 600)->get(); // mô phỏng tiến trình bị kill, khoá kẹt
    Http::fake();
    expect(mpRun()[0])->toBe(1);

    $this->artisan('videos:migrate-provider', ['--unlock' => true])->expectsConfirmation('Nhả khoá videos:migrate-provider? Chỉ làm khi CHẮC CHẮN không có tiến trình chuyển nào đang chạy (hai tiến trình cùng chạy có thể tạo video trùng ở Bunny).', 'no')->assertExitCode(0);
    expect(mpRun()[0])->toBe(1); // vẫn kẹt

    expect(mpRun(['--unlock' => true, '--force' => true])[0])->toBe(0);
    bzFakeApi(mpRoutes());
    expect(mpRun()[0])->toBe(0);
});

test('R1: tien trinh khac mo so trong luc ta tao video -> khong co hai so do, xoa video vua tao', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    $other = 'cccccccc-bbbb-cccc-dddd-eeeeeeeeeeee';
    $routes = mpRoutes() + mpRoutes(guid: $other);
    $routes['POST '.bzVideosPath()] = function () use ($asset, $other) {
        VideoProviderMigration::factory()->create([
            'video_asset_id' => $asset->id, 'from_video_id' => $asset->provider_video_id, 'to_video_id' => $other,
        ]);

        return Http::response(bzVideoBody(MP_NEW_GUID), 200);
    };
    bzFakeApi($routes);

    mpRun();

    expect(VideoProviderMigration::query()->where('video_asset_id', $asset->id)->whereIn('status', ['pending', 'uploaded'])->count())->toBeLessThanOrEqual(1)
        ->and($asset->fresh()->provider_video_id)->toBe($other);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), MP_NEW_GUID));
});

test('R2: ngat sau PUT roi chay lai -> khong PUT lai, ghi nhan khi Bunny ready', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    VideoProviderMigration::factory()->create([
        'video_asset_id' => $asset->id, 'from_video_id' => $asset->provider_video_id, 'to_video_id' => MP_NEW_GUID, 'to_library_id' => BZ_LIB,
    ]); // pending: PUT đã xong ở Bunny nhưng sổ chưa kịp ghi
    bzFakeApi(mpRoutes(remoteStatus: 4));
    $GLOBALS['mp_put_done'] = true;

    [$code] = mpRun();

    expect($code)->toBe(0)->and($asset->fresh()->provider_video_id)->toBe(MP_NEW_GUID);
    Http::assertNotSent(fn (HttpRequest $r) => in_array($r->method(), ['POST', 'PUT'], true));
});

test('R2: video Bunny con o trang thai created thi van PUT', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    VideoProviderMigration::factory()->create([
        'video_asset_id' => $asset->id, 'from_video_id' => $asset->provider_video_id, 'to_video_id' => MP_NEW_GUID, 'to_library_id' => BZ_LIB,
    ]);
    $routes = mpRoutes();
    $state = 0;
    $routes['GET '.bzVideosPath('/'.MP_NEW_GUID)] = function () use (&$state) {
        return Http::response(bzVideoBody(MP_NEW_GUID, $state++ === 0 ? 0 : 4, 30), 200);
    };
    bzFakeApi($routes);

    expect(mpRun()[0])->toBe(0);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT');
});

test('R3: --rollback tra asset ve VideoLab, giu video Bunny; khong the sau --delete-source', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    $guid = $asset->provider_video_id;
    $routes = mpRoutes() + ['GET /videolab/library/default/videos/'.$guid => Http::response(['guid' => $guid, 'status' => 4, 'length' => 61], 200)];
    bzFakeApi($routes);
    mpRun();
    expect($asset->fresh()->provider)->toBe('bunny');

    expect(mpRun(['--rollback' => true])[0])->toBe(1); // thiếu --asset

    [$code, $out] = mpRun(['--rollback' => true, '--asset' => (string) $asset->id]);

    expect($code)->toBe(0)->and($out)->toContain(MP_NEW_GUID);
    $fresh = $asset->fresh();
    expect($fresh->provider)->toBe('internal')->and($fresh->provider_library_id)->toBe('default')->and($fresh->provider_video_id)->toBe($guid)
        ->and($fresh->duration_seconds)->toBe(61)->and(VideoProviderMigration::query()->sole()->status)->toBe('rolled_back');
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), MP_NEW_GUID));

    // chuyển lại rồi xoá nguồn: không hoàn tác được nữa
    $again = 'dddddddd-bbbb-cccc-dddd-eeeeeeeeeeee';
    bzFakeApi(mpRoutes(guid: $again) + ['DELETE /videolab/library/default/videos/'.$guid => Http::response([], 200)]);
    mpRun(['--delete-source' => true, '--force' => true]);
    [, $out2] = mpRun(['--rollback' => true, '--asset' => (string) $asset->id]);
    expect($out2)->toContain('không thể hoàn tác')->and($asset->fresh()->provider)->toBe('bunny');
});

test('R4: --wait sai gia tri bi tu choi; R6: chuyen xong cap nhat updated_at cua bai', function () {
    $dir = mpSetup();
    $asset = mpAsset($dir);
    DB::table('lessons')->where('id', $asset->lesson_id)->update(['updated_at' => now()->subDay()]);
    bzFakeApi(mpRoutes());

    expect(mpRun(['--wait' => 'abc'])[0])->toBe(1)->and(mpRun(['--wait' => '-5'])[0])->toBe(1);
    Http::assertNothingSent();

    expect(mpRun(['--wait' => '0'])[0])->toBe(0)
        ->and(DB::table('lessons')->where('id', $asset->lesson_id)->value('updated_at'))->toBeGreaterThan(now()->subHour()->toDateTimeString());
});
