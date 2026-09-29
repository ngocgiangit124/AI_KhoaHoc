import { ApiError, NetworkError } from "./errors";
import type { ApiErrorBody } from "./types";

/** Bọc `fetch` để phân biệt lỗi mạng (NetworkError) với lỗi HTTP có body (ApiError). */
export async function doFetch(url: string, init: RequestInit): Promise<Response> {
  try {
    return await fetch(url, init);
  } catch (cause) {
    throw new NetworkError(cause);
  }
}

/**
 * `Retry-After` (giây) của response 429, khi có (api-contract §1.7, `cors.php` expose header
 * này cho request cross-origin — xem `ApiError.retryAfterSeconds`). Laravel luôn trả số nguyên
 * giây, không phải dạng HTTP-date.
 */
function readRetryAfterSeconds(res: Response): number | undefined {
  const raw = res.headers.get("Retry-After");
  if (!raw) return undefined;
  const seconds = Number(raw);
  return Number.isFinite(seconds) && seconds >= 0 ? seconds : undefined;
}

/** Đọc body JSON theo envelope api-contract, ném `ApiError` khi response không `ok`. */
export async function parseJsonResponse<T>(res: Response): Promise<T> {
  if (res.status === 204) {
    return undefined as T;
  }

  const text = await res.text();
  let body: unknown = undefined;
  if (text.length > 0) {
    try {
      body = JSON.parse(text);
    } catch {
      if (res.ok) {
        return undefined as T;
      }
      throw new ApiError(
        res.status,
        { message: "Đã có lỗi xảy ra, vui lòng thử lại sau." },
        readRetryAfterSeconds(res),
      );
    }
  }

  if (!res.ok) {
    throw new ApiError(res.status, (body ?? {}) as ApiErrorBody, readRetryAfterSeconds(res));
  }

  return body as T;
}
