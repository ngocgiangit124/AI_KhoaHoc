<?php

use App\Models\AuditLog;

/**
 * QA T36 (chạy riêng: `pest --group=race`). Bổ sung race theo gợi ý của review: `setHomepage` + `erase` (T34 sẽ gọi) cùng
 * `withdraw`/`consent`/`content` trên MỘT giáo viên. Tái dùng worker/helper của TeacherProfileRaceTest: chạy bằng `pest --group=race tests/Feature/T36` (cả thư mục, để helper được nạp).
 */
test('QA race: bat trang chu + erase + rut/dong y + sua noi dung cung luc tren 1 giao vien -> khong deadlock/500, dong y va ho so nhat quan', function () {
    foreach (range(1, 4) as $round) {
        $s = vvT36RaceSetup(6, 1);
        $all = [...$s['pre'], ...$s['candidates'], $s['admin']];
        [$c] = $s['candidates'];

        try {
            // Có sẵn hồ sơ đã đồng ý để erase/withdraw có việc thật.
            expect(vvT36RaceOnce(['consent', $c, microtime(true)])['result'])->toBe('ok');

            $t = microtime(true) + 2.5;
            $res = vvT36RaceParallel([
                ['enable', $s['admin'], $c, $t], ['erase', $c, $t], ['withdraw', $c, $t],
                ['consent', $c, $t], ['content', $s['admin'], $c, $t, 'bio-race'], ['disable', $s['admin'], $c, $t],
            ]);

            foreach ($res as $r) {
                expect($r['result'])->toBeIn(['ok', 'domain'], json_encode($r));
                if ($r['result'] === 'domain') {
                    // NOT_FOUND: hồ sơ vừa bị erase giữa hai bước; không có mã nào khác hợp lệ.
                    expect($r['code'])->toBeIn(['NOT_FOUND']);
                }
            }

            $state = vvT36RaceOnce(['state', $c]);
            // Bất biến: có dòng hồ sơ đang đồng ý <=> đúng 1 dòng consents còn hiệu lực. Không bao giờ 2 dòng hiệu lực.
            expect($state['consents'])->toBe($state['profile_with_consent'], json_encode($state));
            expect($state['consents'])->toBeLessThanOrEqual(1);
            if ($state['profile_rows'] === 0) {
                expect($state['consents'])->toBe(0);
            }
            expect(AuditLog::query()->where('action', 'teacher_profile.erase')->where('subject_id', $c)->count())->toBeLessThanOrEqual(1);
        } finally {
            vvT36RaceOnce(['cleanup', ...$all]);
        }
    }
})->group('race');
