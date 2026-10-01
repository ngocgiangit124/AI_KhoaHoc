<?php

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;

/**
 * Câu hỏi đã nằm trong ít nhất 1 lượt làm bài (đang làm hoặc đã nộp) chưa?
 * Nếu rồi, sửa/xoá phải theo copy-on-write (data-model §3.4) — không bao giờ
 * UPDATE tại chỗ nội dung/đáp án mà lượt cũ còn tham chiếu.
 *
 * Nguồn sự thật là `quiz_attempts.question_ids` (chốt lúc bắt đầu làm).
 *
 * Tính đúng đắn dưới đồng thời: caller PHẢI đang giữ khoá `quizzes` `FOR
 * UPDATE` của quiz chứa câu này (`ContentLock::quiz`). T22 khi TẠO lượt làm
 * PHẢI khoá cùng hàng `quizzes` (FOR UPDATE/FOR SHARE) TRƯỚC KHI đọc danh sách
 * câu hỏi + INSERT `quiz_attempts` — nhờ đó "kiểm tra đã dùng chưa" và "tạo
 * lượt làm chốt câu hỏi" không thể chen nhau (nếu không, lượt làm mới có thể
 * chốt câu hỏi vừa bị sửa tại chỗ).
 */
final class QuestionUsage
{
    public static function isUsed(Quiz $quiz, QuizQuestion $question): bool
    {
        return QuizAttempt::query()
            ->where('quiz_id', $quiz->getKey())
            ->whereRaw('JSON_CONTAINS(question_ids, CAST(? AS JSON))', [(string) (int) $question->getKey()])
            ->exists();
    }
}
