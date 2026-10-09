# Build image production web + admin (T35-2, ADR-008 §8.9). Chạy từ thư mục gốc repo:
#   VV_REGISTRY=vv-local IMAGE_TAG=dev VV_ENV=production \
#   docker buildx bake -f frontend/docker-bake.hcl --load web admin
# NEXT_PUBLIC_* lấy từ biến môi trường (CI: `vars` của GitHub Environment) — công khai, KHÔNG phải secret.

variable "VV_REGISTRY" { default = "vv-local" }
variable "IMAGE_TAG" { default = "dev" }
variable "VV_ENV" { default = "production" } # staging | production
variable "VCS_REF" { default = "" }

variable "NEXT_PUBLIC_API_URL" { default = "" }
variable "NEXT_PUBLIC_SITE_URL" { default = "" }
variable "NEXT_PUBLIC_STATIC_URL" { default = "" }
variable "NEXT_PUBLIC_VIDEO_HOSTS" { default = "" }
variable "NEXT_PUBLIC_TURNSTILE_SITE_KEY" { default = "" }
variable "NEXT_PUBLIC_MOMO_HOSTS" { default = "" }
variable "NEXT_PUBLIC_ADMIN_API_URL" { default = "" }
variable "NEXT_PUBLIC_ADMIN_URL" { default = "" }
variable "NEXT_PUBLIC_VIDEO_UPLOAD_URL" { default = "" }

group "default" { targets = ["web", "admin"] }

target "_common" {
  context   = "frontend"
  dockerfile = "Dockerfile"
  platforms = ["linux/amd64"]
}

target "web" {
  inherits = ["_common"]
  args = {
    APP                            = "web"
    PORT                           = "3000"
    VV_ENV                         = VV_ENV
    VCS_REF                        = VCS_REF
    NEXT_PUBLIC_API_URL            = NEXT_PUBLIC_API_URL
    NEXT_PUBLIC_SITE_URL           = NEXT_PUBLIC_SITE_URL
    NEXT_PUBLIC_STATIC_URL         = NEXT_PUBLIC_STATIC_URL
    NEXT_PUBLIC_VIDEO_HOSTS        = NEXT_PUBLIC_VIDEO_HOSTS
    NEXT_PUBLIC_TURNSTILE_SITE_KEY = NEXT_PUBLIC_TURNSTILE_SITE_KEY
    NEXT_PUBLIC_MOMO_HOSTS         = NEXT_PUBLIC_MOMO_HOSTS
  }
  tags = ["${VV_REGISTRY}/vitaminvui-web:${VV_ENV}-${IMAGE_TAG}"]
}

target "admin" {
  inherits = ["_common"]
  args = {
    APP                            = "admin"
    PORT                           = "3001"
    VV_ENV                         = VV_ENV
    VCS_REF                        = VCS_REF
    NEXT_PUBLIC_ADMIN_API_URL      = NEXT_PUBLIC_ADMIN_API_URL
    NEXT_PUBLIC_ADMIN_URL          = NEXT_PUBLIC_ADMIN_URL
    NEXT_PUBLIC_STATIC_URL         = NEXT_PUBLIC_STATIC_URL
    NEXT_PUBLIC_VIDEO_UPLOAD_URL   = NEXT_PUBLIC_VIDEO_UPLOAD_URL
    NEXT_PUBLIC_TURNSTILE_SITE_KEY = NEXT_PUBLIC_TURNSTILE_SITE_KEY
  }
  tags = ["${VV_REGISTRY}/vitaminvui-admin:${VV_ENV}-${IMAGE_TAG}"]
}
