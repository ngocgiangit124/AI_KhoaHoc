# syntax=docker/dockerfile:1.7
# Image production cho apps/web và apps/admin (T35-2, ADR-008 §8.9). Build từ thư mục frontend/:
#   docker buildx bake -f docker-bake.hcl web admin     (xem frontend/README.md, mục "Production image")
# Dockerfile.dev KHÔNG dùng cho production.
#
# NEXT_PUBLIC_* nhúng vào bundle lúc build => mỗi môi trường (staging/production) một image.
# TUYỆT ĐỐI không thêm ARG/ENV cho secret (INTERNAL_API_TOKEN...): chúng chỉ nằm trong env file trên server (lúc chạy).

ARG NODE_IMAGE=node:22-bookworm-slim@sha256:c3de60bf2f9dd0ac6370e6117950ff62d6e339527e7472301c9c78a017978392

# ---- deps: cài phụ thuộc theo lockfile (chỉ cần các package.json nên lớp này cache tốt) ----
FROM ${NODE_IMAGE} AS deps
ENV PNPM_HOME=/pnpm
ENV PATH="$PNPM_HOME:$PATH"
RUN corepack enable && corepack prepare pnpm@9.15.9 --activate
WORKDIR /workspace
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./
COPY apps/web/package.json apps/web/package.json
COPY apps/admin/package.json apps/admin/package.json
COPY packages/api-client/package.json packages/api-client/package.json
COPY packages/ui/package.json packages/ui/package.json
COPY packages/config/package.json packages/config/package.json
RUN --mount=type=cache,id=vv-pnpm-store,target=/pnpm/store \
    pnpm install --frozen-lockfile --store-dir /pnpm/store

# ---- build ----
FROM deps AS build
ARG APP
RUN test "$APP" = "web" || test "$APP" = "admin" || (echo "APP phải là web hoặc admin" >&2; exit 1)
# Giá trị công khai (build arg). Mặc định chuỗi rỗng để biến luôn được định nghĩa; thiếu biến bắt buộc thì env.ts báo lỗi rõ ràng.
ARG NEXT_PUBLIC_API_URL=""
ARG NEXT_PUBLIC_SITE_URL=""
ARG NEXT_PUBLIC_STATIC_URL=""
ARG NEXT_PUBLIC_VIDEO_HOSTS=""
ARG NEXT_PUBLIC_TURNSTILE_SITE_KEY=""
ARG NEXT_PUBLIC_MOMO_HOSTS=""
ARG NEXT_PUBLIC_ADMIN_API_URL=""
ARG NEXT_PUBLIC_ADMIN_URL=""
ARG NEXT_PUBLIC_VIDEO_UPLOAD_URL=""
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL \
    NEXT_PUBLIC_SITE_URL=$NEXT_PUBLIC_SITE_URL \
    NEXT_PUBLIC_STATIC_URL=$NEXT_PUBLIC_STATIC_URL \
    NEXT_PUBLIC_VIDEO_HOSTS=$NEXT_PUBLIC_VIDEO_HOSTS \
    NEXT_PUBLIC_TURNSTILE_SITE_KEY=$NEXT_PUBLIC_TURNSTILE_SITE_KEY \
    NEXT_PUBLIC_MOMO_HOSTS=$NEXT_PUBLIC_MOMO_HOSTS \
    NEXT_PUBLIC_ADMIN_API_URL=$NEXT_PUBLIC_ADMIN_API_URL \
    NEXT_PUBLIC_ADMIN_URL=$NEXT_PUBLIC_ADMIN_URL \
    NEXT_PUBLIC_VIDEO_UPLOAD_URL=$NEXT_PUBLIC_VIDEO_UPLOAD_URL \
    NEXT_TELEMETRY_DISABLED=1 \
    NODE_ENV=production
COPY apps/$APP apps/$APP
COPY packages packages
WORKDIR /workspace/apps/${APP}
RUN pnpm exec next build
# Chuẩn bị cây chạy: standalone + static + public (không chép .env, e2e, test).
RUN mkdir -p /out/apps/${APP}/.next /out/apps/${APP}/public \
 && cp -a .next/standalone/. /out/ \
 && cp -a .next/static /out/apps/${APP}/.next/static \
 && if [ -d public ]; then cp -a public/. /out/apps/${APP}/public/; fi

# ---- runner ----
FROM ${NODE_IMAGE} AS runner
ARG APP
ARG VV_ENV=""
ARG VCS_REF=""
ARG PORT=3000
LABEL vv.env="${VV_ENV}" \
      org.opencontainers.image.revision="${VCS_REF}" \
      org.opencontainers.image.title="vitaminvui-${APP}"
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    HOSTNAME=0.0.0.0 \
    APP=${APP}
WORKDIR /app
# Mã thuộc root, user `node` chỉ đọc (T35-1 S7): Next bị RCE cũng không sửa được server.js/chunk JS. Chỉ thư mục cache ảnh của Next
# ghi được (compose còn mount tmpfs lên đúng đường dẫn này khi chạy với rootfs chỉ đọc).
COPY --from=build /out/ ./
RUN mkdir -p apps/${APP}/.next/cache && chown node:node apps/${APP}/.next/cache
USER node
# web 3000, admin 3001 (hợp đồng ADR-008 §8.3); compose publish 127.0.0.1:<cổng>.
ENV PORT=${PORT}
EXPOSE ${PORT}
CMD ["sh", "-c", "exec node apps/${APP}/server.js"]
