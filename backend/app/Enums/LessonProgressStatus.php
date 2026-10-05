<?php

namespace App\Enums;

enum LessonProgressStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
