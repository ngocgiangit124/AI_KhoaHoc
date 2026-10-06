<?php

use App\VideoLab\Exceptions\VideoRejectedException;
use App\VideoLab\Jobs\TranscodeVideoJob;
use App\VideoLab\Models\Video;
use App\VideoLab\Services\MediaToolkit;
use App\VideoLab\Services\TranscodeService;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->vlDir = vlUseTempStorage();
    Queue::fake();
    $this->toolkit = new FakeMediaToolkit;
    app()->instance(MediaToolkit::class, $this->toolkit);
});

afterEach(fn () => vlCleanup($this->vlDir));

test('QA file doc: mov co dref tro toi /etc/passwd qua magic bytes nhung lenh ffmpeg khoa protocol_whitelist=file + demuxer mov', function () {
    // Magic bytes chi chan loai file; chan dref/URL la viec cua whitelist (da kiem that o e2e worker-video).
    $mov = "\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00qt  ".str_pad('', 64, "\x00").'dref/etc/passwd';
    $guid = vlUploadWhole($mov);

    expect(vlVideo($guid)->status)->toBe(Video::UPLOADED);
    Queue::assertPushed(TranscodeVideoJob::class);

    $cmd = implode(' ', app(MediaToolkit::class)->encodeCommand('/s/x.bin', 'mov', '/o', 360, 800, 96, true));
    expect($cmd)->toContain('-protocol_whitelist file')->and($cmd)->toContain('-f mov');
});

test('QA file hong: ftyp dung nhung ruot hong -> ffprobe tu choi -> status 5, khong co HLS, khong retry', function () {
    $guid = vlUploadWhole(vlFakeMp4(300));
    $this->toolkit->probeError = new VideoRejectedException('Tệp video hỏng.');

    app(TranscodeService::class)->run($guid);

    expect(vlVideo($guid)->status)->toBe(Video::ERROR)
        ->and(is_dir(app(VideoLabStorage::class)->hlsDir($guid)))->toBeFalse();
});

test('QA R5: xoa video luc dang transcode -> khong de lai thu muc HLS mo coi', function () {
    $guid = vlUploadWhole(vlFakeMp4(300));

    // Giả lập admin xoá video ngay khi worker đang encode bậc đầu tiên.
    $deleting = new class extends FakeMediaToolkit
    {
        public string $guid = '';

        public function encodeRendition(string $source, string $format, string $outDir, int $height, int $videoKbps, int $audioKbps, bool $hasAudio): void
        {
            parent::encodeRendition($source, $format, $outDir, $height, $videoKbps, $audioKbps, $hasAudio);
            Video::query()->where('guid', $this->guid)->delete();
        }
    };
    $deleting->guid = $guid;
    app()->instance(MediaToolkit::class, $deleting);

    app(TranscodeService::class)->run($guid);

    expect(Video::query()->where('guid', $guid)->exists())->toBeFalse()
        ->and(is_dir(app(VideoLabStorage::class)->hlsDir($guid)))->toBeFalse(); // BUG nếu còn thư mục
});

test('QA TUS resume: ngat giua chung (ghi dang do) roi HEAD -> tiep tuc tu offset da commit', function () {
    $content = vlFakeMp4(300);
    $guid = vlCreateVideo();
    vlTusCreate($guid, 300)->assertCreated();
    vlTusPatch($guid, 0, substr($content, 0, 120))->assertNoContent();

    // client mất kết nối: gửi lại PATCH cũ với offset cũ -> 409, HEAD cho biết offset thật
    vlTusPatch($guid, 0, substr($content, 0, 120))->assertStatus(409);
    $offset = (int) vlTusHead($guid)->headers->get('Upload-Offset');
    expect($offset)->toBe(120);

    vlTusPatch($guid, $offset, substr($content, $offset))->assertNoContent();
    expect(vlVideo($guid)->status)->toBe(Video::UPLOADED)
        ->and(file_get_contents($this->vlDir.'/source/'.$guid.'.bin'))->toBe($content);
});

test('QA chunk > chunk_max_mb -> 413, offset khong doi', function () {
    config(['videolab.chunk_max_mb' => 1]);
    $guid = vlCreateVideo();
    $total = 3 * 1024 * 1024;
    vlTusCreate($guid, $total)->assertCreated();

    vlTusPatch($guid, 0, str_repeat('a', 1024 * 1024 + 1))->assertStatus(413);
    expect(vlVideo($guid)->upload_offset)->toBe(0);
});
