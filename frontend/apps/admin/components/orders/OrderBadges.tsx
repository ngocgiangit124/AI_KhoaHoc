import { Badge, IconAlertTriangle, IconClock, cx } from "@vitaminvui/ui/v2";
import { adminStatus, remainingText } from "@/lib/orders/format";
import type { OrderListItem } from "@/lib/orders/schemas";

type StatusInput = Pick<OrderListItem, "status" | "status_reason" | "payment_method" | "needs_review">;

/** Nhãn trạng thái phía quản trị. Chữ đủ nghĩa khi bỏ màu; "Cần xem lại" là badge riêng. */
export function AdminStatusBadge({ order, size = "sm" }: { order: StatusInput; size?: "sm" | "md" }) {
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

/** Thời gian còn lại tới hạn chờ. "Sắp hết hạn" theo `expiring_soon` của server (< 12 giờ, chỉ đơn manual). */
export function DeadlineCell({ expiresAt, expiringSoon, now, className }: { expiresAt: string; expiringSoon: boolean; now: number; className?: string }) {
  const text = remainingText(expiresAt, now);
  const urgent = expiringSoon && new Date(expiresAt).getTime() > now;
  return (
    <span className={cx("inline-flex flex-col items-start gap-1", className)} data-soon={expiringSoon ? "true" : undefined}>
      <span className={cx("num inline-flex items-center gap-1 whitespace-nowrap text-sm", urgent ? "font-semibold text-warning" : "text-ink")}>
        <IconClock size={14} />
        {text}
      </span>
      {urgent ? (
        <Badge tone="warning" size="sm">
          Sắp hết hạn
        </Badge>
      ) : null}
    </span>
  );
}
