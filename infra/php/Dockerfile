FROM php:8.3-fpm

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        intl \
        bcmath \
        zip \
        pcntl \
        exif \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# C4-L3: php.ini-production (display_errors=Off, display_startup_errors=Off, log_errors=On, expose_php=Off, zend.assertions=-1).
# Lỗi trước khi Laravel nạp (vendor/ hỏng lúc đổi symlink release, lỗi cú pháp config, hết bộ nhớ) không được in đường dẫn
# tuyệt đối vào response. Dev local muốn xem lỗi PHP thô: thêm infra/docker-compose.debug.yml (xem file đó), KHÔNG sửa image.
# GHI CHÚ T35 (image staging/production): dùng NGUYÊN Dockerfile này (hoặc kế thừa FROM image này), không thêm lại display_errors.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY conf.d/zz-vitaminvui.ini /usr/local/etc/php/conf.d/zz-vitaminvui.ini

# Chạy container với UID/GID của host để file tạo ra không thuộc root trên WSL.
RUN groupmod -g "${GID}" www-data 2>/dev/null || groupadd -g "${GID}" appgroup \
    && usermod -u "${UID}" -g "${GID}" www-data 2>/dev/null || true

WORKDIR /var/www

USER www-data

CMD ["php-fpm"]
