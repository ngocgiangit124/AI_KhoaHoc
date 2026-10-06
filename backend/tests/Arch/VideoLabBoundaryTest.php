<?php

/*
 * ADR-002 §1: VideoLab là module độc lập (đóng vai "nhà cung cấp bên ngoài"), chỉ nói chuyện với app qua HTTP.
 * Không có phụ thuộc hợp lệ nào cần loại trừ: mọi lớp dùng chung (ngoại lệ, response lỗi, model) đều có bản riêng
 * trong `App\VideoLab`. Chỉ được dùng framework Laravel và config().
 */
arch('VideoLab khong phu thuoc Models/Services/Exceptions/Http/Support/Enums cua app')
    ->expect('App\VideoLab')
    ->not->toUse(['App\Models', 'App\Services', 'App\Exceptions', 'App\Http', 'App\Support', 'App\Enums', 'App\Policies', 'App\Jobs']);
