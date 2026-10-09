import type { BadgeTone } from "@vitaminvui/ui/v2";
import { formatDateTime } from "@vitaminvui/ui/v2";
import type { ManualContact } from "./schemas";

/** Nhãn trạng thái phía học sinh (bảng trạng thái của US-022). Chữ đủ nghĩa khi bỏ màu. Trạng thái lạ -> "Đã huỷ" an toàn. */
export function studentStatus(status: string, reason: string | null): { label: string; tone: BadgeTone } {
  if (status === "pending") return { label: "Chờ Quản trị viên duyệt", tone: "warning" };
  if (status === "paid") return { label: "Đã thanh toán", tone: "success" };
  if (status === "refunded") return { label: "Đã hoàn tiền", tone: "info" };
  if (status === "failed") return { label: "Thanh toán không thành công", tone: "danger" };
  switch (reason) {
    case "admin_cancelled":
      return { label: "Đã huỷ bởi Quản trị viên", tone: "danger" };
    case "expired":
      return { label: "Đã huỷ do quá hạn chờ", tone: "neutral" };
    case "superseded":
      return { label: "Đã thay bằng đơn mới", tone: "neutral" };
    case "user_cancelled":
      return { label: "Bạn đã huỷ đơn", tone: "neutral" };
    default:
      return { label: "Đã huỷ", tone: "neutral" };
  }
}

/** Số giờ (làm tròn lên) từ `now` tới `iso`; âm/0 = đã qua. `iso` hỏng -> 0. */
export function hoursUntil(iso: string, now: number = Date.now()): number {
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return 0;
  return Math.ceil((t - now) / 3_600_000);
}

/** "19:42, 10/10/2026 (còn 47 giờ)". Dưới 1 giờ: "còn dưới 1 giờ"; quá hạn: "đã quá hạn". */
export function deadlineText(expiresAt: string, now: number = Date.now()): string {
  const h = hoursUntil(expiresAt, now);
  const left = h <= 0 ? "đã quá hạn" : h <= 1 ? "còn dưới 1 giờ" : `còn ${h} giờ`;
  return `${formatDateTime(expiresAt)} (${left})`;
}

/** Tên phương thức hiển thị (US-022 Q16). Mã lạ -> nguyên mã. */
export function methodLabel(method: string): string {
  switch (method) {
    case "manual":
      return "Liên hệ Quản trị viên";
    case "momo":
      return "Ví MoMo";
    case "none":
      return "Miễn phí (mã giảm giá)";
    default:
      return method;
  }
}

/** `tel:` từ chuỗi hiển thị: giữ chữ số, giữ `+` đầu nếu có (api-contract §2.1). Không có chữ số -> null. */
export function telHref(phone: string): string | null {
  const trimmed = phone.trim();
  const digits = trimmed.replace(/\D/g, "");
  if (digits === "") return null;
  return `tel:${trimmed.startsWith("+") ? "+" : ""}${digits}`;
}

/** Zalo chỉ nhận `https://zalo.me/...` (api-contract §2.1); bất kỳ URL khác bị bỏ để không mở liên kết lạ. */
export function safeZaloUrl(url: string | null): string | null {
  if (!url) return null;
  return /^https:\/\/zalo\.me\/[\w./-]+$/.test(url) ? url : null;
}

/** Email chỉ dùng cho `mailto:` khi có dạng đơn giản (không khoảng trắng, `?`, `&`, `#`, ngoặc, dấu nháy). */
export function safeEmail(email: string | null): string | null {
  return email && /^[^\s@?&#<>"']+@[^\s@?&#<>"']+\.[^\s@?&#<>"']+$/.test(email) ? email : null;
}

/** Có ít nhất một kênh liên hệ để hiển thị? */
export function hasContactChannel(c: ManualContact): boolean {
  return Boolean((c.phone && telHref(c.phone)) || safeZaloUrl(c.zalo_url) || safeEmail(c.email));
}

/** Tóm tắt khóa trong dòng đơn: "Toán 9 và 2 khóa khác". */
export function itemsSummary(titles: string[], count: number): string {
  const first = titles[0] ?? "";
  const shown = titles.length;
  const rest = Math.max(0, count - 1);
  if (count <= 1 || shown === 0) return first;
  return `${first} và ${rest} khóa khác`;
}

/** Số khóa còn tính tiền trong giỏ/preview. */
export function payableCount(items: Array<{ unavailable: boolean }>): number {
  return items.filter((i) => !i.unavailable).length;
}

/** Lịch sử phía học sinh (không có ghi chú nội bộ). */
export function orderHistory(o: {
  status: string;
  status_reason: string | null;
  created_at: string;
  paid_at?: string | null;
  cancelled_at?: string | null;
  refunded_at?: string | null;
  payment_method: string;
}): Array<{ at: string; text: string }> {
  const rows: Array<{ at: string; text: string }> = [
    { at: o.created_at, text: o.payment_method === "manual" ? "Bạn đặt đơn, chọn “Liên hệ Quản trị viên”" : "Bạn đặt đơn" },
  ];
  if ((o.status === "paid" || o.status === "refunded") && o.paid_at) {
    rows.push({ at: o.paid_at, text: o.payment_method === "manual" ? "Quản trị viên xác nhận đã nhận tiền, khóa học được mở" : "Đơn được thanh toán, khóa học được mở" });
  }
  if (o.status === "refunded" && o.refunded_at) rows.push({ at: o.refunded_at, text: "Đơn được hoàn tiền, quyền học bị thu hồi" });
  if (o.status === "cancelled" && o.cancelled_at) {
    const text =
      { admin_cancelled: "Quản trị viên huỷ đơn", expired: "Đơn tự huỷ vì quá hạn chờ duyệt", superseded: "Đơn được thay bằng đơn mới", user_cancelled: "Bạn huỷ đơn" }[
        o.status_reason ?? ""
      ] ?? "Đơn bị huỷ";
    rows.push({ at: o.cancelled_at, text });
  }
  return rows;
}
