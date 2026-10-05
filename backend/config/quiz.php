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

];
