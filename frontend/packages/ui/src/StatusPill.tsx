import { Badge, type BadgeVariant } from "./Badge";

export interface StatusPillProps {
  status: string;
  /** Ghi đè nhãn hiển thị (mặc định tra theo `STATUS_LABELS`, fallback về chính `status`). */
  label?: string;
  className?: string;
}

/**
 * Nhãn trạng thái nghiệp vụ (đơn hàng, enrollment, mã giảm giá, tài khoản staff —
 * design-system.md §5.1). Danh sách khớp các enum trạng thái trong data-model.md; trạng
 * thái chưa có trong bảng vẫn hiển thị được (variant neutral) để không chặn UI khi backend
 * bổ sung enum mới.
 */
const STATUS_MAP: Record<string, { label: string; variant: BadgeVariant }> = {
  // Đơn hàng (orders.status)
  pending: { label: "Chờ thanh toán", variant: "warning" },
  paid: { label: "Đã thanh toán", variant: "success" },
  cancelled: { label: "Đã huỷ", variant: "neutral" },
  expired: { label: "Đã hết hạn", variant: "danger" },
  refunded: { label: "Đã hoàn tiền", variant: "info" },
  needs_review: { label: "Cần kiểm tra", variant: "warning" },
  // Đăng ký học miễn phí (enrollments.status)
  pending_approval: { label: "Chờ duyệt", variant: "warning" },
  approved: { label: "Đã duyệt", variant: "success" },
  rejected: { label: "Đã từ chối", variant: "danger" },
  active: { label: "Đang hoạt động", variant: "success" },
  revoked: { label: "Đã thu hồi", variant: "neutral" },
  // Mã giảm giá (coupons)
  inactive: { label: "Đã tắt", variant: "neutral" },
  // Tài khoản staff (users.status)
  locked: { label: "Đã khoá", variant: "danger" },
};

export function StatusPill({ status, label, className }: StatusPillProps) {
  const meta = STATUS_MAP[status];
  return (
    <Badge variant={meta?.variant ?? "neutral"} className={className}>
      {label ?? meta?.label ?? status}
    </Badge>
  );
}
