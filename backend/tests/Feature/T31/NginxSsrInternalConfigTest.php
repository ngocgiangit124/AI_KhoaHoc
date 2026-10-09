<?php

/**
 * Sua loi nho 4 (ADR-004 §2.8, R1 cua review): kiem TINH mau Nginx `infra/production/nginx/conf.d/vitaminvui.conf`.
 * Skip khi container khong mount `infra/production` (xem checklist §7), cung cach voi Cum4ConfigTest.
 */
function ngxMissing(): bool
{
    return ! file_exists(ngxPath());
}

function ngxPath(): string
{
    return dirname(__DIR__, 4).'/infra/production/nginx/conf.d/vitaminvui.conf';
}

/** Bo dong chu thich de grep khong khop nham vao chu thich. */
function ngxStrip(string $s): string
{
    return preg_replace('/^\s*#.*$/m', '', preg_replace('/(?<=;)\s+#.*$/m', '', $s));
}

/** Lay noi dung khoi `{ ... }` bat dau tai vi tri dau `{` (can bang ngoac). */
function ngxBlockAt(string $src, int $open): string
{
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}' && --$depth === 0) {
            return substr($src, $open + 1, $i - $open - 1);
        }
    }

    return '';
}

/** Cac khoi `server {}` (da bo chu thich). */
function ngxServers(): array
{
    $src = ngxStrip(file_get_contents(ngxPath()));
    preg_match_all('/^server\s*\{/m', $src, $m, PREG_OFFSET_CAPTURE);

    return array_map(fn ($x) => ngxBlockAt($src, $x[1] + strlen($x[0]) - 1), $m[0]);
}

function ngxVideoServer(): string
{
    $src = ngxStrip(file_get_contents(dirname(ngxPath(), 2).'/optional/videolab.conf'));
    preg_match('/^server\s*\{/m', $src, $m, PREG_OFFSET_CAPTURE);

    return ngxBlockAt($src, $m[0][1] + strlen($m[0][0]) - 1);
}

function ngxServerByName(string $name): string
{
    foreach (ngxServers() as $block) {
        if (preg_match('/^\s*server_name\s+'.preg_quote($name, '/').'\s*;/m', $block)) {
            return $block;
        }
    }
    throw new RuntimeException("Khong thay server_name {$name}");
}

/** Khoi `location <selector> {}` ben trong mot server. */
function ngxLocation(string $server, string $selector): string
{
    if (! preg_match('/^\s*location\s+'.preg_quote($selector, '/').'\s*\{/m', $server, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException("Khong thay location {$selector}");
    }

    return ngxBlockAt($server, $m[0][1] + strlen($m[0][0]) - 1);
}

function ngxInternalServer(): string
{
    foreach (ngxServers() as $block) {
        if (preg_match('/^\s*listen\s+[^;]*:8081\s*;/m', $block)) {
            return $block;
        }
    }
    throw new RuntimeException('Khong thay listener 8081');
}

test('S4-N1 listener 8081 ep HTTP_HOST = host api, sau include fastcgi_params', function () {
    $loc = ngxLocation(ngxInternalServer(), '^~ /api/v1/');

    expect($loc)->toMatch('/fastcgi_param\s+HTTP_HOST\s+api\.vitaminvui\.vn\s*;/');
    expect(strpos($loc, 'include fastcgi_params'))->toBeLessThan(strpos($loc, 'fastcgi_param HTTP_HOST'));
    // Khong xoa header noi bo o day (can toi PHP).
    expect($loc)->not->toContain('HTTP_X_INTERNAL_TOKEN')->not->toContain('HTTP_X_CLIENT_IP');
})->skip(ngxMissing(), 'Can mount infra/production (xem checklist §7).');

test('S4-N2 listener 8081 chi cho IP Next (allow + deny all), body nho, log rieng', function () {
    $s = ngxInternalServer();

    expect($s)->toMatch('/^\s*allow\s+<IP_NEXT_SERVER>\s*;/m')
        ->toMatch('/^\s*deny\s+all\s*;/m')
        ->toMatch('/^\s*listen\s+<IP_NOI_BO_NGINX>:8081\s*;/m')
        ->toMatch('/^\s*client_max_body_size\s+1k\s*;/m')
        ->toMatch('/^\s*access_log\s+\/var\/log\/nginx\/vv-internal\.access\.log\s*;/m')
        ->not->toContain('ssl')
        ->not->toMatch('/allow\s+(all|0\.0\.0\.0)/');
    // allow dung truoc deny all.
    expect(strpos($s, 'allow <IP_NEXT_SERVER>'))->toBeLessThan(strpos($s, 'deny all'));
})->skip(ngxMissing(), 'Can mount infra/production.');

test('S4-N3 listener 8081 chi GET/HEAD toi /api/v1/, moi duong khac 404, khong co PHP truc tiep', function () {
    $s = ngxInternalServer();

    expect(ngxLocation($s, '/'))->toMatch('/return\s+404\s*;/');
    $api = ngxLocation($s, '^~ /api/v1/');
    expect($api)->toMatch('/limit_except\s+GET\s+HEAD\s*\{\s*deny\s+all;\s*\}/')
        ->toContain('fastcgi_pass php_fpm')
        ->toContain('SCRIPT_FILENAME $document_root/index.php');
    expect($s)->not->toMatch('/location\s+~[^{]*\\\\\.php/');
    // Chi dung 2 location.
    expect(preg_match_all('/^\s*location\s/m', $s))->toBe(2);
})->skip(ngxMissing(), 'Can mount infra/production.');

test('S4-N4 moi host cong khai xoa X-Internal-Token va X-Client-IP', function () {
    $proxyHosts = ['vitaminvui.vn', 'admin.vitaminvui.vn'];
    foreach ($proxyHosts as $h) {
        $s = ngxServerByName($h);
        // moi location co proxy_pass phai co ca 2 dong xoa header
        preg_match_all('/^\s*location\s+([^{]+)\{/m', $s, $locs, PREG_OFFSET_CAPTURE);
        $count = 0;
        foreach ($locs[0] as $i => $l) {
            $body = ngxBlockAt($s, $l[1] + strlen($l[0]) - 1);
            if (str_contains($body, 'proxy_pass')) {
                $count++;
                expect($body)->toMatch('/proxy_set_header\s+X-Internal-Token\s+""\s*;/', "{$h} {$locs[1][$i][0]}")
                    ->toMatch('/proxy_set_header\s+X-Client-IP\s+""\s*;/', "{$h} {$locs[1][$i][0]}");
            }
        }
        expect($count)->toBeGreaterThan(0);
    }

    // host api / admin-api dung snippet chung phai xoa 2 header qua fastcgi_param
    $snippet = ngxStrip(file_get_contents(dirname(ngxPath(), 2).'/snippets/vv-api-common.conf'));
    expect($snippet)->toMatch('/fastcgi_param\s+HTTP_X_INTERNAL_TOKEN\s+""\s*;/')
        ->toMatch('/fastcgi_param\s+HTTP_X_CLIENT_IP\s+""\s*;/');
    foreach (['api.vitaminvui.vn', 'admin-api.vitaminvui.vn'] as $h) {
        expect(ngxServerByName($h))->toContain('include /etc/nginx/snippets/vv-api-common.conf');
    }

    // host video (TUY CHON, nginx/optional/videolab.conf, khong nap mac dinh): moi location vao PHP xoa 2 header
    expect(file_get_contents(ngxPath()))->not->toContain('server_name video.');
    $video = ngxVideoServer();
    preg_match_all('/^\s*location\s+([^{]+)\{/m', $video, $locs, PREG_OFFSET_CAPTURE);
    foreach ($locs[0] as $i => $l) {
        $body = ngxBlockAt($video, $l[1] + strlen($l[0]) - 1);
        if (str_contains($body, 'fastcgi_pass')) {
            expect($body)->toMatch('/fastcgi_param\s+HTTP_X_INTERNAL_TOKEN\s+""\s*;/', $locs[1][$i][0])
                ->toMatch('/fastcgi_param\s+HTTP_X_CLIENT_IP\s+""\s*;/', $locs[1][$i][0]);
        }
    }
})->skip(ngxMissing(), 'Can mount infra/production.');

test('S4-N5 host web: location / co limit_req vv_web + 429, /_next/static/ KHONG co limit_req', function () {
    $web = ngxServerByName('vitaminvui.vn');

    $root = ngxLocation($web, '/');
    expect($root)->toMatch('/limit_req\s+zone=vv_web\s+burst=\d+\s+nodelay\s*;/')
        ->toMatch('/limit_req_status\s+429\s*;/');

    $static = ngxLocation($web, '^~ /_next/static/');
    expect($static)->not->toContain('limit_req')->toContain('proxy_pass http://next_web');

    // Khong limit_req o cap server (se ap len ca /_next/static/).
    $serverLevel = preg_replace('/^\s*location\s[^{]*\{(?:[^{}]*)\}/m', '', $web);
    expect($serverLevel)->not->toContain('limit_req');

    // Zone khai bao o http level.
    expect(ngxStrip(file_get_contents(ngxPath())))->toMatch('/^limit_req_zone\s+\$binary_remote_addr\s+zone=vv_web:\d+m\s+rate=\d+r\/s\s*;/m');
})->skip(ngxMissing(), 'Can mount infra/production.');

test('S4-N6 host web co real_ip de limit_req theo IP khach that; host admin khong bi limit_req', function () {
    expect(ngxServerByName('vitaminvui.vn'))->toContain('include /etc/nginx/snippets/vv-real-ip.conf');
    expect(ngxServerByName('admin.vitaminvui.vn'))->not->toContain('vv_web');
})->skip(ngxMissing(), 'Can mount infra/production.');

test('R-1 T37: webhook Bunny log theo $uri (khong query), thang ^~ webhooks, giu xu ly header/fastcgi', function () {
    $conf = ngxStrip((string) file_get_contents(ngxPath()));
    $snippet = ngxStrip((string) file_get_contents(dirname(ngxPath(), 2).'/snippets/vv-api-common.conf'));

    // log_format khong chua query ($request, $args, $query_string, $request_uri) va dung $uri
    expect($conf)->toMatch('/^log_format\s+vv_noargs\s+\'([^\']*)\'\s*;/m');
    preg_match('/^log_format\s+vv_noargs\s+\'([^\']*)\'\s*;/m', $conf, $m);
    expect($m[1])->toContain('$uri')->not->toContain('$request ')->not->toContain('$request"')->not->toContain('$args')
        ->not->toContain('$query_string')->not->toContain('$request_uri');

    $pos = strpos($snippet, 'location = /api/v1/webhooks/video/bunny');
    expect($pos)->not->toBeFalse();
    $body = ngxBlockAt($snippet, strpos($snippet, '{', $pos));
    expect($body)->toMatch('/access_log\s+\S+\s+vv_noargs\s*;/')
        ->toContain('client_max_body_size 16k')
        ->toMatch('/fastcgi_param\s+HTTP_X_INTERNAL_TOKEN\s+""\s*;/')
        ->toMatch('/fastcgi_param\s+HTTP_X_CLIENT_IP\s+""\s*;/')
        ->toContain('fastcgi_pass php_fpm');

    // Khong con access_log mac dinh o cap server cho host api (se ghi query)
    expect(ngxServerByName('api.vitaminvui.vn'))->not->toMatch('/access_log\s+\S+\s+(combined|main)/');
})->skip(ngxMissing(), 'Can mount infra/production.');

test('V3-3 moi khoi proxy toi Next chuan hoa X-Forwarded-Host, X-Real-IP, Forwarded', function () {
    foreach (['vitaminvui.vn', 'admin.vitaminvui.vn'] as $h) {
        $s = ngxServerByName($h);
        preg_match_all('/^\s*location\s+([^{]+)\{/m', $s, $locs, PREG_OFFSET_CAPTURE);
        $count = 0;
        foreach ($locs[0] as $i => $l) {
            $body = ngxBlockAt($s, $l[1] + strlen($l[0]) - 1);
            if (str_contains($body, 'proxy_pass')) {
                $count++;
                expect($body)->toMatch('/proxy_set_header\s+X-Forwarded-Host\s+\$host\s*;/', "{$h} {$locs[1][$i][0]}")
                    ->toMatch('/proxy_set_header\s+X-Real-IP\s+\$remote_addr\s*;/', "{$h} {$locs[1][$i][0]}")
                    ->toMatch('/proxy_set_header\s+Forwarded\s+""\s*;/', "{$h} {$locs[1][$i][0]}");
            }
        }
        expect($count)->toBeGreaterThan(0);
    }
})->skip(ngxMissing(), 'Can mount infra/production.');

test('V3-1 host API co limit_req/limit_conn/timeout; zone khai bao o http level; /index.php internal', function () {
    $conf = ngxStrip(file_get_contents(ngxPath()));
    $snippet = ngxStrip(file_get_contents(dirname(ngxPath(), 2).'/snippets/vv-api-common.conf'));

    expect($conf)->toMatch('/^limit_req_zone\s+\$binary_remote_addr\s+zone=vv_api:\d+m\s+rate=\d+r\/s\s*;/m')
        ->toMatch('/^limit_req_zone\s+\$binary_remote_addr\s+zone=vv_api_auth:\d+m\s+rate=\d+r\/s\s*;/m')
        ->toMatch('/^limit_conn_zone\s+\$binary_remote_addr\s+zone=vv_conn:\d+m\s*;/m');
    expect($snippet)->toMatch('/limit_req\s+zone=vv_api\s+burst=\d+\s+nodelay\s*;/')
        ->toMatch('/limit_req\s+zone=vv_api_auth\s+burst=\d+\s+nodelay\s*;/')
        ->toMatch('/limit_req_status\s+429\s*;/')
        ->toMatch('/limit_conn\s+vv_conn\s+\d+\s*;/')
        ->toMatch('/client_header_timeout\s+\d+s\s*;/')
        ->toMatch('/client_body_timeout\s+\d+s\s*;/');
    expect(ngxLocation($snippet, '= /index.php'))->toContain('internal;');

    // Khong ap limit len listener :8081 (SSR da co tran tong rieng) va khong include snippet nay o do.
    expect(ngxInternalServer())->not->toContain('limit_req')->not->toContain('vv-api-common.conf');
})->skip(ngxMissing(), 'Can mount infra/production.');
