<?php

namespace App\Enums;

/**
 * `subjects.status` (US-011, data-model §3.2). Không có trong danh sách Enum
 * liệt kê ở api-contract §3 — thêm để nhất quán với quy ước cast enum của dự
 * án (không dùng chuỗi thô); T06 (CRUD chuyên đề) có thể mở rộng nếu cần.
 */
enum SubjectStatus: string
{
    case Active = 'active';
    case Hidden = 'hidden';
}
