<?php

namespace App\Enums;

enum ConsentType: string
{
    case PrivacyPolicy = 'privacy_policy';
    case Terms = 'terms';
    case ParentConsent = 'parent_consent';
    case Marketing = 'marketing';
    // US-020: giáo viên đồng ý công khai ảnh, họ tên, giới thiệu (bằng chứng suốt vòng đời tài khoản).
    case TeacherPublicProfile = 'teacher_public_profile';
}
