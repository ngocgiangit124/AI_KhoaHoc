import { doFetch, parseJsonResponse } from "./http";

export interface PublicFetchOptions extends Omit<RequestInit, "cache" | "credentials"> {
  /** Giây; `false` = không cache (`cache: 'no-store'`). Bỏ trống = mặc định của Next.js. */
  revalidate?: number | false;
  /** Tag cho `revalidateTag`/`updateTag` (Next.js Data Cache). */
  tags?: string[];
}

/**
 * Gọi endpoint công khai — KHÔNG BAO GIỜ gửi cookie (`credentials: 'omit'`), được phép
 * cache/ISR (ADR-004 §2.5, S16). Dùng cho danh mục, chi tiết khóa học, `/config/public`...
 *
 * Cấm dùng cho endpoint cần đăng nhập — dùng `authFetch`.
 */
export async function publicFetch<T>(
  baseUrl: string,
  path: string,
  options: PublicFetchOptions = {},
): Promise<T> {
  const { revalidate, tags, headers, ...rest } = options;

  // Next.js không cho truyền cùng lúc `cache` và `next.revalidate`. `revalidate: false`
  // dùng `cache: 'no-store'`; số giây hoặc `tags` dùng `next: {...}` (ISR/Data Cache).
  const cacheInit: Partial<RequestInit> =
    revalidate === false
      ? { cache: "no-store" }
      : revalidate !== undefined || tags !== undefined
        ? {
            // `next` chỉ được Next.js hiểu (fetch đã được vá); môi trường khác (Vitest) bỏ qua.
            ...({
              next: {
                ...(revalidate !== undefined ? { revalidate } : {}),
                ...(tags ? { tags } : {}),
              },
            } as Record<string, unknown>),
          }
        : {};

  const res = await doFetch(`${baseUrl}${path}`, {
    ...rest,
    ...cacheInit,
    credentials: "omit",
    headers: { Accept: "application/json", ...headers },
  });

  return parseJsonResponse<T>(res);
}
