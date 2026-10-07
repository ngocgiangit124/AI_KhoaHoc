/**
 * Cổng bản xem trước design v2 (`/v2/...`, nhóm route `(v2-preview)`): ở production chỉ mở khi biến server `V2_PREVIEW=1`.
 * Đọc `process.env` lúc gọi (không chốt ở lúc build). Dùng trong `proxy.ts`.
 */
export function isPreviewPath(pathname: string): boolean {
  let p = pathname;
  try {
    p = decodeURIComponent(pathname); // `/%76%32` → `/v2`
  } catch {
    /* đường dẫn lỗi: giữ nguyên */
  }
  p = p.replace(/\/{2,}/g, "/").toLowerCase();
  return p === "/v2" || p.startsWith("/v2/");
}

export function isBlockedPreview(pathname: string): boolean {
  return isPreviewPath(pathname) && process.env.NODE_ENV === "production" && process.env.V2_PREVIEW !== "1";
}
