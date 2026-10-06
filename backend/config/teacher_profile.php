<?php

/*
|--------------------------------------------------------------------------
| Hồ sơ giáo viên công khai (US-020, T36, ADR-005)
|--------------------------------------------------------------------------
|
| Toàn bộ là HẰNG, không đọc env: đổi giá trị = đổi code + review (câu chữ đồng ý là bằng chứng pháp lý).
*/

return [

    // BR3: số giáo viên tối đa ở trang chủ (kể cả người bị khoá/đổi vai trò vẫn còn cờ bật).
    'homepage_max' => 6,

    // Phiên bản câu chữ đồng ý, ghi vào `consents.policy_version` và `teacher_profiles.public_consent_version`.
    // Đổi câu chữ thì các đồng ý cũ VẪN hiệu lực (PO 2026-10-06).
    'consent_version' => '2026-10',

    // BR4: câu chữ hiển thị cho giáo viên khi tick đồng ý.
    'consent_text' => 'Tôi đồng ý công khai ảnh, họ tên và phần giới thiệu của tôi trên website VitaminVui',

    // BR7: cạnh tối đa (px) của ảnh đại diện sau khi cắt vuông.
    'avatar_max_edge' => 800,

    // BR3: `homepage_order` nằm trong 1..order_max.
    'order_max' => 999,

    'headline_max' => 120,

    'bio_max' => 600,

];
