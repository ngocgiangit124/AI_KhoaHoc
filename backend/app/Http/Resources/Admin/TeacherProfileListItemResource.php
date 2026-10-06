<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;

/** Dòng trong danh sách quản lý: như `TeacherProfileResource` nhưng `abilities` không có `consent` (api-contract §2.9). */
class TeacherProfileListItemResource extends TeacherProfileResource
{
    /**
     * @return array<string, bool>
     */
    protected function abilities(Request $request, User $user): array
    {
        return ['edit_content' => $user->isTeacher(), 'manage_homepage' => true];
    }
}
