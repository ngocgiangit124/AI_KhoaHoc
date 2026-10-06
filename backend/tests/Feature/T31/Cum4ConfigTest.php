<?php

use App\Support\Heartbeat;
use App\Support\ProductionConfigGuard;
use App\VideoLab\Jobs\SendVideoLabWebhookJob;
use App\VideoLab\Jobs\TranscodeVideoJob;
use App\VideoLab\Models\Video;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Sửa lỗi "Bảo mật cụm 4" (docs/security/review-cum4-config.md): M1 (Redis ACL + session encrypt), M2 (chú thích cuối dòng
 * trong env), L1 (log level), L2 (/up + CSP), L4 (debug), L6 nằm ở tests/Arch.
 */
function c4Infra(string $path): string
{
    return dirname(__DIR__, 4).'/infra/production/'.$path;
}

function c4InfraMissing(): bool
{
    return ! file_exists(c4Infra('.env.production.example'));
}

/** Parser "thô" giống `docker --env-file`/systemd `EnvironmentFile`: tách theo dấu `=` ĐẦU TIÊN, giữ nguyên phần đuôi (kể cả chú thích). */
function c4ParseRawEnv(string $content): array
{
    $vars = [];

    foreach (preg_split('/\R/', $content) as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $vars[trim($key)] = $value;
    }

    return $vars;
}

/** Nạp biến vào env thật, nạp lại config, đặt môi trường như APP_ENV thô rồi chạy guard. Trả thông báo lỗi hoặc null. */
function c4RunGuardRaw(array $vars): ?string
{
    $backup = [];

    foreach ($vars as $k => $v) {
        $backup[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)];
        $_ENV[$k] = $_SERVER[$k] = $v;
        putenv("{$k}={$v}");
    }

    try {
        $loaded = [];
        foreach (['app', 'session', 'sanctum', 'captcha', 'payments', 'video', 'videolab', 'internal', 'auth', 'features'] as $name) {
            $loaded[$name] = require config_path("{$name}.php");
        }
        config($loaded);
        app()->detectEnvironment(fn () => (string) ($vars['APP_ENV'] ?? 'production'));

        try {
            (new ProductionConfigGuard)->check();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    } finally {
        foreach ($backup as $k => [$e, $s, $g]) {
            $e === null ? $_ENV[$k] = null : $_ENV[$k] = $e;
            if ($e === null) {
                unset($_ENV[$k]);
            }
            if ($s === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $s;
            }
            $g === false ? putenv($k) : putenv("{$k}={$g}");
        }
    }
}

/** Giá trị hạ tầng thay cho placeholder của file mẫu production. */
function c4ValidInfra(): array
{
    return [
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'TRUSTED_PROXIES' => '10.0.0.1,10.0.0.2',
        'INTERNAL_API_TOKEN' => str_repeat('ab', 32),
        'VIDEOLAB_API_KEY' => str_repeat('a', 64),
        'VIDEOLAB_TOKEN_KEY' => str_repeat('b', 64),
        'VIDEOLAB_WEBHOOK_SECRET' => str_repeat('c', 64),
        'MOMO_ENDPOINT' => 'https://payment.momo.vn/v2/gateway/api/create',
    ];
}

// ---------------------------------------------------------------- M2

test('M2: env mau production nap bang parser tho (docker --env-file) qua guard khi thay placeholder', function (string $env) {
    $vars = array_merge(c4ParseRawEnv((string) file_get_contents(c4Infra('.env.production.example'))), c4ValidInfra(), ['APP_ENV' => $env]);

    expect(c4RunGuardRaw($vars))->toBeNull();
})->with(['production', 'staging'])->skip(c4InfraMissing(), 'Cần mount infra/production (xem checklist §7).');

test('M2: env mau (production + worker-video) khong con chu thich cuoi dong', function (string $file) {
    foreach (preg_split('/\R/', (string) file_get_contents(c4Infra($file))) as $n => $line) {
        expect(preg_match('/^[A-Z][A-Z0-9_]*=.*\s#/', $line))->toBe(0, "{$file}:".($n + 1).' còn chú thích cuối dòng: '.$line);
    }
})->with(['.env.production.example', '.env.worker-video.example'])->skip(c4InfraMissing(), 'Cần mount infra/production.');

test('M2: env co chu thich cuoi dong (kieu cu) nap bang parser tho thi guard chan', function (string $line, string $expectedKey) {
    $vars = array_merge(c4ParseRawEnv((string) file_get_contents(c4Infra('.env.production.example'))), c4ValidInfra());
    $vars = array_merge($vars, c4ParseRawEnv($line));

    $error = c4RunGuardRaw($vars);

    expect($error)->not->toBeNull()->and($error)->toContain($expectedKey);
})->with([
    'APP_ENV dinh chu thich' => ['APP_ENV=production                 # production | staging. TUYET DOI khong de local', 'APP_ENV'],
    'APP_ENV=prod' => ['APP_ENV=prod', 'APP_ENV'],
    'token la placeholder' => ['INTERNAL_API_TOKEN=                # openssl rand -hex 32 (>= 32 ky tu)', 'INTERNAL_API_TOKEN'],
    'token khong phai hex' => ['INTERNAL_API_TOKEN=zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', 'INTERNAL_API_TOKEN'],
    'PAYMENT_GATEWAYS dinh chu thich' => ['PAYMENT_GATEWAYS=                  # de trong khi MVP', 'PAYMENT_GATEWAYS'],
    'CAPTCHA_DRIVER dinh chu thich' => ['CAPTCHA_DRIVER=turnstile           # fake bi chan', 'CAPTCHA_DRIVER'],
    'cookie dinh chu thich' => ['SESSION_COOKIE=__Host-vv_session          # staging: x', 'SESSION_COOKIE'],
    'khoang trang thua o cuoi' => ['APP_URL=https://api.vitaminvui.vn ', 'APP_URL'],
])->skip(c4InfraMissing(), 'Cần mount infra/production.');

test('M2: APP_ENV khong hop le bi chan truoc moi kiem tra khac', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'APP_ENV');
})->with(['prod', 'production # x', 'Staging', 'stage', 'development', 'production ']);

test('M2: bien env quan trong chua " #" bi chan (guard doc gia tri tho)', function (string $key, string $value) {
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.debug' => false, 'session.secure' => true, 'session.encrypt' => true, 'captcha.driver' => 'turnstile',
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'], 'app.static_url' => 'https://static.vitaminvui-media.net', 'app.trusted_proxies' => '10.0.0.1',
        'payments.enabled_gateways' => [], 'video.provider' => 'internal', 'video.enabled_providers' => ['internal'],
        'internal.required' => false, 'internal.ssr_token' => null, 'features.paid_checkout' => false,
        'videolab.enabled' => false,
    ]);

    $_ENV[$key] = $value;

    try {
        expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, $key);
    } finally {
        unset($_ENV[$key]);
    }
})->with([
    ['APP_URL', 'https://api.vitaminvui.vn # x'],
    ['VIDEOLAB_API_KEY', str_repeat('a', 40).'   # x'],
    ['DB_PASSWORD', 'abc # def'],
    ['REDIS_PASSWORD', ' abc'],
]);

// ---------------------------------------------------------------- M1

test('M1: guard bat buoc SESSION_ENCRYPT=true ngoai local/testing', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.debug' => false, 'session.secure' => true, 'session.encrypt' => false, 'captcha.driver' => 'turnstile',
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'], 'app.static_url' => 'https://static.vitaminvui-media.net', 'app.trusted_proxies' => '10.0.0.1',
        'payments.enabled_gateways' => [], 'video.provider' => 'internal', 'video.enabled_providers' => ['internal'],
        'internal.required' => false, 'features.paid_checkout' => false, 'videolab.enabled' => false,
    ]);

    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'SESSION_ENCRYPT');

    config(['session.encrypt' => true]);
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);

    app()->detectEnvironment(fn () => 'local');
    config(['session.encrypt' => false]);
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
});

test('M1: env mau bat SESSION_ENCRYPT, worker-video co REDIS_USERNAME/REDIS_PASSWORD rieng va khong con REDIS_DB_SESSION', function () {
    $app = c4ParseRawEnv((string) file_get_contents(c4Infra('.env.production.example')));
    $worker = c4ParseRawEnv((string) file_get_contents(c4Infra('.env.worker-video.example')));

    expect($app)->toHaveKey('SESSION_ENCRYPT', 'true')
        ->and($worker)->toHaveKey('REDIS_USERNAME', 'vv_worker_video')
        ->and($worker)->toHaveKey('REDIS_PASSWORD')
        ->and($worker)->not->toHaveKey('REDIS_DB_SESSION')
        ->and($worker)->not->toHaveKey('REDIS_LIMITER_DB')
        ->and($worker['REDIS_PREFIX'])->toBe($app['REDIS_PREFIX'])
        ->and($worker['REDIS_PASSWORD'])->not->toBe($app['REDIS_PASSWORD'] ?: 'x');
})->skip(c4InfraMissing(), 'Cần mount infra/production.');

test('M1: config/database.php doc REDIS_USERNAME cho moi ket noi Redis', function () {
    $_ENV['REDIS_USERNAME'] = $_SERVER['REDIS_USERNAME'] = 'vv_worker_video';
    putenv('REDIS_USERNAME=vv_worker_video');

    try {
        $redis = (require config_path('database.php'))['redis'];

        foreach (['default', 'session', 'cache', 'queue', 'limiter'] as $name) {
            expect($redis[$name]['username'])->toBe('vv_worker_video');
        }
    } finally {
        unset($_ENV['REDIS_USERNAME'], $_SERVER['REDIS_USERNAME']);
        putenv('REDIS_USERNAME');
    }
});

test('R1: moi dong khong rong cua file ACL phai bat dau bang `user ` (Redis khong cho comment)', function (string $file) {
    $lines = array_filter(array_map('trim', file(c4Infra("redis/{$file}"))), fn ($l) => $l !== '');

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        expect($line)->toStartWith('user ');
    }
})->with(['users.acl', 'users.video-instance.acl'])->skip(c4InfraMissing(), 'Cần mount infra/production.');

test('M1/R7: mau Redis ACL: worker-video chi queue `video`, khong EVALSHA, khong @dangerous/key *', function (string $file, bool $shared) {
    $lines = array_values(array_filter(array_map('trim', file(c4Infra("redis/{$file}"))), fn ($l) => str_starts_with($l, 'user ')));
    $worker = collect($lines)->first(fn ($l) => str_starts_with($l, 'user vv_worker_video '));

    expect($worker)->not->toBeNull()
        ->and(collect($lines)->contains(fn ($l) => str_starts_with($l, 'user default ')))->toBeTrue();

    $tokens = preg_split('/\s+/', $worker);
    $commands = collect($tokens)->filter(fn ($t) => str_starts_with($t, '+'))->map(fn ($t) => ltrim($t, '+'))->sort()->values()->all();
    $keys = collect($tokens)->filter(fn ($t) => str_starts_with($t, '~'))->sort()->values()->all();
    $readKeys = collect($tokens)->filter(fn ($t) => str_starts_with($t, '%R~'))->sort()->values()->all();

    $expected = ['eval', 'lpop', 'rpush', 'zadd', 'zrem', 'zrangebyscore', 'zremrangebyrank', 'llen', 'zcard', 'blpop', 'select', 'ping'];
    $shared && array_push($expected, 'get', 'mget');
    sort($expected);

    expect($commands)->toBe($expected)->and($commands)->not->toContain('evalsha')
        ->and($tokens)->toContain('-@all')->and($tokens)->toContain('resetkeys')->and($tokens)->toContain('resetchannels')
        ->and($worker)->not->toContain('~*')->not->toContain('+@')->not->toContain('queues:default')->not->toContain(' >')
        ->and($keys)->toBe(['~<PREFIX>queues:video', '~<PREFIX>queues:video:delayed', '~<PREFIX>queues:video:notify', '~<PREFIX>queues:video:reserved'])
        ->and($readKeys)->toHaveCount($shared ? 3 : 0);

    foreach ($readKeys as $key) {
        expect($key)->toContain('illuminate:queue');
    }
})->with([['users.acl', true], ['users.video-instance.acl', false]])->skip(c4InfraMissing(), 'Cần mount infra/production.');

test('R2: connection redis_video dung connection Redis `video`: mac dinh cung Redis/DB voi queue, tach duoc bang REDIS_VIDEO_*', function () {
    $vars = ['REDIS_HOST' => 'main-redis', 'REDIS_PORT' => '6379', 'REDIS_PASSWORD' => 'mainpw', 'REDIS_USERNAME' => 'u-main', 'REDIS_QUEUE_DB' => '3'];
    $video = ['REDIS_VIDEO_HOST' => 'video-redis', 'REDIS_VIDEO_PORT' => '6380', 'REDIS_VIDEO_PASSWORD' => 'videopw', 'REDIS_VIDEO_USERNAME' => 'vv_worker_video', 'REDIS_VIDEO_DB' => '0'];

    $load = function (array $env): array {
        $keys = array_keys($env);
        foreach ($env as $k => $v) {
            $_ENV[$k] = $_SERVER[$k] = $v;
            putenv("{$k}={$v}");
        }

        try {
            return (require config_path('database.php'))['redis']['video'];
        } finally {
            foreach ($keys as $k) {
                unset($_ENV[$k], $_SERVER[$k]);
                putenv($k);
            }
        }
    };

    // Không đặt REDIS_VIDEO_*: giống Redis chính (local không đổi).
    expect($load($vars))->toMatchArray(['host' => 'main-redis', 'port' => '6379', 'password' => 'mainpw', 'username' => 'u-main', 'database' => '3']);
    // Đặt REDIS_VIDEO_*: tách sang Redis riêng.
    expect($load($vars + $video))->toMatchArray(['host' => 'video-redis', 'port' => '6380', 'password' => 'videopw', 'username' => 'vv_worker_video', 'database' => '0']);

    expect(config('queue.connections.redis_video.connection'))->toBe('video')
        ->and(config('queue.connections.redis_video.queue'))->toBe('video')
        ->and(config('queue.connections.redis.connection'))->toBe('queue')
        ->and(config('videolab.job.connection'))->toBe('redis_video');
});

test('R2: TranscodeVideoJob (TusUploadService) dispatch qua connection redis_video/queue video; webhook van vao queue default', function () {
    Queue::fake();

    TranscodeVideoJob::dispatch('11111111-1111-1111-1111-111111111111');
    SendVideoLabWebhookJob::dispatch('11111111-1111-1111-1111-111111111111');

    Queue::assertPushedOn('video', TranscodeVideoJob::class);
    Queue::assertPushed(TranscodeVideoJob::class, fn ($j) => $j->connection === 'redis_video');
    Queue::assertPushed(SendVideoLabWebhookJob::class, fn ($j) => $j->queue === null && $j->connection === null);
});

test('R2: env mau co huong dan Redis rieng cho video (REDIS_VIDEO_*) o ca app va worker', function (string $file) {
    $content = (string) file_get_contents(c4Infra($file));

    foreach (['REDIS_VIDEO_HOST', 'REDIS_VIDEO_PORT', 'REDIS_VIDEO_USERNAME', 'REDIS_VIDEO_PASSWORD'] as $key) {
        expect($content)->toContain("# {$key}=");
    }
})->with(['.env.production.example', '.env.worker-video.example'])->skip(c4InfraMissing(), 'Cần mount infra/production.');

test('M1: worker-video (connection redis_video) khong ghi heartbeat vao cache; worker cua app van ghi', function () {
    $reset = fn () => (function () {
        self::$lastWorkerWrite = 0;
    })->bindTo(null, Heartbeat::class)();

    config(['cache.default' => 'array']);
    $key = config('ops.health.cache_prefix').'worker';
    Cache::forget($key);

    $reset();
    event(new Looping('redis_video', 'video'));
    expect(Cache::get($key))->toBeNull();

    $reset();
    event(new Looping('redis', 'default'));
    expect(Cache::get($key))->not->toBeNull();
});

// ---------------------------------------------------------------- L1

test('L1: LOG_LEVEL=warning khong lam mat log playback/payments/learning (co dinh info)', function () {
    $_ENV['LOG_LEVEL'] = $_SERVER['LOG_LEVEL'] = 'warning';
    putenv('LOG_LEVEL=warning');

    try {
        $channels = (require config_path('logging.php'))['channels'];

        foreach (['playback', 'payments', 'learning'] as $name) {
            expect($channels[$name]['level'])->toBe('info');
        }
    } finally {
        unset($_ENV['LOG_LEVEL'], $_SERVER['LOG_LEVEL']);
        putenv('LOG_LEVEL');
    }
});

// ---------------------------------------------------------------- L2

test('L2: /up tra JSON toi gian (khong HTML/script CDN), co CSP va Permissions-Policy', function (string $hostKey) {
    $response = $this->get('http://'.config($hostKey).'/up');

    $response->assertOk()->assertExactJson(['status' => 'ok']);
    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->getContent())->not->toContain('jsdelivr')->not->toContain('<html');
    $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('microphone=()')->toContain('geolocation=()');
})->with(['app.api_host', 'app.admin_api_host']);

test('L2: moi response API (JSON, 404 HTML, 405) co CSP default-src none', function () {
    $host = 'http://'.config('app.api_host');

    foreach ([
        $this->getJson($host.'/api/v1/health'),
        $this->get($host.'/khong-ton-tai', ['Accept' => 'text/html']),
        $this->post($host.'/api/v1/health'),
    ] as $response) {
        expect($response->headers->get('Content-Security-Policy'))->toStartWith("default-src 'none'")
            ->and($response->headers->get('Permissions-Policy'))->not->toBeEmpty();
    }
});

test('L2: Nginx mau gioi han IP cho /up', function () {
    $conf = (string) file_get_contents(c4Infra('nginx/snippets/vv-api-common.conf'));

    expect($conf)->toMatch('/location = \/up \{[^}]*allow <IP_MONITOR_LB>;[^}]*deny all;/s');
})->skip(c4InfraMissing(), 'Cần mount infra/production.');

// ---------------------------------------------------------------- L4

test('L4: APP_DEBUG=true o production: guard ep tat debug, request HTML (ngoai api/*) khong lo trang debug', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.debug' => true, 'session.secure' => true, 'session.encrypt' => true]);

    $caught = null;

    try {
        (new ProductionConfigGuard)->check();
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()->and(config('app.debug'))->toBeFalse();

    $response = app(ExceptionHandler::class)->render(Request::create('/khong-ton-tai', 'GET', server: ['HTTP_ACCEPT' => 'text/html']), $caught);
    $body = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(500)
        ->and($body)->not->toContain('ProductionConfigGuard')->not->toContain('vendor/')->not->toContain('RuntimeException')->not->toContain('APP_DEBUG');

    // Đối chứng: nếu debug còn bật thì trang HTML lộ chi tiết (chứng tỏ kiểm tra trên có ý nghĩa).
    config(['app.debug' => true]);
    $debugBody = (string) app(ExceptionHandler::class)->render(Request::create('/khong-ton-tai', 'GET', server: ['HTTP_ACCEPT' => 'text/html']), $caught)->getContent();
    expect($debugBody)->toContain('RuntimeException');
});

// ---------------------------------------------------------------- R3, R4

test('R3: APP_ENV sai (prod) kem APP_DEBUG=true: guard ep tat debug truoc, khong render trang debug', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    config(['app.debug' => true]);

    $caught = null;

    try {
        (new ProductionConfigGuard)->check();
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()->and(config('app.debug'))->toBeFalse();

    $body = (string) app(ExceptionHandler::class)->render(Request::create('/x', 'GET', server: ['HTTP_ACCEPT' => 'text/html']), $caught)->getContent();
    expect($body)->not->toContain('ProductionConfigGuard')->not->toContain('vendor/')->not->toContain('RuntimeException');
})->with(['prod', 'Production', 'uat', 'production # x']);

test('R3: local/testing khong bi ep tat debug', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    config(['app.debug' => true]);

    (new ProductionConfigGuard)->check();

    expect(config('app.debug'))->toBeTrue();
})->with(['local', 'testing']);

test('R4: mat khau hop le chua # sat chu (ab#cd, #abc, a#b) khong bi chan; " #" va khoang trang dau/cuoi bi chan', function (string $value, bool $blocked) {
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.debug' => false, 'session.secure' => true, 'session.encrypt' => true, 'captcha.driver' => 'turnstile',
        'sanctum.stateful' => ['vitaminvui.vn', 'admin.vitaminvui.vn'], 'app.static_url' => 'https://static.vitaminvui-media.net', 'app.trusted_proxies' => '10.0.0.1',
        'payments.enabled_gateways' => [], 'video.provider' => 'internal', 'video.enabled_providers' => ['internal'],
        'internal.required' => false, 'internal.ssr_token' => null, 'features.paid_checkout' => false,
        'videolab.enabled' => false,
    ]);

    foreach (['DB_PASSWORD', 'REDIS_PASSWORD', 'TURNSTILE_SECRET'] as $key) {
        $_ENV[$key] = $value;
    }

    try {
        $blocked
            ? expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class, 'chú thích cuối dòng')
            : expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);
    } finally {
        unset($_ENV['DB_PASSWORD'], $_ENV['REDIS_PASSWORD'], $_ENV['TURNSTILE_SECRET']);
    }
})->with([
    ['ab#cd', false],
    ['#abc', false],
    ['abc#', false],
    ['a1B2c3D4e5F6g7H8', false],
    ['ab #cd', true],
    [' abcd', true],
    ['abcd ', true],
]);

// ---------------------------------------------------------------- R5

test('R5: videolab:notify dispatch loi -> notified_at tro ve null, lan sau gui lai duoc', function () {
    $video = Video::factory()->finished()->create();

    // Làm dispatch ném lỗi: bind Dispatcher giả.
    $failing = new class(app()) extends Illuminate\Bus\Dispatcher
    {
        public function dispatch($command)
        {
            throw new RuntimeException('Redis down');
        }
    };

    $original = app(Dispatcher::class);
    app()->instance(Dispatcher::class, $failing);
    Bus::clearResolvedInstance(Dispatcher::class);

    expect(fn () => Artisan::call('videolab:notify'))->toThrow(RuntimeException::class, 'Redis down');
    expect($video->fresh()->notified_at)->toBeNull();

    // Lượt sau (Redis lại chạy): gửi được và đánh dấu.
    app()->instance(Dispatcher::class, $original);
    Bus::clearResolvedInstance(Dispatcher::class);
    Queue::fake();

    test()->artisan('videolab:notify')->assertSuccessful();

    Queue::assertPushed(SendVideoLabWebhookJob::class, fn ($j) => $j->guid === $video->guid);
    expect($video->fresh()->notified_at)->not->toBeNull();
});

test('R5: migration notified_at backfill: video xong/loi cu duoc coi la da bao, video dang xu ly thi khong', function () {
    $migration = require database_path('migrations/2026_10_17_100000_add_notified_at_to_vl_videos.php');
    $migration->down();

    try {

        $stamp = now()->subDay()->startOfSecond();
        $done = Video::factory()->finished()->create();
        $failed = Video::factory()->create(['status' => Video::ERROR]);
        $busy = Video::factory()->create(['status' => Video::TRANSCODING]);
        DB::table('vl_videos')->update(['updated_at' => $stamp]);

        $migration->up();

        expect(Schema::hasColumn('vl_videos', 'notified_at'))->toBeTrue();
        $rows = DB::table('vl_videos')->pluck('notified_at', 'guid');
        expect($rows[$done->guid])->toBe($stamp->toDateTimeString())
            ->and($rows[$failed->guid])->toBe($stamp->toDateTimeString())
            ->and($rows[$busy->guid])->toBeNull();

        // down() đảo ngược được.
        $migration->down();
        expect(Schema::hasColumn('vl_videos', 'notified_at'))->toBeFalse();
        $migration->up();
        expect(Schema::hasColumn('vl_videos', 'notified_at'))->toBeTrue();
    } finally {
        // ALTER TABLE gây commit ngầm trong MySQL: dọn dữ liệu test để không rò sang test khác.
        if (! Schema::hasColumn('vl_videos', 'notified_at')) {
            $migration->up();
        }
        DB::table('vl_videos')->delete();
    }
});
