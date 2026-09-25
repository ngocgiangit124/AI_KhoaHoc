/**
 * Định dạng dùng chung 2 app (tránh lỗi hydration do server/client ra kết quả khác nhau —
 * luôn truyền `timeZone: 'Asia/Ho_Chi_Minh'` tường minh, không phụ thuộc múi giờ máy chủ).
 */

const currencyFormatter = new Intl.NumberFormat("vi-VN", {
  style: "currency",
  currency: "VND",
  maximumFractionDigits: 0,
});

/** Tiền là số nguyên VNĐ (api-contract §1.4). */
export function formatCurrencyVnd(amount: number): string {
  return currencyFormatter.format(amount);
}

// Dùng field cụ thể (không `dateStyle`/`timeStyle`) để chủ động thứ tự "ngày, giờ" —
// thứ tự mặc định của ICU cho vi-VN với dateStyle/timeStyle không khớp quy ước hiển thị
// thường dùng ở app (và khác nhau giữa các bản ICU/Node).
const dateFormatter = new Intl.DateTimeFormat("vi-VN", {
  timeZone: "Asia/Ho_Chi_Minh",
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
});

const timeFormatter = new Intl.DateTimeFormat("vi-VN", {
  timeZone: "Asia/Ho_Chi_Minh",
  hour: "2-digit",
  minute: "2-digit",
  hour12: false,
});

/** Ngày giờ ISO 8601 (api-contract §1.4) -> "dd/MM/yyyy, HH:mm" theo giờ VN. */
export function formatDateTimeVn(iso: string | Date): string {
  const date = new Date(iso);
  return `${dateFormatter.format(date)}, ${timeFormatter.format(date)}`;
}

/** Chỉ ngày, không giờ -> "dd/MM/yyyy". */
export function formatDateVn(iso: string | Date): string {
  return dateFormatter.format(new Date(iso));
}
