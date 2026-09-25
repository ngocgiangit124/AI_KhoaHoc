import { doFetch, parseJsonResponse } from "./http";

interface CsrfTokenResponse {
  token: string;
}

/**
 * Cache CSRF token trong bộ nhớ, theo `baseUrl` (api-contract §1.2).
 *
 * QUAN TRỌNG: cache này CHỈ an toàn ở trình duyệt (mỗi tab có instance module riêng).
 * Nếu đoạn code này chạy trên server Next.js (Route Handler/Server Action dùng chung
 * cho nhiều người dùng), cache theo biến module sẽ bị RÒ RỈ token giữa các phiên khác
 * nhau — vì vậy `isServerRuntime()` tắt cache khi không có `window` để luôn lấy token mới.
 */
const tokenCache = new Map<string, string>();

function isServerRuntime(): boolean {
  return typeof window === "undefined";
}

export async function getCsrfToken(baseUrl: string): Promise<string> {
  if (!isServerRuntime()) {
    const cached = tokenCache.get(baseUrl);
    if (cached) return cached;
  }

  const res = await doFetch(`${baseUrl}/api/v1/csrf-token`, {
    method: "GET",
    credentials: "include",
    cache: "no-store",
    headers: { Accept: "application/json" },
  });
  const body = await parseJsonResponse<CsrfTokenResponse>(res);

  if (!isServerRuntime()) {
    tokenCache.set(baseUrl, body.token);
  }

  return body.token;
}

/** Xoá token đã cache (dùng khi 419 — token CSRF hết hạn). */
export function clearCsrfToken(baseUrl: string): void {
  tokenCache.delete(baseUrl);
}
