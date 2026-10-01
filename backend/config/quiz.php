<?php

/*
|--------------------------------------------------------------------------
| Giới hạn soạn quiz (T21, data-model §3.4, security S8)
|--------------------------------------------------------------------------
|
| Không đọc từ .env: các con số này gắn với schema/CHECK
| (`chk_quiz_attempts_question_count` = 1..200) và với giới hạn hiển thị, đổi
| chúng là quyết định của PO/DBA, không phải cấu hình từng môi trường.
| `max_questions` KHÔNG được vượt 200 khi chưa sửa CHECK của `quiz_attempts`.
|
*/

return [

    /** Số câu hỏi tối đa mỗi quiz (chờ PO xác nhận — data-model §3.4). */
    'max_questions' => 200,

    /** Số lựa chọn mỗi câu (MVP: đúng 4, đúng 1 đáp án đúng — US-007 BR1). */
    'options_per_question' => 4,

    /** Độ dài tối đa (ký tự) của nội dung câu hỏi / lời giải. */
    'content_max_length' => 5000,

    /** Độ dài tối đa (ký tự) của một lựa chọn. */
    'option_max_length' => 1000,

    /** Thời gian làm bài tối đa (phút) khi bật giới hạn (api-contract §2.5). */
    'max_time_limit_minutes' => 300,

    /** Trần body (KB) cho request soạn câu hỏi / quiz — middleware `body.limit`. */
    'question_body_max_kb' => 128,
    'small_body_max_kb' => 16,

];
