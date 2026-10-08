<?php

namespace App\Enums;

enum ConsentType: string
{
    case PrivacyPolicy = 'privacy_policy';
    case Terms = 'terms';
    /** @deprecated ADR-006 — không ghi mới; giữ giá trị cho dữ liệu/enum cũ. */
    case ParentConsent = 'parent_consent';
    case Marketing = 'marketing';
    // US-020: giáo viên đồng ý công khai ảnh, họ tên, giới thiệu (bằng chứng suốt vòng đời tài khoản).
    case TeacherPublicProfile = 'teacher_public_profile';
}
