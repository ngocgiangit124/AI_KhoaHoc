import {
  authFetch as authFetchBase,
  publicFetch as publicFetchBase,
  type AuthFetchOptions,
  type PublicFetchOptions,
} from "@vitaminvui/api-client";
import { env } from "@/env";

/**
 * Gọi endpoint công khai trên host admin-api (ví dụ `/csrf-token` trước khi đăng nhập) từ
 * trình duyệt. Admin hầu như không có endpoint công khai khác — đa số route cần đăng nhập.
 */
export function publicFetch<T>(path: string, options?: PublicFetchOptions): Promise<T> {
  return publicFetchBase<T>(env.NEXT_PUBLIC_ADMIN_API_URL, path, options);
}

/**
 * Gọi endpoint cần đăng nhập trên host admin-api — PHẢI chạy ở Client Component (browser
 * tự gửi cookie host-only `vv_admin_session`). `EnsureAdminOrigin` (backend) chỉ chấp nhận
 * `Origin`/`Referer` = `NEXT_PUBLIC_ADMIN_URL`.
 */
export function authFetch<T>(path: string, options?: AuthFetchOptions): Promise<T> {
  return authFetchBase<T>(env.NEXT_PUBLIC_ADMIN_API_URL, path, options);
}
