const STORAGE_KEY = "vv_device_id";

/**
 * UUID v4 định danh thiết bị (ADR-003, header `X-Device-Id`), lưu `localStorage`.
 * Chỉ ổn định ở trình duyệt. Khi gọi ở server (SSR) — không có `localStorage` — trả về
 * một UUID tạm cho từng lần gọi; các route GET không bắt buộc device ổn định, còn luồng
 * thật sự cần (đăng nhập, bind phiên) phải chạy ở Client Component.
 */
export function getDeviceId(): string {
  if (typeof window === "undefined" || typeof window.localStorage === "undefined") {
    return crypto.randomUUID();
  }

  const existing = window.localStorage.getItem(STORAGE_KEY);
  if (existing) {
    return existing;
  }

  const id = crypto.randomUUID();
  window.localStorage.setItem(STORAGE_KEY, id);
  return id;
}
