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

/** Đọc body JSON theo envelope api-contract, ném `ApiError` khi response không `ok`. */
function readRetryAfter(res: Response): number | undefined {
  const raw = res.headers.get("Retry-After");
  if (!raw || !/^\d+$/.test(raw.trim())) return undefined;
  return Number(raw.trim());
}

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
      throw new ApiError(res.status, { message: "Đã có lỗi xảy ra, vui lòng thử lại sau." }, readRetryAfter(res));
    }
  }

  if (!res.ok) {
    throw new ApiError(res.status, (body ?? {}) as ApiErrorBody, readRetryAfter(res));
  }

  return body as T;
}
