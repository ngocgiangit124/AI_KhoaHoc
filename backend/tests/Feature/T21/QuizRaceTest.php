<?php

use Symfony\Component\Process\Process;

/**
 * QA T21 (chạy riêng: `pest --group=race`). Race thật bằng nhiều tiến trình PHP; dữ liệu commit thật, dọn trong finally.
 */
function vvQuizWorker(array $args): Process
{
    $p = new Process([PHP_BINARY, base_path('tests/Support/quiz_race_worker.php'), ...array_map('strval', $args)], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => env('DB_HOST', 'mysql'),
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ]);
    $p->setTimeout(60);

    return $p;
}

function vvQuizOnce(array $args): array
{
    $p = vvQuizWorker($args);
    $p->mustRun();

    return json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function vvQuizParallel(array $sets): array
{
    $procs = array_map(fn ($a) => vvQuizWorker($a), $sets);
    foreach ($procs as $p) {
        $p->start();
    }
    $res = [];
    foreach ($procs as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
        $res[] = json_decode(trim($p->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    return $res;
}

test('race: xoa chuong song song voi POST/PUT cau hoi -> khong 500/deadlock, trang thai nhat quan', function () {
    foreach (range(1, 3) as $round) {
        $s = vvQuizOnce(['setup']);

        try {
            $t = microtime(true) + 3.0;
            $results = vvQuizParallel([
                ['delete_chapter', $s['course'], $t, $s['c1']],
                ['add_q', $s['course'], $t, $s['quiz'], 'a'],
                ['add_q', $s['course'], $t, $s['quiz'], 'b'],
                ['put_q', $s['course'], $t, $s['quiz'], $s['q']],
            ]);

            expect(collect($results)->pluck('result')->all())->not->toContain('error');
            $st = vvQuizOnce(['state', $s['course']]);
            $q = collect($st['quizzes'])->firstWhere('id', $s['quiz']);
            // Quiz bị xoá mềm; câu hỏi giữ (kể cả các câu ghi trước khi xoá), position câu đang dùng không trùng.
            expect($q->deleted_at ?? $q['deleted_at'])->not->toBeNull();
            expect(count($st['positions']))->toBe(count(array_unique($st['positions'])));
        } finally {
            vvQuizOnce(['cleanup', $s['course'], $s['creator']]);
        }
    }
})->group('race');

test('race: tao quiz song song voi xoa bai cha -> ok hoac 422 QUIZ_PARENT_INVALID, khong quiz mo coi', function () {
    foreach (range(1, 3) as $round) {
        $s = vvQuizOnce(['setup']);

        try {
            $t = microtime(true) + 3.0;
            $results = vvQuizParallel([
                ['delete_lesson', $s['course'], $t, $s['c2'], $s['l2']],
                ['create_quiz', $s['course'], $t, $s['l2'], 'lesson'],
                ['create_quiz', $s['course'], $t, $s['l2'], 'lesson'],
            ]);

            expect(collect($results)->pluck('result')->all())->not->toContain('error');
            foreach (array_slice($results, 1) as $r) {
                if ($r['result'] === 'domain') {
                    expect($r['code'])->toBe('QUIZ_PARENT_INVALID')->and($r['status'])->toBe(422);
                }
            }
            // Bất biến: quiz còn sống không được trỏ tới bài đã xoá.
            $st = vvQuizOnce(['state', $s['course']]);
            $live = collect($st['quizzes'])->filter(fn ($q) => $q['lesson_id'] == $s['l2'] && $q['deleted_at'] === null);
            expect($live->count())->toBe(0);
        } finally {
            vvQuizOnce(['cleanup', $s['course'], $s['creator']]);
        }
    }
})->group('race');

test('race: tao quiz song song voi xoa chuong cha -> khong quiz song gan chuong da xoa', function () {
    $s = vvQuizOnce(['setup']);

    try {
        $t = microtime(true) + 3.0;
        $results = vvQuizParallel([
            ['delete_chapter', $s['course'], $t, $s['c2']],
            ['create_quiz', $s['course'], $t, $s['c2'], 'chapter'],
            ['create_quiz', $s['course'], $t, $s['l3'], 'lesson'],
        ]);

        expect(collect($results)->pluck('result')->all())->not->toContain('error');
        $st = vvQuizOnce(['state', $s['course']]);
        $live = collect($st['quizzes'])->filter(fn ($q) => ($q['chapter_id'] == $s['c2'] || $q['lesson_id'] == $s['l3']) && $q['deleted_at'] === null);
        expect($live->count())->toBe(0);
    } finally {
        vvQuizOnce(['cleanup', $s['course'], $s['creator']]);
    }
})->group('race');
