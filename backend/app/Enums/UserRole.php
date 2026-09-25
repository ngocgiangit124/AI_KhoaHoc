<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case PageManager = 'quan_ly_trang';
    case Teacher = 'giao_vien';
    case Student = 'hoc_sinh';

    public function isStaff(): bool
    {
        return $this === self::Admin || $this === self::PageManager;
    }
}
