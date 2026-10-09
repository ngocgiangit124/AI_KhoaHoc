# T35-1 (ADR-008 §8.2): build image backend và worker-video. Chạy từ GỐC REPO:
#   VV_REGISTRY=vv-local IMAGE_TAG=dev docker buildx bake -f infra/production/docker-bake.hcl --load backend worker-video
# CI (T35-3): VV_REGISTRY=ghcr.io/ngocgiangit124 IMAGE_TAG=<sha 40 ký tự> ... --push
# Chuỗi phụ thuộc: php-base (infra/php/Dockerfile, UID/GID 10001) -> backend (infra/php/Dockerfile.prod) -> worker-video (+ ffmpeg).

variable "VV_REGISTRY" {
  default = "vv-local"
}

variable "IMAGE_TAG" {
  default = "dev"
}

# Phải bằng `x-vv-compose-version` trong docker-compose.yml (deploy.sh từ chối khi hai số khác nhau). Tăng cả hai khi đổi
# hợp đồng giữa image và compose (tên biến env bắt buộc, đường dẫn, user...). check-images.sh kiểm hai số khớp nhau.
variable "COMPOSE_VERSION" {
  default = "1"
}

# Base image ghim bản vá + digest (cập nhật có chủ ý: đổi dòng này, build lại, chạy smoke). Ghi chú ngày: 2026-10-10.
variable "PHP_BASE_REF" {
  default = "docker-image://php:8.3.35-fpm@sha256:ed67b5fcd7600614dd651621a04214fbe16d373262cb31eb50bc224c18cf0e88"
}

variable "COMPOSER_REF" {
  default = "docker-image://composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac"
}

group "default" {
  targets = ["backend", "worker-video"]
}

target "_common" {
  platforms = ["linux/amd64"]
}

# Image trung gian: build NGUYÊN infra/php/Dockerfile (không sửa) với UID/GID 10001 và base ghim digest.
# `FROM php:8.3-fpm` / `COPY --from=composer:2` trong Dockerfile gốc được thay bằng named context cùng tên.
target "php-base" {
  inherits   = ["_common"]
  context    = "infra/php"
  dockerfile = "Dockerfile"
  args = {
    UID = "10001"
    GID = "10001"
  }
  contexts = {
    "php:8.3-fpm" = PHP_BASE_REF
    "composer:2"  = COMPOSER_REF
  }
}

target "backend" {
  inherits   = ["_common"]
  context    = "."
  dockerfile = "infra/php/Dockerfile.prod"
  contexts = {
    "vv-php-base" = "target:php-base"
  }
  args = {
    VV_REVISION        = IMAGE_TAG
    VV_COMPOSE_VERSION = COMPOSE_VERSION
  }
  tags = ["${VV_REGISTRY}/vitaminvui-backend:${IMAGE_TAG}"]
  labels = {
    "org.opencontainers.image.revision" = IMAGE_TAG
    "org.opencontainers.image.source"   = "https://github.com/ngocgiangit124/AI_KhoaHoc"
    "vv.compose-version"                = COMPOSE_VERSION
  }
}

# worker-video: Dockerfile có sẵn (không sửa), BASE_IMAGE = image backend cùng tag (mã nguồn + extension), chỉ thêm ffmpeg.
target "worker-video" {
  inherits   = ["_common"]
  context    = "infra/worker-video"
  dockerfile = "Dockerfile"
  contexts = {
    "vv-backend" = "target:backend"
  }
  args = {
    BASE_IMAGE = "vv-backend"
  }
  tags = ["${VV_REGISTRY}/vitaminvui-worker-video:${IMAGE_TAG}"]
  labels = {
    "org.opencontainers.image.revision" = IMAGE_TAG
    "org.opencontainers.image.source"   = "https://github.com/ngocgiangit124/AI_KhoaHoc"
    "vv.compose-version"                = COMPOSE_VERSION
  }
}
