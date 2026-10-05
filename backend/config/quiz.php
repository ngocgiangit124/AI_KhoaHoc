<?php

/*
|--------------------------------------------------------------------------
| Giới hạn soạn quiz (data-model §3.4, README quyết định 17, Security S8)
|--------------------------------------------------------------------------
*/

return [

    // Số câu hỏi tối đa/quiz (khớp CHECK chk_quiz_attempts_question_count của T22).
    'max_questions' => 200,

    'options_per_question' => 4,

    'max_question_chars' => 5000,

    'max_option_chars' => 1000,

    'max_time_limit_minutes' => 300,

    // T22: ân hạn (giây) sau `expires_at` cho autosave/nộp bài do độ trễ mạng; quá hạn này server tự nộp bằng
    // đáp án đã autosave (đồng hồ do server quyết định, không tin client).
    // Số lượt tối đa trả về ở lịch sử lượt làm của 1 quiz.
    'history_limit' => 50,

    'submit_grace_seconds' => (int) env('QUIZ_SUBMIT_GRACE_SECONDS', 30),

];
