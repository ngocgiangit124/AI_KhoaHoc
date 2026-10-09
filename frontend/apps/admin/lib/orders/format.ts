import type { OrderDetail, OrderListItem, OrderStatus, StatusLog } from "./schemas";

const timeVn = new Intl.DateTimeFormat("vi-VN", { timeZone: "Asia/Ho_Chi_Minh", hour: "2-digit", minute: "2-digit", hour12: false });
const dateVn = new Intl.DateTimeFormat("vi-VN", { timeZone: "Asia/Ho_Chi_Minh", day: "2-digit", month: "2-digit", year: "numeric" });

/** ISO → "19:59 08/10/2026" (giờ Việt Nam). */
export function formatWhen(iso: string): string {
  const d = new Date(iso);
  return `${timeVn.format(d)} ${dateVn.format(d)}`;
}

export const formatDay = (iso: string) => dateVn.format(new Date(iso));

/** Số giờ nguyên còn lại tới `iso` (làm tròn xuống; âm = đã quá hạn). */
export function hoursLeft(iso: string, now: number = Date.now()): number {
  return Math.floor((new Date(iso).getTime() - now) / 3_600_000);
}

export const SOON_HOURS = 12;

/** "Còn 5 giờ" / "Còn dưới 1 giờ" / "Đã quá hạn". */
export function remainingText(iso: string, now: number = Date.now()): string {
  const h = hoursLeft(iso, now);
  if (new Date(iso).getTime() <= now) return "Đã quá hạn";
  return h < 1 ? "Còn dưới 1 giờ" : `Còn ${h} giờ`;
}

export type Tone = "neutral" | "warning" | "success" | "info" | "danger";

const REASON_LABELS: Record<string, string> = {
  user_cancelled: "HS tự huỷ",
  admin_cancelled: "Huỷ bởi QTV",
  expired: "Tự huỷ (hết hạn)",
  superseded: "Thay bằng đơn mới",
  account_deleted: "Tài khoản đã xoá",
};

/** Nhãn trạng thái phía quản trị (bảng trạng thái US-022). Chữ đủ nghĩa khi bỏ màu. */
export function adminStatus(status: OrderStatus, reason: string | null, method: string = "manual"): { label: string; tone: Tone } {
  if (status === "pending") return { label: "Chờ duyệt", tone: "warning" };
  if (status === "paid") return { label: method === "manual" ? "Đã duyệt" : method === "none" ? "Miễn phí (0đ)" : "Đã thanh toán", tone: "success" };
  if (status === "refunded") return { label: "Đã hoàn tiền", tone: "info" };
  if (status === "failed") return { label: "Thất bại", tone: "danger" };
  return { label: REASON_LABELS[reason ?? ""] ?? "Đã huỷ", tone: "neutral" };
}

export function methodLabel(m: string): string {
  return m === "manual" ? "Liên hệ Quản trị viên" : m === "momo" ? "MoMo" : m === "none" ? "Miễn phí (0đ)" : m;
}

export const REVIEW_REASON_LABEL: Record<string, string> = {
  late_payment: "Duyệt muộn (đơn đã huỷ trước đó).",
  already_owned: "Học sinh đã sở hữu khóa trong đơn từ nguồn khác: giữ quyền cũ, hoàn phần trùng ngoài hệ thống.",
  coupon_over_limit: "Mã giảm giá đã vượt số lượt cho phép.",
  coupon_already_used: "Học sinh đã dùng mã giảm giá này ở đơn khác.",
  course_unavailable: "Có khóa không còn khả dụng.",
};

const WARNING_LABEL: Record<string, (t: string) => string> = {
  COURSE_UNPUBLISHED: (t) => `Khóa “${t}” đã ngừng bán sau khi đặt. Duyệt vẫn mở khóa theo giá đã chốt.`,
  COURSE_DELETED: (t) => `Khóa “${t}” đã bị xoá nên duyệt sẽ bị từ chối. Nếu học sinh đã chuyển tiền, hoàn tiền ngoài hệ thống.`,
  ALREADY_OWNED: (t) => `Học sinh đã sở hữu khóa “${t}” từ nguồn khác: giữ quyền cũ, hoàn phần trùng ngoài hệ thống.`,
  ACCOUNT_LOCKED: () => "Tài khoản học sinh đang bị khoá: vẫn duyệt được nhưng học sinh chưa vào học được.",
  ACCOUNT_DELETED: () => "Tài khoản học sinh đã bị xoá.",
};

const LATE_WARNING_LABEL: Record<string, (t: string, c: string) => string> = {
  ALREADY_OWNED: (t) => `Học sinh đã sở hữu khóa “${t}” từ đơn/nguồn khác: giữ quyền cũ, hoàn phần trùng ngoài hệ thống.`,
  COUPON_OVER_LIMIT: (_t, c) => `Mã giảm giá ${c} đã hết lượt: đơn sẽ gắn cờ “Cần xem lại”.`,
  COUPON_ALREADY_USED: (_t, c) => `Học sinh đã dùng mã giảm giá ${c} ở đơn khác: đơn sẽ gắn cờ “Cần xem lại”.`,
};

export function warningText(w: { code: string; title: string | null; coupon_code: string | null }, late = false): string {
  const t = w.title ?? "";
  const c = w.coupon_code ?? "";
  const f = late ? LATE_WARNING_LABEL[w.code] : undefined;
  if (f) return f(t, c);
  return WARNING_LABEL[w.code]?.(t) ?? `Cảnh báo: ${w.code}`;
}

/** Người thực hiện một dòng lịch sử. */
export function actorText(l: Pick<StatusLog, "actor_type" | "actor">): string {
  if (l.actor_type === "system" || l.actor_type === "gateway") return l.actor_type === "gateway" ? "Cổng thanh toán" : "Hệ thống";
  if (l.actor_type === "staff") return l.actor?.name ?? "Quản trị viên";
  return l.actor?.name ? `Học sinh ${l.actor.name}` : "Học sinh";
}

/** Câu một dòng lịch sử, ví dụ "Đã duyệt bởi Đỗ Thị Mai lúc 19:59 08/10/2026". */
export function logSentence(l: StatusLog): string {
  const by = actorText(l);
  const when = formatWhen(l.created_at);
  const late = l.meta["late"] === true;
  if (l.from === null) return `${by} đặt đơn lúc ${when}`;
  if (l.to === "paid") {
    return l.actor_type === "staff" ? `${late ? "Đã duyệt muộn" : "Đã duyệt"} bởi ${by} lúc ${when}` : `Đã thanh toán (${by}) lúc ${when}`;
  }
  if (l.to === "refunded") return `Đã đánh dấu hoàn tiền bởi ${by} lúc ${when}`;
  if (l.to === "cancelled") {
    if (l.reason === "expired") return `Tự huỷ do hết hạn chờ lúc ${when}`;
    if (l.reason === "user_cancelled") return `${by} tự huỷ đơn lúc ${when}`;
    if (l.reason === "superseded") return `Thay bằng đơn mới lúc ${when}`;
    if (l.reason === "account_deleted") return `Huỷ do tài khoản bị xoá lúc ${when}`;
    return `Đã huỷ bởi ${by} lúc ${when}`;
  }
  if (l.to === "failed") return `Thanh toán thất bại lúc ${when}`;
  return `${adminStatus(l.to as OrderStatus, l.reason).label} bởi ${by} lúc ${when}`;
}

/** Dòng log gần nhất chuyển sang `to`. */
export function lastLogTo(order: Pick<OrderDetail, "status_logs">, to: string): StatusLog | null {
  for (let i = order.status_logs.length - 1; i >= 0; i--) {
    const l = order.status_logs[i];
    if (l && l.to === to) return l;
  }
  return null;
}

export const isExpiringSoon = (o: Pick<OrderListItem, "expiring_soon" | "status">) => o.status === "pending" && o.expiring_soon;

/** `tel:` / `mailto:` — luôn qua encodeURIComponent, chỉ giữ ký tự số/+ cho SĐT (S5). */
export function telHref(phone: string): string {
  return `tel:${encodeURIComponent(phone.replace(/[^\d+]/g, ""))}`;
}
export function mailHref(email: string, code: string): string {
  return `mailto:${encodeURIComponent(email)}?subject=${encodeURIComponent(`VitaminVui — đơn ${code}`)}`;
}
