import {
  authFetch as authFetchBase,
  publicFetch as publicFetchBase,
  type AuthFetchOptions,
  type PublicFetchOptions,
} from "@vitaminvui/api-client";
import { env } from "@/env";

/**
 * Gọi endpoint công khai từ TRÌNH DUYỆT (`credentials: 'omit'`, được phép cache/ISR).
 * Dùng trong Client Component, hoặc Server Component không cần proxy qua backend nội bộ
 * (xem `lib/api.server.ts` cho SSR dùng `API_INTERNAL_URL`).
 */
export function publicFetch<T>(path: string, options?: PublicFetchOptions): Promise<T> {
  return publicFetchBase<T>(env.NEXT_PUBLIC_API_URL, path, options);
}

/**
 * Gọi endpoint cần đăng nhập — PHẢI chạy ở Client Component (browser tự gửi cookie
 * host-only `vv_session` qua `credentials: 'include'`). Không dùng trong Server Component
 * có `revalidate` (S16, ADR-004 §2.5).
 */
export function authFetch<T>(path: string, options?: AuthFetchOptions): Promise<T> {
  return authFetchBase<T>(env.NEXT_PUBLIC_API_URL, path, options);
}
