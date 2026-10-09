<?php

namespace App\Services\Quiz;

use App\Exceptions\DomainException;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Learning\LessonAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Làm quiz của học sinh (US-007). Nguyên tắc:
 * - Đồng hồ do server quyết định: `expires_at = started_at + time_limit`; hết hạn khi `now > expires_at + ân hạn`
 *   (config quiz.submit_grace_seconds). Quá hạn: autosave bị từ chối (409 QUIZ_ATTEMPT_EXPIRED) và lượt được tự nộp
 *   (auto_submitted = true, submitted_at = expires_at) bằng đáp án đã autosave — làm lười ở mọi lần đọc/ghi và quét
 *   nền bằng `quizzes:auto-submit-expired`. Nộp tay từ `expires_at` trở đi (trong ân hạn) cũng ghi auto_submitted = true.
 * - Thứ tự khoá cố định: quizzes (sharedLock) → quiz_attempts. Tạo lượt đọc dòng quiz bằng sharedLock để lọt
 *   khoảng giữa "admin kiểm có lượt" (QuizContentService, lockForUpdate quiz) và "sửa câu tại chỗ" không xảy ra.
 * - Nộp bài/tự nộp: khoá dòng lượt (lockForUpdate theo PK) + UPDATE ... WHERE submitted_at IS NULL → double submit,
 *   2 tab và command nền chỉ có 1 bên chốt điểm; bên còn lại nhận lại kết quả đã chốt (idempotent).
 * - `answers` chỉ ghi bằng JSON_SET (nguyên tử); lượt cũ đọc câu bằng withTrashed() (copy-on-write).
 */
class QuizAttemptService
{
    public function __construct(private readonly LessonAccessService $access) {}

    /**
     * Bắt đầu hoặc tiếp tục lượt đang làm.
     *
     * @return array{attempt: QuizAttempt, created: bool}
     *
     * @throws DomainException 403 COURSE_NOT_OWNED, 404, 422 QUIZ_NOT_READY
     */
    public function start(User $user, Quiz $quiz): array
    {
        $course = $quiz->course;

        if ($course === null) {
            throw $this->notFound();
        }

        $this->access->assertCanLearnCourse($user, $course);

        return DB::transaction(function () use ($user, $quiz): array {
            $locked = Quiz::query()->whereKey($quiz->getKey())->sharedLock()->first();

            if ($locked === null) {
                throw $this->notFound();
            }

            $existing = $this->lockInProgress((int) $user->getKey(), (int) $locked->getKey());

            if ($existing !== null) {
                if (! $this->isExpired($existing)) {
                    return ['attempt' => $existing, 'created' => false];
                }

                $existing = $this->settle($existing);
            }

            $questionIds = QuizQuestion::query()
                ->where('quiz_id', $locked->getKey())
                ->orderBy('position')->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            if ($questionIds === []) {
                throw new DomainException('QUIZ_NOT_READY', 'Bài kiểm tra chưa sẵn sàng.', 422);
            }

            $now = now();
            $minutes = config('features.quiz_time_limit') ? $locked->time_limit_minutes : null;

            try {
                $id = DB::table('quiz_attempts')->insertGetId([
                    'user_id' => $user->getKey(),
                    'quiz_id' => $locked->getKey(),
                    'course_id' => $locked->course_id,
                    'question_ids' => json_encode($questionIds),
                    'answers' => '{}',
                    'started_at' => $now,
                    'expires_at' => $minutes === null ? null : $now->copy()->addMinutes((int) $minutes),
                    'auto_submitted' => false,
                    'total_questions' => count($questionIds),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $e) {
                // 2 tab bắt đầu cùng lúc: unique (user, quiz, in_progress_flag) → dùng lại lượt của bên thắng.
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }

                $winner = QuizAttempt::query()
                    ->where('user_id', $user->getKey())->where('quiz_id', $locked->getKey())
                    ->whereNull('submitted_at')->first();

                if ($winner === null) {
                    throw $e;
                }

                return ['attempt' => $winner, 'created' => false];
            }

            return ['attempt' => QuizAttempt::query()->findOrFail($id), 'created' => true];
        }, 3);
    }

    /**
     * Autosave 1 câu (idempotent: gửi lại cùng giá trị vẫn 204; đổi đáp án = ghi đè).
     *
     * @throws DomainException 404, 403, 409 QUIZ_ATTEMPT_SUBMITTED|QUIZ_ATTEMPT_EXPIRED, 422 QUIZ_QUESTION_NOT_IN_ATTEMPT|QUIZ_OPTION_INVALID
     */
    public function saveAnswer(User $user, int $attemptId, int $questionId, int $optionId): void
    {
        $attempt = $this->findOwned($user, $attemptId);
        $this->access->assertOwnsCourse($user, (int) $attempt->course_id);

        $this->assertWritable($attempt);

        if (! in_array($questionId, $attempt->question_ids, true)) {
            throw new DomainException('QUIZ_QUESTION_NOT_IN_ATTEMPT', 'Câu hỏi không thuộc lượt làm bài này.', 422);
        }

        // Lựa chọn phải thuộc đúng câu hỏi (không tin client).
        if (! QuizOption::query()->whereKey($optionId)->where('question_id', $questionId)->exists()) {
            throw new DomainException('QUIZ_OPTION_INVALID', 'Đáp án không thuộc câu hỏi này.', 422);
        }

        // Nguyên tử trong 1 câu lệnh (không mất cập nhật khi 2 request song song); mọi giá trị đều binding,
        // path `$."<question_id>"` dựng từ số nguyên đã ép; CAST ... AS SIGNED để lưu JSON số nguyên, không phải chuỗi.
        $affected = DB::update(
            'UPDATE quiz_attempts SET answers = JSON_SET(answers, ?, CAST(? AS SIGNED)), updated_at = ?'
            .' WHERE id = ? AND submitted_at IS NULL AND (expires_at IS NULL OR expires_at >= ?)',
            ['$."'.$questionId.'"', $optionId, now(), $attempt->getKey(), $this->deadlineCutoff()],
        );

        if ($affected === 0) {
            // Nộp/hết hạn xen giữa kiểm tra và ghi: phân loại lại từ dữ liệu mới.
            $this->assertWritable($this->findOwned($user, $attemptId));
        }
    }

    /**
     * Nộp bài. Idempotent: lượt đã nộp (kể cả do tab khác/command nền) trả lại kết quả đã chốt.
     *
     * @throws DomainException 404, 403
     */
    public function submit(User $user, int $attemptId): QuizAttempt
    {
        $attempt = $this->findOwned($user, $attemptId);
        $this->access->assertOwnsCourse($user, (int) $attempt->course_id);

        return DB::transaction(function () use ($attempt): QuizAttempt {
            $locked = QuizAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();

            return $locked->isSubmitted() ? $locked : $this->settle($locked, true);
        }, 3);
    }

    /**
     * Xem lượt (đang làm hoặc kết quả). Lượt quá hạn được tự nộp trước khi trả.
     *
     * @throws DomainException 404, 403
     */
    public function show(User $user, int $attemptId): QuizAttempt
    {
        $attempt = $this->findOwned($user, $attemptId);
        $this->access->assertOwnsCourse($user, (int) $attempt->course_id);

        return $this->finalizeIfExpired($attempt);
    }

    /**
     * Lịch sử các lượt của học sinh với quiz (mới nhất trước) + điểm cao nhất.
     *
     * @return array{attempts: Collection<int, QuizAttempt>, best_score: float|null, attempts_count: int}
     *
     * @throws DomainException 403, 404
     */
    public function history(User $user, Quiz $quiz): array
    {
        $course = $quiz->course;

        if ($course === null) {
            throw $this->notFound();
        }

        $this->access->assertCanLearnCourse($user, $course);

        $open = QuizAttempt::query()
            ->where('user_id', $user->getKey())->where('quiz_id', $quiz->getKey())
            ->whereNull('submitted_at')->first();

        if ($open !== null) {
            $this->finalizeIfExpired($open);
        }

        $attempts = QuizAttempt::query()
            ->where('user_id', $user->getKey())->where('quiz_id', $quiz->getKey())
            ->orderByDesc('id')
            ->limit((int) config('quiz.history_limit', 50))
            ->get();

        $stats = DB::table('quiz_attempts')
            ->where('user_id', $user->getKey())->where('quiz_id', $quiz->getKey())
            ->whereNotNull('submitted_at')
            ->selectRaw('COUNT(*) AS n, MAX(score) AS best')
            ->first();

        return [
            'attempts' => $attempts,
            'best_score' => $stats === null || $stats->best === null ? null : (float) $stats->best,
            'attempts_count' => (int) ($stats->n ?? 0),
        ];
    }

    /**
     * Tự nộp lượt quá hạn (command nền + làm lười). Trả lượt mới nhất; không làm gì nếu đã nộp/chưa quá hạn.
     */
    public function finalizeIfExpired(QuizAttempt $attempt): QuizAttempt
    {
        if ($attempt->isSubmitted() || ! $this->isExpired($attempt)) {
            return $attempt;
        }

        return DB::transaction(function () use ($attempt): QuizAttempt {
            $locked = QuizAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();

            return $locked->isSubmitted() || ! $this->isExpired($locked) ? $locked : $this->settle($locked);
        }, 3);
    }

    /**
     * Câu hỏi của lượt theo đúng thứ tự đã chốt (kể cả câu đã xoá mềm/thay thế) kèm lựa chọn. Có `is_correct`/
     * `explanation` ở model: CHỈ resource kết quả (đã nộp) được đọc; resource lượt đang làm không bao giờ.
     *
     * @return Collection<int, QuizQuestion>
     */
    public function questionsFor(QuizAttempt $attempt): Collection
    {
        $byId = QuizQuestion::query()->withTrashed()
            ->whereIn('id', $attempt->question_ids)
            ->with(['options' => fn ($q) => $q->withTrashed()])
            ->get()
            ->keyBy('id');

        $ordered = [];

        foreach ($attempt->question_ids as $id) {
            if ($byId->has($id)) {
                $ordered[] = $byId->get($id);
            }
        }

        return (new QuizQuestion)->newCollection($ordered);
    }

    /**
     * Điểm cao nhất mỗi quiz (chỉ lượt đã nộp) — dùng cho T23 (tiến độ khóa).
     *
     * @param  list<int>|null  $quizIds  null = mọi quiz của khóa
     * @return array<int, float> quiz_id => điểm thang 10
     */
    public function bestScoresForCourse(int $userId, int $courseId, ?array $quizIds = null): array
    {
        return QuizAttempt::query()
            ->where('user_id', $userId)->where('course_id', $courseId)
            ->whereNotNull('submitted_at')
            ->when($quizIds !== null, fn ($q) => $q->whereIn('quiz_id', $quizIds))
            ->groupBy('quiz_id')
            ->selectRaw('quiz_id, MAX(score) AS best')
            ->pluck('best', 'quiz_id')
            ->map(fn ($v): float => (float) $v)
            ->all();
    }

    /**
     * BE-backlog-1 (FW6): id lượt làm điểm cao nhất của học sinh, theo quiz (hoà điểm → lượt nộp sớm nhất, rồi id nhỏ).
     * 1 truy vấn theo (user_id, course_id) — index `quiz_attempts_user_course_index`; chỉ đọc 3 cột.
     *
     * @param  list<int>|null  $quizIds  null = mọi quiz của khóa
     * @return array<int, int> quiz_id => attempt_id
     */
    public function bestAttemptIdsForCourse(int $userId, int $courseId, ?array $quizIds = null): array
    {
        $rows = QuizAttempt::query()
            ->where('user_id', $userId)->where('course_id', $courseId)
            ->whereNotNull('submitted_at')->whereNotNull('score')
            ->when($quizIds !== null, fn ($q) => $q->whereIn('quiz_id', $quizIds))
            ->orderByDesc('score')->orderBy('submitted_at')->orderBy('id')
            ->get(['id', 'quiz_id']);

        $best = [];
        foreach ($rows as $row) {
            $best[(int) $row->quiz_id] ??= (int) $row->id;
        }

        return $best;
    }

    public function isExpired(QuizAttempt $attempt): bool
    {
        return $attempt->expires_at !== null
            && ! $attempt->isSubmitted()
            && $attempt->expires_at->lt($this->deadlineCutoff());
    }

    /** Mốc "còn hạn": `expires_at >= now - ân hạn`. */
    private function deadlineCutoff(): Carbon
    {
        return now()->subSeconds((int) config('quiz.submit_grace_seconds'));
    }

    /**
     * Chốt điểm lượt đang bị khoá. $manual = học sinh bấm nộp; nộp lúc `now >= expires_at` (kể cả trong ân hạn)
     * đánh dấu tự nộp; quá ân hạn thì lấy `expires_at` làm giờ nộp (không cho kéo dài thời gian).
     */
    private function settle(QuizAttempt $locked, bool $manual = false): QuizAttempt
    {
        $expired = $this->isExpired($locked);
        // Nộp tay lúc now >= expires_at (trong ân hạn) cũng tính là tự nộp (PO 2026-10-08).
        $pastDeadline = $locked->expires_at !== null && ! now()->lt($locked->expires_at);
        $auto = $expired || ! $manual || $pastDeadline;
        $submittedAt = $expired ? $locked->expires_at : now();

        $questions = $this->questionsFor($locked)->keyBy('id');
        $answers = $locked->answers;
        $result = [];
        $correctCount = 0;

        foreach ($locked->question_ids as $qid) {
            $correct = $questions->get($qid)?->options->firstWhere('is_correct', true)?->id;
            $selected = isset($answers[$qid]) ? (int) $answers[$qid] : null;
            $ok = $selected !== null && $correct !== null && $selected === (int) $correct;
            $correctCount += $ok ? 1 : 0;
            $result[(string) $qid] = ['selected' => $selected, 'correct' => $correct === null ? null : (int) $correct, 'ok' => $ok];
        }

        $total = max(1, (int) $locked->total_questions);

        DB::table('quiz_attempts')->where('id', $locked->getKey())->whereNull('submitted_at')->update([
            'submitted_at' => $submittedAt,
            'auto_submitted' => $auto,
            'correct_count' => $correctCount,
            'score' => round($correctCount / $total * 10, 2),
            'result' => json_encode($result),
            'updated_at' => now(),
        ]);

        return QuizAttempt::query()->findOrFail($locked->getKey());
    }

    private function lockInProgress(int $userId, int $quizId): ?QuizAttempt
    {
        $id = QuizAttempt::query()->where('user_id', $userId)->where('quiz_id', $quizId)->whereNull('submitted_at')->value('id');

        if ($id === null) {
            return null;
        }

        return QuizAttempt::query()->whereKey($id)->whereNull('submitted_at')->lockForUpdate()->first();
    }

    /** @throws DomainException 409 nếu đã nộp / đã hết hạn (hết hạn → tự nộp rồi mới báo) */
    private function assertWritable(QuizAttempt $attempt): void
    {
        if ($attempt->isSubmitted()) {
            throw new DomainException('QUIZ_ATTEMPT_SUBMITTED', 'Bài làm đã được nộp.', 409);
        }

        if ($this->isExpired($attempt)) {
            $this->finalizeIfExpired($attempt);

            throw new DomainException('QUIZ_ATTEMPT_EXPIRED', 'Đã hết thời gian làm bài; bài đã được nộp tự động.', 409);
        }
    }

    /** Lượt của chính học sinh; của người khác hoặc không tồn tại → 404 (không lộ tồn tại). */
    private function findOwned(User $user, int $attemptId): QuizAttempt
    {
        $attempt = QuizAttempt::query()->whereKey($attemptId)->where('user_id', $user->getKey())->first();

        return $attempt ?? throw $this->notFound();
    }

    private function notFound(): DomainException
    {
        return new DomainException('NOT_FOUND', 'Không tìm thấy bài làm.', 404);
    }
}
