# Worker transcode VideoLab (T12, ADR-002 §3a.3) — SANDBOX:
# - dựa trên image php của dự án (đã có đủ extension, www-data đã khớp UID/GID host) + CHỈ thêm ffmpeg/ffprobe
#   (image php thường không cài ffmpeg → bề mặt tấn công ffmpeg tách khỏi php-fpm);
# - chạy bằng www-data (không root); KHÔNG chứa .env/secret: biến môi trường cấp tường minh trong docker-compose;
# - mã nguồn không COPY vào image: compose mount ../backend chỉ đọc (local); production build image riêng.
# Build image php trước: `docker compose build php` (tag vitaminvui-php:8.3).
ARG BASE_IMAGE=vitaminvui-php:8.3
FROM ${BASE_IMAGE}

USER root

RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY php-worker.ini /usr/local/etc/php/conf.d/zz-worker.ini

WORKDIR /var/www/backend

USER www-data

CMD ["php", "artisan", "queue:work", "redis_video", "--queue=video", "--tries=2", "--timeout=3600", "--sleep=3", "--max-time=3600"]
