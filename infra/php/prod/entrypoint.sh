#!/bin/sh
# T35-1 (ADR-008 §8.8): chạy trước mọi tiến trình backend (php-fpm, queue, scheduler, worker-video, migrate).
# VV_OPTIMIZE=0 bỏ qua bước cache (migrate, preflight của deploy.sh, worker-video rootfs chỉ đọc).
# `php artisan optimize` nạp app nên ProductionConfigGuard chạy ngay ở đây: cấu hình sai thì container thoát với mã khác 0,
# không chạy tiếp với cấu hình hỏng. Không in giá trị env.
set -eu

if [ "${VV_OPTIMIZE:-1}" != "0" ]; then
    php artisan optimize
fi

exec docker-php-entrypoint "$@"
