<?php

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Support\Facades\DB;

/**
 * BE-backlog-1 (FA5): cờ `has_attempts` cho API soạn quiz của admin — để FE báo trước "sửa sẽ tạo bản sao câu hỏi".
 * Mỗi lần gọi tối đa 1 truy vấn cho cả danh sách (không N+1). Chỉ đọc, không khoá (cờ chỉ để hiển thị; nơi quyết định
 * copy-on-write vẫn là QuizContentService::hasAttempts dưới khoá).
 */
class QuizAttemptUsage
{
    /**
     * Gắn `has_attempts` (bool) vào từng quiz: có ít nhất 1 lượt làm (đang làm hoặc đã nộp) trỏ tới quiz.
     *
     * @param  iterable<Quiz>  $quizzes
     */
    public function markQuizzes(iterable $quizzes): void
    {
        $ids = [];
        foreach ($quizzes as $quiz) {
            $ids[] = (int) $quiz->getKey();
        }

        $used = $ids === []
            ? []
            : DB::table('quiz_attempts')->whereIn('quiz_id', $ids)->distinct()->pluck('quiz_id')->map(fn ($i): int => (int) $i)->all();
        $used = array_flip($used);

        foreach ($quizzes as $quiz) {
            $quiz->setAttribute('has_attempts', isset($used[(int) $quiz->getKey()]));
        }
    }

    /**
     * Gắn `has_attempts` (bool) vào từng câu hỏi: id câu nằm trong `question_ids` của ít nhất 1 lượt làm.
     * Một truy vấn EXISTS tương quan, lọc theo `quiz_id` (index) trước khi JSON_CONTAINS.
     *
     * @param  iterable<QuizQuestion>  $questions
     */
    public function markQuestions(iterable $questions): void
    {
        $ids = [];
        foreach ($questions as $question) {
            $ids[] = (int) $question->getKey();
        }

        $used = [];
        if ($ids !== []) {
            $used = DB::table('quiz_questions as qq')
                ->whereIn('qq.id', $ids)
                ->whereExists(function ($sub): void {
                    $sub->selectRaw('1')->from('quiz_attempts as a')
                        ->whereColumn('a.quiz_id', 'qq.quiz_id')
                        ->whereRaw('JSON_CONTAINS(a.question_ids, CAST(qq.id AS JSON))');
                })
                ->pluck('qq.id')->map(fn ($i): int => (int) $i)->all();
            $used = array_flip($used);
        }

        foreach ($questions as $question) {
            $question->setAttribute('has_attempts', isset($used[(int) $question->getKey()]));
        }
    }
}
