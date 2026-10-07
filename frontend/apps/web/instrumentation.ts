/**
 * Khởi động server: nạp sẵn module lọc HTML (isomorphic-dompurify kéo theo jsdom, nạp lần
 * đầu mất hàng giây) để người xem đầu tiên của trang chi tiết khóa học không gánh độ trễ đó
 * (ngưỡng TTFB cache lạnh 1,2 s — ADR-004 §2.7).
 */
export async function register() {
  if (process.env.NEXT_RUNTIME === "nodejs") {
    const { sanitizeCourseDescription } = await import("@/lib/catalog/sanitize");
    sanitizeCourseDescription("<p>warmup</p>");
  }
}
