import { Badge, IconAlertTriangle, IconClock, cx, type BadgeTone } from "@vitaminvui/ui/v2";
import { hoursLeft, type AdminOrderListItem, type AdminOrderReason, type AdminOrderStatus } from "@/lib/mock/v2/orders";

/** Nhãn trạng thái phía quản trị (bảng trạng thái US-022). Chữ đủ nghĩa khi bỏ màu. */
export function adminStatus(status: AdminOrderStatus, reason: AdminOrderReason, method: AdminOrderListItem["payment_method"] = "manual"): { label: string; tone: BadgeTone } {
  if (status === "pending") return { label: "Chờ duyệt", tone: "warning" };
  if (status === "paid") return { label: method === "manual" ? "Đã duyệt" : method === "none" ? "Miễn phí (0đ)" : "Đã thanh toán", tone: "success" };
  if (status === "refunded") return { label: "Đã hoàn tiền", tone: "info" };
  if (status === "failed") return { label: "Thất bại", tone: "danger" };
  const map: Record<string, string> = {
    user_cancelled: "HS tự huỷ",
    admin_cancelled: "Huỷ bởi QTV",
    expired: "Tự huỷ (hết hạn)",
    superseded: "Thay bằng đơn mới",
    account_deleted: "Tài khoản đã xoá",
  };
  return { label: map[reason ?? ""] ?? "Đã huỷ", tone: "neutral" };
}

export function AdminStatusBadge({ order, size = "sm" }: { order: Pick<AdminOrderListItem, "status" | "status_reason" | "payment_method" | "needs_review">; size?: "sm" | "md" }) {
  const s = adminStatus(order.status, order.status_reason, order.payment_method);
  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      <Badge tone={s.tone} dot size={size}>
        {s.label}
      </Badge>
      {order.needs_review ? (
        <Badge tone="danger" size={size} icon={<IconAlertTriangle size={14} />}>
          Cần xem lại
        </Badge>
      ) : null}
    </span>
  );
}

/** Thời gian còn lại tới hạn chờ. Dưới 12 giờ: nhãn "Sắp hết hạn" (AC15). */
export function DeadlineCell({ expiresAt, className }: { expiresAt: string; className?: string }) {
  const h = hoursLeft(expiresAt);
  const urgent = h < 12;
  return (
    <span className={cx("inline-flex flex-col items-start gap-1", className)}>
      <span className={cx("num inline-flex items-center gap-1 whitespace-nowrap text-sm", urgent ? "font-semibold text-warning" : "text-ink")}>
        <IconClock size={14} />
        {h <= 0 ? "Đã quá hạn" : h < 1 ? "Còn dưới 1 giờ" : `Còn ${h} giờ`}
      </span>
      {urgent && h > 0 ? (
        <Badge tone="warning" size="sm">
          Sắp hết hạn
        </Badge>
      ) : null}
    </span>
  );
}

export function methodLabel(m: AdminOrderListItem["payment_method"]): string {
  return m === "manual" ? "Liên hệ QTV" : m === "momo" ? "MoMo" : "Miễn phí";
}
