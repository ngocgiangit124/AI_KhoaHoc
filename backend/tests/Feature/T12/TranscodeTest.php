<?php

use App\VideoLab\Exceptions\VideoRejectedException;
use App\VideoLab\Jobs\SendVideoLabWebhookJob;
use App\VideoLab\Jobs\TranscodeVideoJob;
use App\VideoLab\Models\Video;
use App\VideoLab\Services\MediaToolkit;
use App\VideoLab\Services\TranscodeService;
use App\VideoLab\Support\MagicBytes;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->vlDir = vlUseTempStorage();
    Queue::fake();
    $this->toolkit = new FakeMediaToolkit;
    app()->instance(MediaToolkit::class, $this->toolkit);
    config(['videolab.job.connection' => 'sync']);
});

afterEach(fn () => vlCleanup($this->vlDir));

/** Video đã upload xong (status 1) với file nguồn. */
function vlUploaded(?string $content = null): string
{
    $guid = vlUploadWhole($content ?? vlFakeMp4(200));
    Queue::assertPushed(TranscodeVideoJob::class);

    return $guid;
}

test('transcode: thanh cong -> HLS 360p+720p, master playlist, status 4, do dai, webhook', function () {
    $guid = vlUploaded();

    (new TranscodeVideoJob($guid))->handle(app(TranscodeService::class));

    $video = vlVideo($guid);
    $dir = app(VideoLabStorage::class)->hlsDir($guid);
    expect($video->status)->toBe(Video::FINISHED)->and($video->length_seconds)->toBe(125)->and($video->finished_at)->not->toBeNull()
        ->and(array_column($video->renditions, 'height'))->toBe([360, 720]);
    expect(file_get_contents($dir.'/playlist.m3u8'))->toContain('RESOLUTION=1280x720', '360p/index.m3u8', '720p/index.m3u8')
        ->and(is_file($dir.'/360p/seg_00000.ts'))->toBeTrue()
        ->and(is_dir(app(VideoLabStorage::class)->hlsWorkDir($guid)))->toBeFalse();
    // file gốc vẫn còn (xoá bởi videolab:cleanup sau 7 ngày) và nằm ngoài thư mục hls
    expect(is_file($this->vlDir.'/source/'.$guid.'.bin'))->toBeTrue();
    // Cụm 4 M1: worker không dispatch vào queue của app; `videolab:notify` (scheduler) báo sau.
    Queue::assertNotPushed(SendVideoLabWebhookJob::class);
    expect($video->notified_at)->toBeNull();
});

test('transcode: nguon thap hon 720p chi co bac 360; nguon rat nho van co 1 bac', function () {
    $this->toolkit->info = ['duration' => 30.0, 'width' => 640, 'height' => 480, 'has_audio' => false];
    $guid = vlUploaded();
    (new TranscodeVideoJob($guid))->handle(app(TranscodeService::class));
    expect(array_column(vlVideo($guid)->renditions, 'height'))->toBe([360])->and(vlVideo($guid)->renditions[0]['width'])->toBe(480);

    $this->toolkit->info = ['duration' => 30.0, 'width' => 320, 'height' => 240, 'has_audio' => true];
    $small = vlUploaded();
    (new TranscodeVideoJob($small))->handle(app(TranscodeService::class));
    // Cụm 2 L2: nguồn nhỏ hơn bậc thấp nhất KHÔNG bị phóng to lên 360p, giữ chiều cao gốc.
    expect(array_column(vlVideo($small)->renditions, 'height'))->toBe([240])->and(vlVideo($small)->renditions[0]['width'])->toBe(320);

    $this->toolkit->info = ['duration' => 30.0, 'width' => 160, 'height' => 120, 'has_audio' => true];
    $tiny = vlUploaded();
    (new TranscodeVideoJob($tiny))->handle(app(TranscodeService::class));
    expect(array_column(vlVideo($tiny)->renditions, 'height'))->toBe([120])->and(vlVideo($tiny)->renditions[0]['path'])->toBe('120p/index.m3u8');
});

test('transcode: bi tu choi (ffprobe/gioi han) -> status 5, KHONG nem (khong retry), webhook, khong co HLS', function () {
    $this->toolkit->probeError = new VideoRejectedException('Video dài quá 180 phút.');
    $guid = vlUploaded();

    (new TranscodeVideoJob($guid))->handle(app(TranscodeService::class));

    $video = vlVideo($guid);
    expect($video->status)->toBe(Video::ERROR)->and($video->error)->toBe('Video dài quá 180 phút.')
        ->and($this->toolkit->encodeCalls)->toBe(0)->and(is_dir(app(VideoLabStorage::class)->hlsDir($guid)))->toBeFalse();
    Queue::assertNotPushed(SendVideoLabWebhookJob::class);
});

test('transcode: magic bytes duoc kiem lai truoc ffprobe (file nguon bi thay bang #EXTM3U)', function () {
    $guid = vlUploaded();
    file_put_contents($this->vlDir.'/source/'.$guid.'.bin', "#EXTM3U\n#EXT-X-VERSION:3\n");

    (new TranscodeVideoJob($guid))->handle(app(TranscodeService::class));

    expect(vlVideo($guid)->status)->toBe(Video::ERROR)->and($this->toolkit->probeCalls)->toBe(0);
});

test('transcode: loi ha tang (ffmpeg crash) nem lai de queue retry, khong de lai HLS do; failed() danh dau loi', function () {
    $this->toolkit->failEncode = true;
    $guid = vlUploaded();
    $service = app(TranscodeService::class);

    expect(fn () => (new TranscodeVideoJob($guid))->handle($service))->toThrow(RuntimeException::class);
    expect(is_dir(app(VideoLabStorage::class)->hlsDir($guid)))->toBeFalse()->and(is_dir(app(VideoLabStorage::class)->hlsWorkDir($guid)))->toBeFalse();
    expect(vlVideo($guid)->status)->toBe(Video::TRANSCODING);

    // chạy lại thành công (idempotent: output dở đã bị dọn)
    $this->toolkit->failEncode = false;
    (new TranscodeVideoJob($guid))->handle($service);
    expect(vlVideo($guid)->status)->toBe(Video::FINISHED);

    // hết lượt retry: failed() đánh dấu lỗi nhưng KHÔNG ghi đè video đã xong
    (new TranscodeVideoJob($guid))->failed(new RuntimeException('x'));
    expect(vlVideo($guid)->status)->toBe(Video::FINISHED);

    $this->toolkit->failEncode = true;
    $g2 = vlUploaded();
    expect(fn () => (new TranscodeVideoJob($g2))->handle($service))->toThrow(RuntimeException::class);
    (new TranscodeVideoJob($g2))->failed(new RuntimeException('x'));
    expect(vlVideo($g2)->status)->toBe(Video::ERROR)->and(vlVideo($g2)->error)->toBe('Xử lý video thất bại.');
});

test('transcode: chay lai tren video da xong/da loi la no-op', function () {
    $guid = vlUploaded();
    $service = app(TranscodeService::class);
    $service->run($guid);
    $calls = $this->toolkit->encodeCalls;

    $service->run($guid);
    $service->run('00000000-0000-0000-0000-000000000000');

    expect($this->toolkit->encodeCalls)->toBe($calls);
});

test('job: cau hinh queue video, timeout 3600, tries 2; retry_after connection > timeout', function () {
    $job = new TranscodeVideoJob('x');
    expect($job->queue)->toBe('video')->and($job->timeout)->toBe(3600)->and($job->tries)->toBe(2)->and($job->failOnTimeout)->toBeTrue();
    expect(config('queue.connections.redis_video.retry_after'))->toBeGreaterThan(3600)
        ->and(config('queue.connections.redis_video.queue'))->toBe('video');
});

test('toolkit: lenh ffprobe/ffmpeg la MANG tham so voi protocol_whitelist file va khoa demuxer (ADR-002 3a.2)', function () {
    $toolkit = new MediaToolkit;

    $probe = $toolkit->probeCommand('/s/x.bin', MagicBytes::MOV);
    expect($probe)->toBeArray()->and($probe)->toContain('-protocol_whitelist', 'file', '-format_whitelist', MediaToolkit::FORMAT_WHITELIST, '-show_streams');
    expect($probe[array_search('-f', $probe, true) + 1])->toBe('mov')->and(end($probe))->toBe('/s/x.bin');

    $mkv = $toolkit->probeCommand('/s/x.bin', MagicBytes::MATROSKA);
    expect($mkv[array_search('-f', $mkv, true) + 1])->toBe('matroska');

    $enc = $toolkit->encodeCommand('/s/x.bin', MagicBytes::MOV, '/o/360p', 360, 800, 96, true);
    expect($enc)->toContain('-protocol_whitelist', 'file', '-nostdin', '-hls_segment_filename', '-sn', '-dn');
    expect($enc[array_search('-protocol_whitelist', $enc, true) + 1])->toBe('file')
        ->and($enc[array_search('-i', $enc, true) - 2])->toBe('-f')
        ->and($enc[array_search('-i', $enc, true) + 1])->toBe('/s/x.bin')
        ->and(end($enc))->toBe('/o/360p/index.m3u8');

    // đường dẫn chứa ký tự shell chỉ là 1 phần tử, không bị diễn giải
    $evil = $toolkit->probeCommand('/s/a; rm -rf /.bin', MagicBytes::MOV);
    expect(end($evil))->toBe('/s/a; rm -rf /.bin');

    expect($toolkit->encodeCommand('/s/x.bin', MagicBytes::MOV, '/o', 360, 800, 96, false))->not->toContain('-c:a');
});

test('toolkit: probe tu choi dinh dang/khong co video/qua dai/qua lon (du lieu ffprobe)', function () {
    $validate = fn (array $data) => (new ReflectionMethod(MediaToolkit::class, 'validateProbe'))->invoke(new MediaToolkit, $data);

    $ok = ['format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2', 'duration' => '60.0'], 'streams' => [['codec_type' => 'video', 'width' => 1280, 'height' => 720], ['codec_type' => 'audio']]];
    expect($validate($ok))->toMatchArray(['width' => 1280, 'height' => 720, 'has_audio' => true]);
    expect($validate(['format' => $ok['format'], 'streams' => [['codec_type' => 'video', 'width' => 160, 'height' => 120]]]))->toMatchArray(['width' => 160, 'height' => 120]);

    $cases = [
        'format la' => array_replace_recursive($ok, ['format' => ['format_name' => 'hls,applehttp']]),
        'concat' => array_replace_recursive($ok, ['format' => ['format_name' => 'concat']]),
        'khong video' => ['format' => $ok['format'], 'streams' => [['codec_type' => 'audio']]],
        'chi anh bia' => ['format' => $ok['format'], 'streams' => [['codec_type' => 'video', 'width' => 100, 'height' => 100, 'disposition' => ['attached_pic' => 1]]]],
        'qua dai' => array_replace_recursive($ok, ['format' => ['duration' => (string) (181 * 60)]]),
        'khong thoi luong' => array_replace_recursive($ok, ['format' => ['duration' => '0']]),
        'dai 3840x100' => ['format' => $ok['format'], 'streams' => [['codec_type' => 'video', 'width' => 3840, 'height' => 100]]],
        'cao 100x1000' => ['format' => $ok['format'], 'streams' => [['codec_type' => 'video', 'width' => 100, 'height' => 1000]]],
        'qua nho 64x48' => ['format' => $ok['format'], 'streams' => [['codec_type' => 'video', 'width' => 64, 'height' => 48]]],
        'qua 4k' => ['format' => $ok['format'], 'streams' => [['codec_type' => 'video', 'width' => 7680, 'height' => 4320]]],
    ];

    foreach ($cases as $name => $data) {
        expect(fn () => $validate($data))->toThrow(VideoRejectedException::class, '', "case {$name}");
    }
});

test('ffmpeg THAT (bo qua neu may khong co ffmpeg): mp4 sinh bang lavfi -> HLS phat duoc; #EXTM3U doi mp4 bi chan', function () {
    $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
    if ($ffmpeg === '' || trim((string) shell_exec('command -v ffprobe 2>/dev/null')) === '') {
        $this->markTestSkipped('Không có ffmpeg/ffprobe (chạy trong image worker-video).');
    }

    app()->forgetInstance(MediaToolkit::class);
    app()->bind(MediaToolkit::class);
    config(['videolab.ffmpeg.renditions' => [360 => [800, 96]], 'videolab.ffmpeg.encode_timeout' => 120]);

    $sample = $this->vlDir.'/sample.mp4';
    exec(escapeshellarg($ffmpeg).' -v error -f lavfi -i testsrc=duration=3:size=640x480:rate=24 -f lavfi -i sine=duration=3 -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest '.escapeshellarg($sample).' 2>&1', $out, $code);
    expect($code)->toBe(0);

    $guid = vlUploaded(file_get_contents($sample));
    (new TranscodeVideoJob($guid))->handle(app(TranscodeService::class));

    $video = vlVideo($guid);
    expect($video->status)->toBe(Video::FINISHED)->and($video->length_seconds)->toBeBetween(2, 4);
    $dir = app(VideoLabStorage::class)->hlsDir($guid);
    expect(file_get_contents($dir.'/360p/index.m3u8'))->toContain('#EXT-X-ENDLIST', 'seg_00000.ts')->and(filesize($dir.'/360p/seg_00000.ts'))->toBeGreaterThan(1000);

    // file ftyp giả nhưng không phải media thật: magic bytes qua, ffprobe từ chối → status 5
    $fake = vlUploaded(vlFakeMp4(500));
    (new TranscodeVideoJob($fake))->handle(app(TranscodeService::class));
    expect(vlVideo($fake)->status)->toBe(Video::ERROR);
});

test('toolkit: Process cua ffmpeg/ffprobe co env sach (khong ke thua mat khau DB/Redis)', function () {
    // Lưu giá trị gốc để khôi phục: CI nạp DB_PASSWORD/REDIS_PASSWORD qua biến môi trường thật,
    // gỡ hẳn sẽ làm các test đọc lại config sau đó kết nối với mật khẩu rỗng.
    $old = [getenv('DB_PASSWORD'), getenv('REDIS_PASSWORD'), $_SERVER['DB_PASSWORD'] ?? null];
    putenv('DB_PASSWORD=sieu-bi-mat');
    putenv('REDIS_PASSWORD=sieu-bi-mat-2');
    $_SERVER['DB_PASSWORD'] = 'sieu-bi-mat';

    try {
        $env = (new MediaToolkit)->cleanEnv();

        expect($env['PATH'])->toBe('/usr/local/bin:/usr/bin:/bin')->and($env['HOME'])->toBe('/tmp')
            ->and($env['DB_PASSWORD'])->toBeFalse()->and($env['REDIS_PASSWORD'])->toBeFalse();

        $p = new Process(['sh', '-c', 'echo "[$DB_PASSWORD][$REDIS_PASSWORD][$HOME]"'], null, $env);
        $p->run();
        expect(trim($p->getOutput()))->toBe('[][][/tmp]');
    } finally {
        $old[0] === false ? putenv('DB_PASSWORD') : putenv('DB_PASSWORD='.$old[0]);
        $old[1] === false ? putenv('REDIS_PASSWORD') : putenv('REDIS_PASSWORD='.$old[1]);
        if ($old[2] === null) {
            unset($_SERVER['DB_PASSWORD']);
        } else {
            $_SERVER['DB_PASSWORD'] = $old[2];
        }
    }
});

test('videolab:notify: dispatch webhook cho video xong/loi chua bao, danh dau notified_at, khong bao lai', function () {
    $done = Video::factory()->finished()->create();
    $failed = Video::factory()->create(['status' => Video::ERROR, 'error' => 'x']);
    $already = Video::factory()->finished()->create(['notified_at' => now()]);
    $uploading = Video::factory()->create(['status' => Video::TRANSCODING]);

    test()->artisan('videolab:notify')->assertSuccessful();

    Queue::assertPushed(SendVideoLabWebhookJob::class, 2);
    Queue::assertPushed(SendVideoLabWebhookJob::class, fn ($j) => $j->guid === $done->guid);
    Queue::assertPushed(SendVideoLabWebhookJob::class, fn ($j) => $j->guid === $failed->guid);
    expect($done->fresh()->notified_at)->not->toBeNull()
        ->and($failed->fresh()->notified_at)->not->toBeNull()
        ->and($already->fresh()->notified_at)->not->toBeNull()
        ->and($uploading->fresh()->notified_at)->toBeNull();

    test()->artisan('videolab:notify')->assertSuccessful();
    Queue::assertPushed(SendVideoLabWebhookJob::class, 2);
});

test('videolab:notify duoc dang ky trong scheduler moi phut', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'videolab:notify'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});
