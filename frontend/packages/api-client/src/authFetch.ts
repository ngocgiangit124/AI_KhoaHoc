import { clearCsrfToken, getCsrfToken } from "./csrfToken";
import { getDeviceId } from "./deviceId";
import { dispatchAuthEventIfNeeded, ApiError } from "./errors";
import { doFetch, parseJsonResponse } from "./http";

const WRITE_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);

export type AuthFetchOptions = Omit<RequestInit, "cache" | "credentials"> & {
  /**
   * Khi `true`: KHÔNG phát sự kiện `forced-logout`/`login-required` dù lỗi khớp mã mất
   * phiên (api-contract §1.7) — `ApiError` vẫn được ném bình thường, chỉ tắt hiệu ứng phụ
   * toàn cục. Dùng khi "dò" trạng thái đăng nhập ở nơi KHÔNG bắt buộc đăng nhập (ví dụ
   * header công khai gọi `GET /auth/me` để biết có đang đăng nhập hay không) — ở đó, 401
   * `UNAUTHENTICATED` là bình thường (khách chưa đăng nhập), không phải "mất phiên", nên
   * không được kích hoạt `ForcedLogoutOverlay`/chuyển hướng `/dang-nhap`.
   */
  suppressAuthEvents?: boolean;
};

/**
 * Gọi endpoint cần đăng nhập: `credentials: 'include'`, `cache: 'no-store'` luôn luôn
 * (ADR-004 §2.5, S16 — không bao giờ cache dữ liệu theo người dùng), tự gắn
 * `X-Device-Id`, tự gắn `X-CSRF-TOKEN` cho method thay đổi dữ liệu, tự thử lại 1 lần khi
 * gặp 419 (CSRF hết hạn — api-contract §1.2). Khi lỗi có `code` khớp mã mất phiên, phát
 * sự kiện `forced-logout`/`login-required` để `ForcedLogoutOverlay` xử lý (trừ khi gọi với
 * `suppressAuthEvents: true`).
 *
 * Trang cần đăng nhập ở Server Component phải đặt `export const dynamic = 'force-dynamic'`
 * và gọi qua wrapper `server-only` của app (forward cookie thủ công) — `authFetch` của
 * package này không tự đọc cookie server, chỉ set `credentials: 'include'` cho trình duyệt.
 */
export async function authFetch<T>(
  baseUrl: string,
  path: string,
  options: AuthFetchOptions = {},
  _internal: { retriedAfter419?: boolean } = {},
): Promise<T> {
  const { suppressAuthEvents, ...fetchOptions } = options;
  const method = (fetchOptions.method ?? "GET").toUpperCase();
  const needsCsrf = WRITE_METHODS.has(method);

  const headers = new Headers(fetchOptions.headers);
  headers.set("Accept", "application/json");
  if (!headers.has("X-Device-Id")) {
    headers.set("X-Device-Id", getDeviceId());
  }
  if (needsCsrf && !headers.has("X-CSRF-TOKEN")) {
    headers.set("X-CSRF-TOKEN", await getCsrfToken(baseUrl));
  }

  const res = await doFetch(`${baseUrl}${path}`, {
    ...fetchOptions,
    headers,
    credentials: "include",
    cache: "no-store",
  });

  if (res.status === 419 && needsCsrf && !_internal.retriedAfter419) {
    clearCsrfToken(baseUrl);
    return authFetch<T>(baseUrl, path, options, { retriedAfter419: true });
  }

  try {
    return await parseJsonResponse<T>(res);
  } catch (err) {
    if (err instanceof ApiError && !suppressAuthEvents) {
      dispatchAuthEventIfNeeded(err.code);
    }
    throw err;
  }
}
