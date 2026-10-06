<?php

use App\VideoLab\Models\Video;
use App\VideoLab\Support\VideoLabStorage;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => $this->vlDir = vlUseTempStorage());
afterEach(fn () => vlCleanup($this->vlDir));

test('quan ly: thieu/sai AccessKey -> 401', function () {
    $url = vlUrl('/library/'.vlLibrary().'/videos');

    test()->postJson($url, ['title' => 'x'])->assertUnauthorized();
    test()->postJson($url, ['title' => 'x'], ['AccessKey' => 'sai'])->assertUnauthorized();
    test()->getJson($url.'/'.fake()->uuid(), ['AccessKey' => ''])->assertUnauthorized();
    test()->deleteJson($url.'/'.fake()->uuid())->assertUnauthorized();
    expect(Video::count())->toBe(0);
});

test('quan ly: tao -> guid, show tra status kieu Bunny, delete don file va ban ghi', function () {
    $guid = vlCreateVideo(['title' => 'Bai 1']);

    $show = test()->getJson(vlUrl('/library/'.vlLibrary().'/videos/'.$guid), vlAccessHeaders())->assertOk();
    expect($show->json())->toMatchArray(['guid' => $guid, 'status' => 0, 'length' => 0, 'uploadStarted' => false, 'title' => 'Bai 1']);

    $storage = app(VideoLabStorage::class);
    $storage->ensureDirs();
    file_put_contents($storage->incoming($guid), 'x');
    mkdir($storage->hlsDir($guid), 0775, true);
    file_put_contents($storage->hlsDir($guid).'/playlist.m3u8', 'x');

    test()->deleteJson(vlUrl('/library/'.vlLibrary().'/videos/'.$guid), [], vlAccessHeaders())->assertNoContent();

    expect(Video::count())->toBe(0)->and(is_dir($storage->hlsDir($guid)))->toBeFalse()->and(file_exists($storage->incoming($guid)))->toBeFalse();
    test()->getJson(vlUrl('/library/'.vlLibrary().'/videos/'.$guid), vlAccessHeaders())->assertNotFound();
    test()->deleteJson(vlUrl('/library/'.vlLibrary().'/videos/'.$guid), [], vlAccessHeaders())->assertNotFound();
});

test('quan ly: library sai -> 404; title bat buoc; guid sai dinh dang -> 404', function () {
    test()->postJson(vlUrl('/library/khac/videos'), ['title' => 'x'], vlAccessHeaders())->assertNotFound();
    test()->postJson(vlUrl('/library/'.vlLibrary().'/videos'), [], vlAccessHeaders())->assertUnprocessable();
    test()->getJson(vlUrl('/library/'.vlLibrary().'/videos/..%2f..'), vlAccessHeaders())->assertNotFound();
    test()->getJson(vlUrl('/library/'.vlLibrary().'/videos/not-a-guid'), vlAccessHeaders())->assertNotFound();
});

test('quan ly: khong nhan mass-assignment (status/guid tu client bi bo qua)', function () {
    $r = test()->postJson(vlUrl('/library/'.vlLibrary().'/videos'), ['title' => 'x', 'status' => 4, 'guid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'], vlAccessHeaders())->assertCreated();

    expect($r->json('status'))->toBe(0)->and($r->json('guid'))->not->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
});

test('cleanup: upload do qua han -> status 6; file goc cu bi xoa', function () {
    $storage = app(VideoLabStorage::class);
    $storage->ensureDirs();

    $stale = vlCreateVideo();
    file_put_contents($storage->incoming($stale), 'x');
    Video::query()->where('guid', $stale)->update(['created_at' => now()->subHours(30), 'upload_expires_at' => now()->subHours(20)]);

    $fresh = vlCreateVideo();
    file_put_contents($storage->incoming($fresh), 'x');

    $old = vlFinishedVideo();
    file_put_contents($storage->source($old), 'SRC');
    Video::query()->where('guid', $old)->update(['finished_at' => now()->subDays(8), 'source_path' => 'source/x']);

    $recent = vlFinishedVideo();
    file_put_contents($storage->source($recent), 'SRC');
    Video::query()->where('guid', $recent)->update(['source_path' => 'source/x']);

    $this->artisan('videolab:cleanup')->assertSuccessful();

    expect(vlVideo($stale)->status)->toBe(Video::UPLOAD_FAILED)->and(file_exists($storage->incoming($stale)))->toBeFalse()
        ->and(vlVideo($fresh)->status)->toBe(Video::CREATED)->and(file_exists($storage->incoming($fresh)))->toBeTrue()
        ->and(file_exists($storage->source($old)))->toBeFalse()->and(file_exists($storage->source($recent)))->toBeTrue()
        // HLS của video đã xong không bị đụng
        ->and(is_dir($storage->hlsDir($old)))->toBeTrue();

    config(['videolab.storage.keep_source' => true]);
    file_put_contents($storage->source($old), 'SRC');
    Video::query()->where('guid', $old)->update(['source_path' => 'source/x']);
    $this->artisan('videolab:cleanup')->assertSuccessful();
    expect(file_exists($storage->source($old)))->toBeTrue();
});

test('L3 cum 2: cleanup xoa source cua video loi (status 5) sau an han, giu video loi moi va video dang xu ly; --dry-run khong xoa', function () {
    $storage = app(VideoLabStorage::class);
    $storage->ensureDirs();

    $mk = function (int $status, int $ageHours) use ($storage): string {
        $guid = vlCreateVideo();
        file_put_contents($storage->source($guid), 'SRC');
        Video::query()->where('guid', $guid)->update(['status' => $status, 'source_path' => 'source/x', 'updated_at' => now()->subHours($ageHours)]);

        return $guid;
    };

    $oldFailed = $mk(Video::ERROR, 30);
    $newFailed = $mk(Video::ERROR, 1);
    $oldProcessing = $mk(Video::TRANSCODING, 30);

    $this->artisan('videolab:cleanup --dry-run')->assertSuccessful();
    expect(file_exists($storage->source($oldFailed)))->toBeTrue();

    config(['videolab.storage.keep_source' => true]);
    $this->artisan('videolab:cleanup')->assertSuccessful();

    expect(file_exists($storage->source($oldFailed)))->toBeFalse()
        ->and(vlVideo($oldFailed)->source_path)->toBeNull()
        ->and(file_exists($storage->source($newFailed)))->toBeTrue()
        ->and(file_exists($storage->source($oldProcessing)))->toBeTrue();
});

test('cleanup: quet thu muc hls/source mo coi cu hon nguong, giu muc moi va muc con ban ghi; --dry-run khong xoa', function () {
    $storage = app(VideoLabStorage::class);
    $storage->ensureDirs();

    $mk = function (string $guid, bool $old) use ($storage): void {
        mkdir($storage->hlsDir($guid), 0775, true);
        mkdir($storage->hlsWorkDir($guid), 0775, true);
        file_put_contents($storage->source($guid), 'SRC');

        if ($old) {
            foreach ([$storage->hlsDir($guid), $storage->hlsWorkDir($guid), $storage->source($guid)] as $p) {
                touch($p, time() - 7200);
            }
        }
    };

    $oldOrphan = '11111111-1111-4111-8111-111111111111';
    $newOrphan = '22222222-2222-4222-8222-222222222222';
    $mk($oldOrphan, true);
    $mk($newOrphan, false);
    $kept = vlFinishedVideo();
    touch($storage->hlsDir($kept), time() - 7200);

    $this->artisan('videolab:cleanup --dry-run')->assertSuccessful();
    expect(is_dir($storage->hlsDir($oldOrphan)))->toBeTrue();

    $this->artisan('videolab:cleanup')->assertSuccessful();

    expect(is_dir($storage->hlsDir($oldOrphan)))->toBeFalse()
        ->and(is_dir($storage->hlsWorkDir($oldOrphan)))->toBeFalse()
        ->and(file_exists($storage->source($oldOrphan)))->toBeFalse()
        ->and(is_dir($storage->hlsDir($newOrphan)))->toBeTrue()
        ->and(file_exists($storage->source($newOrphan)))->toBeTrue()
        ->and(is_dir($storage->hlsDir($kept)))->toBeTrue();
});
