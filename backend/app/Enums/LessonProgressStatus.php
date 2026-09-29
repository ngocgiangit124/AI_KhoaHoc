<?php

namespace App\Enums;

/**
 * `lesson_progress.status` (US-008, data-model §3.3). Không bao giờ quay lại
 * `in_progress` sau khi đã `completed` (xem lại không đổi trạng thái).
 */
enum LessonProgressStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
