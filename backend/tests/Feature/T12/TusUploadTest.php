<?php

use App\VideoLab\Jobs\SendVideoLabWebhookJob;
use App\VideoLab\Jobs\TranscodeVideoJob;
use App\VideoLab\Models\Video;
use App\VideoLab\Services\TusUploadService;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->vlDir = vlUseTempStorage();
    Queue::fake();
});

afterEach(fn () => vlCleanup($this->vlDir));

test('tus: tao phien, HEAD, PATCH nhieu chunk, hoan tat -> status 1 + job transcode (queue video)', function () {
    $content = vlFakeMp4(300);
    $guid = vlCreateVideo();

    $create = vlTusCreate($guid, 300);
    $create->assertCreated()->assertHeader('Tus-Resumable', '1.0.0');
    expect($create->headers->get('Location'))->toEndWith('/videolab/tus/'.$guid);

    vlTusHead($guid)->assertOk()->assertHeader('Upload-Offset', '0')->assertHeader('Upload-Length', '300');

    vlTusPatch($guid, 0, substr($content, 0, 100))->assertNoContent()->assertHeader('Upload-Offset', '100');
    vlTusHead($guid)->assertHeader('Upload-Offset', '100');
    expect(vlVideo($guid)->status)->toBe(Video::CREATED);

    vlTusPatch($guid, 100, substr($content, 100))->assertNoContent()->assertHeader('Upload-Offset', '300');

    $video = vlVideo($guid);
    expect($video->status)->toBe(Video::UPLOADED)->and($video->upload_offset)->toBe(300);
    expect(file_get_contents($this->vlDir.'/source/'.$guid.'.bin'))->toBe($content)
        ->and(file_exists($this->vlDir.'/incoming/'.$guid.'.part'))->toBeFalse();

    Queue::assertPushed(TranscodeVideoJob::class, fn ($j) => $j->guid === $guid && $j->queue === 'video' && $j->tries === 2 && $j->timeout === 3600);
});

test('tus: file .mp4 thuc chat la #EXTM3U bi tu choi TRUOC ffprobe, status 6, khong dispatch transcode', function () {
    $body = "#EXTM3U\n#EXT-X-VERSION:3\nhttp://evil/x.ts\n";
    $guid = vlCreateVideo();
    vlTusCreate($guid, strlen($body))->assertCreated();

    vlTusPatch($guid, 0, $body)->assertStatus(422);

    $video = vlVideo($guid);
    expect($video->status)->toBe(Video::UPLOAD_FAILED)->and($video->error)->not->toBeEmpty();
    expect(file_exists($this->vlDir.'/incoming/'.$guid.'.part'))->toBeFalse()
        ->and(file_exists($this->vlDir.'/source/'.$guid.'.bin'))->toBeFalse();
    Queue::assertNotPushed(TranscodeVideoJob::class);
    Queue::assertPushed(SendVideoLabWebhookJob::class);
});

test('tus: matroska/webm magic bytes duoc nhan', function () {
    $content = "\x1A\x45\xDF\xA3".str_repeat('x', 60);
    $guid = vlUploadWhole($content);

    expect(vlVideo($guid)->status)->toBe(Video::UPLOADED);
});

test('tus: ffconcat/anh/van ban bi tu choi', function (string $body) {
    $guid = vlUploadWhole($body);
    expect(vlVideo($guid)->status)->toBe(Video::UPLOAD_FAILED);
    Queue::assertNotPushed(TranscodeVideoJob::class);
})->with(['ffconcat' => "ffconcat version 1.0\nfile '/etc/passwd'\n", 'png' => "\x89PNG\r\n\x1a\n".'xxxxxxxx', 'text' => 'hello world, not a video']);

test('tus: PATCH vuot Upload-Length -> 413 va file khoi phuc ve offset cu', function () {
    $guid = vlCreateVideo();
    vlTusCreate($guid, 100)->assertCreated();
    vlTusPatch($guid, 0, vlFakeMp4(40))->assertNoContent();

    vlTusPatch($guid, 40, str_repeat('a', 80))->assertStatus(413);

    expect(vlVideo($guid)->upload_offset)->toBe(40)->and(filesize($this->vlDir.'/incoming/'.$guid.'.part'))->toBe(40);
    vlTusPatch($guid, 40, str_repeat('a', 60))->assertNoContent(); // vẫn resume được
});

test('tus: Content-Length khai vuot Upload-Length -> 413', function () {
    $guid = vlCreateVideo();
    vlTusCreate($guid, 100)->assertCreated();

    vlTusPatch($guid, 0, str_repeat('a', 10), ['Content-Length' => '500'])->assertStatus(413);
});

test('tus: chunk lon hon chunk_max_mb -> 413', function () {
    config(['videolab.chunk_max_mb' => 1]);
    $guid = vlCreateVideo();
    vlTusCreate($guid, 5 * 1024 * 1024)->assertCreated();

    vlTusPatch($guid, 0, vlFakeMp4(1024 * 1024 + 10))->assertStatus(413);
    expect(vlVideo($guid)->upload_offset)->toBe(0);
});

test('tus: Upload-Length > max_bytes -> 413, khong tao phien', function () {
    $guid = vlCreateVideo(['max_bytes' => 1000]);

    vlTusCreate($guid, 1001)->assertStatus(413);
    expect(vlVideo($guid)->upload_length)->toBeNull();

    $big = vlCreateVideo();
    vlTusCreate($big, (config('video.max_upload_mb') * 1024 * 1024) + 1)->assertStatus(413);
});

test('tus: max_bytes khong the vuot video.max_upload_mb du client yeu cau', function () {
    $guid = vlCreateVideo(['max_bytes' => PHP_INT_MAX]);

    expect(vlVideo($guid)->max_bytes)->toBe((int) config('video.max_upload_mb') * 1024 * 1024);
});

test('tus: 1 upload/guid - tao lan 2 -> 403', function () {
    $guid = vlCreateVideo();
    vlTusCreate($guid, 100)->assertCreated();
    vlTusCreate($guid, 100)->assertForbidden();
});

test('tus: upload lai vao guid da finished/dang xu ly -> 403', function () {
    $guid = vlUploadWhole(vlFakeMp4(100));
    expect(vlVideo($guid)->status)->toBe(Video::UPLOADED);
    vlTusPatch($guid, 100, 'x')->assertForbidden();
    vlTusCreate($guid, 100)->assertForbidden();

    $done = vlFinishedVideo();
    vlTusCreate($done, 100)->assertForbidden();
    vlTusPatch($done, 0, 'x')->assertForbidden();
});

test('tus: chu ky het han -> 403', function () {
    $guid = vlCreateVideo();
    $headers = vlTusHeaders($guid, time() - 10, null, ['Upload-Length' => '100']);

    test()->call('POST', vlUrl('/tus'), [], [], [], vlServer($headers))->assertForbidden();
    expect(vlVideo($guid)->upload_length)->toBeNull();
});

test('tus: phien upload (upload_expires_at) het han -> 403 du chu ky con han', function () {
    $guid = vlCreateVideo();
    Video::query()->where('guid', $guid)->update(['upload_expires_at' => now()->subMinute()]);

    vlTusCreate($guid, 100)->assertForbidden();
});

test('tus: chu ky sai/thieu/khac guid/khac library -> 403', function () {
    $guid = vlCreateVideo();
    $other = vlCreateVideo();

    // chữ ký sai
    vlTusCreate($guid, 100, ['AuthorizationSignature' => str_repeat('a', 64)])->assertForbidden();
    // thiếu chữ ký
    vlTusCreate($guid, 100, ['AuthorizationSignature' => ''])->assertForbidden();
    // chữ ký của video khác dùng cho guid này (VideoId header đổi → chữ ký không khớp)
    vlTusCreate($guid, 100, ['VideoId' => $other])->assertForbidden();
    // library sai
    $r = test()->call('POST', vlUrl('/tus'), [], [], [], vlServer(vlTusHeaders($guid, null, 'other-lib', ['Upload-Length' => '100'])));
    $r->assertForbidden();

    // route guid khác VideoId header
    vlTusCreate($guid, 100)->assertCreated();
    test()->call('PATCH', vlUrl('/tus/'.$other), [], [], [], vlServer(vlTusHeaders($guid, null, null, ['Upload-Offset' => '0', 'Content-Type' => 'application/offset+octet-stream'])), 'x')->assertForbidden();
    expect(vlVideo($guid)->upload_offset)->toBe(0);
});

test('tus: Upload-Offset sai -> 409; Content-Type sai -> 415; thieu Tus-Resumable -> 412', function () {
    $guid = vlCreateVideo();
    vlTusCreate($guid, 100)->assertCreated();

    vlTusPatch($guid, 5, 'abc')->assertStatus(409);
    vlTusPatch($guid, 0, 'abc', ['Content-Type' => 'application/json'])->assertStatus(415);
    vlTusPatch($guid, 0, 'abc', ['Tus-Resumable' => ''])->assertStatus(412)->assertHeader('Tus-Version', '1.0.0');
});

test('tus: Upload-Length khong hop le/Defer -> 400', function () {
    $guid = vlCreateVideo();

    vlTusCreate($guid, 0)->assertStatus(400);
    vlTusCreate($guid, 10, ['Upload-Length' => '-5'])->assertStatus(400);
    vlTusCreate($guid, 10, ['Upload-Defer-Length' => '1'])->assertStatus(400);
});

test('tus: ten file chi de hien thi, da lam sach, khong dung lam duong dan', function () {
    $guid = vlCreateVideo();
    $meta = 'filename '.base64_encode("../../etc/passwd\x00.mp4");
    vlTusCreate($guid, 100, ['Upload-Metadata' => $meta])->assertCreated();

    expect(vlVideo($guid)->original_name)->toBe('passwd.mp4');
    expect(array_diff(scandir($this->vlDir.'/incoming'), ['.', '..']))->toBe([2 => $guid.'.part']);
});

test('tus: OPTIONS tra capability; preflight CORS chi cho ADMIN_URL', function () {
    test()->call('OPTIONS', vlUrl('/tus'))->assertNoContent()->assertHeader('Tus-Extension', 'creation')->assertHeader('Tus-Version', '1.0.0');

    $admin = (string) config('app.admin_url');
    $ok = test()->call('OPTIONS', vlUrl('/tus/'.vlCreateVideo()), [], [], [], vlServer([
        'Origin' => $admin, 'Access-Control-Request-Method' => 'PATCH',
    ]));
    $ok->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $admin);
    expect($ok->headers->get('Access-Control-Allow-Headers'))->toContain('AuthorizationSignature', 'Upload-Offset')
        ->and($ok->headers->get('Access-Control-Allow-Credentials'))->toBeNull();

    test()->call('OPTIONS', vlUrl('/tus'), [], [], [], vlServer([
        'Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST',
    ]))->assertForbidden();

    // học sinh (FRONTEND_URL) không được upload
    test()->call('OPTIONS', vlUrl('/tus'), [], [], [], vlServer([
        'Origin' => (string) config('app.frontend_url'), 'Access-Control-Request-Method' => 'POST',
    ]))->assertForbidden();
});

test('tus: host khac khong toi duoc route videolab', function () {
    test()->call('POST', 'http://'.config('app.api_host').'/videolab/tus')->assertNotFound();
});

test('tus: ghi thieu (dia day) -> 507, file khoi phuc ve offset cu, resume duoc', function () {
    // Controller được route giữ lại sau request đầu: phải bind bản thay thế TRƯỚC request đầu tiên.
    $tus = new class(app(VideoLabStorage::class)) extends TusUploadService
    {
        public bool $failing = false;

        protected function writeChunk($handle, string $buffer): int|false
        {
            return fwrite($handle, $this->failing ? substr($buffer, 0, 10) : $buffer);
        }
    };
    app()->instance(TusUploadService::class, $tus);

    $guid = vlCreateVideo();
    vlTusCreate($guid, 200)->assertCreated();
    vlTusPatch($guid, 0, vlFakeMp4(50))->assertNoContent();

    $tus->failing = true;
    vlTusPatch($guid, 50, str_repeat('a', 100))->assertStatus(507);
    expect(vlVideo($guid)->upload_offset)->toBe(50)->and(filesize($this->vlDir.'/incoming/'.$guid.'.part'))->toBe(50);

    $tus->failing = false;
    vlTusPatch($guid, 50, str_repeat('a', 150))->assertNoContent();
    expect(vlVideo($guid)->status)->toBe(Video::UPLOADED);
});
