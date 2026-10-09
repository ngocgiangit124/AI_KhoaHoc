<?php

/**
 * T35-1 (ADR-008 §8.11–8.12): deploy.sh dừng khi có migration chờ mang dấu VV-IRREVERSIBLE mà thiếu --ack-irreversible.
 */
test('migration backfill T29 mang dấu VV-IRREVERSIBLE ở đầu file', function () {
    $file = database_path('migrations/2026_10_20_110000_backfill_parent_consent_status.php');
    $head = implode("\n", array_slice(preg_split('/\R/', (string) file_get_contents($file)), 0, 4));

    expect($head)->toContain('// VV-IRREVERSIBLE:');
});

test('dấu VV-IRREVERSIBLE chỉ nằm ở migration có down() rỗng', function () {
    foreach (glob(database_path('migrations/*.php')) as $file) {
        $code = (string) file_get_contents($file);
        if (! str_contains($code, 'VV-IRREVERSIBLE')) {
            continue;
        }

        expect(preg_match('/function down\(\): void\s*\{\s*(\/\/[^\n]*\s*)?\}/', $code))->toBe(1, basename($file).' có dấu nhưng down() không rỗng');
    }
});

test('compose-version của docker-compose.yml khớp COMPOSE_VERSION của docker-bake.hcl', function () {
    $compose = base_path('../infra/production/docker-compose.yml');
    $bake = base_path('../infra/production/docker-bake.hcl');
    if (! is_file($compose) || ! is_file($bake)) {
        $this->markTestSkipped('infra/ không được mount trong container này.');
    }

    preg_match('/^x-vv-compose-version:\s*"?(\d+)"?/m', (string) file_get_contents($compose), $c);
    preg_match('/variable "COMPOSE_VERSION" \{\s*default = "(\d+)"/', (string) file_get_contents($bake), $b);

    expect($c[1] ?? null)->not->toBeNull()->and($c[1])->toBe($b[1] ?? null);
});

test('ACL Redis có user vv_healthcheck chỉ được PING, không mang mật khẩu', function (string $file) {
    $path = base_path("../infra/production/redis/{$file}");
    if (! is_file($path)) {
        $this->markTestSkipped('infra/ không được mount trong container này.');
    }

    $line = collect(file($path, FILE_IGNORE_NEW_LINES))->first(fn ($l) => str_starts_with($l, 'user vv_healthcheck '));

    expect($line)->not->toBeNull()
        ->and($line)->toContain(' nopass ')->toContain('-@all')->toContain('+ping')
        ->and(substr_count($line, '+'))->toBe(1);
})->with(['users.acl', 'users.video-instance.acl']);

test('grants-worker.sql chỉ cấp vl_videos và failed_jobs, không cấp mức schema', function () {
    $path = base_path('../infra/production/mysql/grants-worker.sql');
    if (! is_file($path)) {
        $this->markTestSkipped('infra/ không được mount trong container này.');
    }

    $grants = collect(file($path, FILE_IGNORE_NEW_LINES))->filter(fn ($l) => str_starts_with($l, 'GRANT '))->values();

    expect($grants)->toHaveCount(2)
        ->and($grants[0])->toContain('`vl_videos`')->not->toContain('DELETE')
        ->and($grants[1])->toContain('`failed_jobs`')
        ->and($grants->implode(' '))->not->toContain('.*');
});
