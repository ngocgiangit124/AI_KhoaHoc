/**
 * Định dạng hiển thị v2. Dùng Intl với locale vi-VN; không phụ thuộc múi giờ máy (tránh lệch hydration).
 */
const numberVi = new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 0 });

/** Tiền VNĐ (số nguyên, api-contract §1.4) → "399.000đ". Giá 0 → "Miễn phí". */
export function formatPrice(amount: number): string {
  return amount === 0 ? "Miễn phí" : `${numberVi.format(amount)}đ`;
}

/** 1240 → "1.240". */
export function formatCount(n: number): string {
  return numberVi.format(n);
}

/** Giây → "12:40" hoặc "1:02:05" (thời lượng bài, đồng hồ). */
export function formatClock(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(totalSeconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  const mm = h > 0 ? String(m).padStart(2, "0") : String(m);
  return h > 0 ? `${h}:${mm}:${String(sec).padStart(2, "0")}` : `${mm}:${String(sec).padStart(2, "0")}`;
}

/** Giây → "6 giờ 30 phút" / "45 phút" (tổng thời lượng khóa). */
export function formatDurationLong(totalSeconds: number): string {
  const minutes = Math.round(totalSeconds / 60);
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) return `${m} phút`;
  return m === 0 ? `${h} giờ` : `${h} giờ ${m} phút`;
}

/** Điểm thang 10 → "7,5" (dấu phẩy thập phân kiểu Việt Nam, tối đa 2 chữ số). */
export function formatScore(score: number): string {
  return new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 2 }).format(score);
}

const timeVn = new Intl.DateTimeFormat("vi-VN", { timeZone: "Asia/Ho_Chi_Minh", hour: "2-digit", minute: "2-digit", hour12: false });
const dateVn = new Intl.DateTimeFormat("vi-VN", { timeZone: "Asia/Ho_Chi_Minh", day: "2-digit", month: "2-digit", year: "numeric" });

/** ISO 8601 → "06/10/2026" (giờ Việt Nam, cố định múi giờ để server/client ra cùng kết quả). */
export function formatDate(iso: string): string {
  return dateVn.format(new Date(iso));
}

/** ISO 8601 → "19:44, 06/10/2026". */
export function formatDateTime(iso: string): string {
  const d = new Date(iso);
  return `${timeVn.format(d)}, ${dateVn.format(d)}`;
}
