<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Quiz;
use App\Services\Privacy\DataExportService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/**
 * DBA (tasks.md T34 "Xong khi"): 1 học sinh có 5.000 `lesson_progress` + 500 `quiz_attempts`; POST /me/data-export < 2 s
 * trên Docker local. Nhóm `perf` để chạy riêng: `pest --group=perf`.
 */
test('xuat du lieu voi 5.000 tien do + 500 luot quiz < 2 giay va so truy van khong doi', function () {
    $me = vvT34Student();
    $course = Course::factory()->published()->paid()->create();
    $chapter = Chapter::factory()->for($course)->create();
    $quiz = Quiz::factory()->forCourse($course)->create();
    $now = now()->toDateTimeString();

    $lessons = [];
    foreach (range(1, 5000) as $i) {
        $lessons[] = [
            'course_id' => $course->id, 'chapter_id' => $chapter->id, 'title' => "Bài $i", 'position' => $i,
            'video_source' => 'none', 'is_preview' => 0, 'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($lessons, 1000) as $chunk) {
        DB::table('lessons')->insert($chunk);
    }
    $lessonIds = DB::table('lessons')->where('chapter_id', $chapter->id)->pluck('id')->all();

    foreach (array_chunk($lessonIds, 1000) as $chunk) {
        DB::table('lesson_progress')->insert(array_map(fn ($id) => [
            'user_id' => $me->id, 'lesson_id' => $id, 'course_id' => $course->id, 'watched_seconds' => 100, 'last_position_seconds' => 5,
            'status' => 'completed', 'completed_at' => $now, 'last_accessed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    $attempts = [];
    foreach (range(1, 500) as $i) {
        $attempts[] = [
            'user_id' => $me->id, 'quiz_id' => $quiz->id, 'course_id' => $course->id, 'question_ids' => '[1,2,3]',
            'answers' => '{"1":1}', 'result' => '{"1":{"selected":1,"correct":1,"ok":true}}', 'started_at' => $now, 'submitted_at' => $now,
            'total_questions' => 3, 'correct_count' => 2, 'score' => 6.67, 'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($attempts, 250) as $chunk) {
        DB::table('quiz_attempts')->insert($chunk);
    }

    $start = hrtime(true);
    $r = vvT34Export()->assertOk();
    $ms = (hrtime(true) - $start) / 1_000_000;

    $data = $r->json();
    expect($data['learning_progress'])->toHaveCount(5000)->and($data['quiz_attempts'])->toHaveCount(500)
        ->and($ms)->toBeLessThan(2000);

    DB::enableQueryLog();
    app(DataExportService::class)->build($me);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries)->toBeLessThanOrEqual(25);

    fwrite(STDERR, sprintf("\n[T34 perf] export 5000+500 dòng: %.0f ms, %d truy vấn, %d byte\n", $ms, $queries, strlen($r->getContent())));
})->group('perf');
