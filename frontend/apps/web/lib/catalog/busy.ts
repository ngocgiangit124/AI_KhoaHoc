import { ApiError, NetworkError } from "@vitaminvui/api-client";

/**
 * API quá tải hoặc tạm lỗi (429 throttle, 5xx, mất kết nối): trang công khai hiện "Hệ thống đang bận" thay vì lỗi 500 trần
 * (ADR-004 §2.8). Lỗi khác (404 đã xử lý riêng, dữ liệu sai schema, bug) vẫn ném lên `error.tsx`.
 * Response 429/5xx KHÔNG vào Data Cache: Next chỉ lưu response status 200 (xem `busy.test.ts`).
 */
export function isUpstreamBusy(err: unknown): boolean {
  if (err instanceof NetworkError) return true;
  return err instanceof ApiError && (err.status === 429 || err.status >= 500);
}
