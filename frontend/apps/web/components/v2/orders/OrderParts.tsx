import {
  Badge,
  CourseCover,
  IconExternalLink,
  IconMail,
  IconMessageCircle,
  IconPhone,
  buttonClasses,
  cx,
  formatDateTime,
  formatPrice,
  type BadgeTone,
} from "@vitaminvui/ui/v2";
import type { ManualPaymentConfig, OrderItem, OrderStatus, OrderStatusReason, StudentOrder } from "@/lib/mock/v2/orders";
import { hoursUntil } from "@/lib/mock/v2/orders";

/* Các khối hiển thị dùng chung cho giỏ, thanh toán, "Đơn đã gửi", đơn của tôi (Server Component, không hook). */

/** Nhãn trạng thái phía học sinh (bảng trạng thái của US-022). Chữ đủ nghĩa khi bỏ màu. */
export function studentStatus(status: OrderStatus, reason: OrderStatusReason): { label: string; tone: BadgeTone } {
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

export function OrderStatusBadge({ order, size = "md" }: { order: Pick<StudentOrder, "status" | "status_reason">; size?: "sm" | "md" }) {
  const s = studentStatus(order.status, order.status_reason);
  return (
    <Badge tone={s.tone} dot size={size}>
      {s.label}
    </Badge>
  );
}

/** "19:42, 10/10/2026 (còn 47 giờ)". Dưới 1 giờ: "còn dưới 1 giờ". */
export function deadlineText(expiresAt: string): string {
  const h = hoursUntil(expiresAt);
  const left = h <= 0 ? "đã quá hạn" : h <= 1 ? "còn dưới 1 giờ" : `còn ${h} giờ`;
  return `${formatDateTime(expiresAt)} (${left})`;
}

/** Danh sách khóa trong giỏ/đơn: bìa nhỏ, tên, lớp, giá (gạch giá gốc khi có giảm). */
export function OrderItemRows({ items, compact = false }: { items: OrderItem[]; compact?: boolean }) {
  return (
    <ul className="divide-y divide-line">
      {items.map((it) => (
        <li key={it.course_id} className={cx("flex items-start gap-3", compact ? "py-2.5" : "py-3")}>
          {compact ? null : (
            <div className="w-20 shrink-0 sm:w-24">
              <CourseCover title={it.title} gradeLevel={it.grade_level} subjectSlug={it.slug} size="thumb" />
            </div>
          )}
          <div className="flex min-w-0 flex-1 flex-col gap-1 sm:flex-row sm:items-start sm:gap-3">
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
              <p className={cx("font-semibold text-ink", compact ? "text-base" : "text-base leading-snug")}>{it.title}</p>
              <p className="text-sm text-ink-soft">Lớp {it.grade_level}</p>
            </div>
            <PriceCell price={it.price} discount={it.discount_amount} final={it.final_amount} />
          </div>
        </li>
      ))}
    </ul>
  );
}

export function PriceCell({ price, discount, final }: { price: number; discount: number; final: number }) {
  return (
    <div className="num flex shrink-0 flex-row items-baseline gap-2 sm:flex-col sm:items-end sm:gap-0 sm:text-right">
      <span className="text-base font-semibold text-ink">{formatPrice(final)}</span>
      {discount > 0 ? (
        <span className="text-sm text-ink-soft">
          <span className="sr-only">Giá gốc </span>
          <s>{formatPrice(price)}</s>
        </span>
      ) : null}
    </div>
  );
}

/** Bảng tiền: Tạm tính / Giảm giá (mã) / Tổng cộng. `totalLabel` đổi được ("Cần thanh toán"). */
export function PricingSummary({
  pricing,
  couponCode,
  totalLabel = "Tổng cộng",
  className,
}: {
  pricing: { subtotal: number; discount: number; total: number };
  couponCode?: string | null;
  totalLabel?: string;
  className?: string;
}) {
  return (
    <dl className={cx("num flex flex-col gap-2 text-base", className)}>
      <div className="flex justify-between gap-4">
        <dt className="text-ink-soft">Tạm tính</dt>
        <dd className="text-ink">{formatPrice(pricing.subtotal)}</dd>
      </div>
      {pricing.discount > 0 ? (
        <div className="flex justify-between gap-4">
          <dt className="text-ink-soft">Giảm giá{couponCode ? ` (${couponCode})` : ""}</dt>
          <dd className="text-success">−{formatPrice(pricing.discount)}</dd>
        </div>
      ) : null}
      <div className="mt-1 flex items-baseline justify-between gap-4 border-t border-line pt-3">
        <dt className="font-semibold text-ink">{totalLabel}</dt>
        <dd className="text-heading font-extrabold tracking-heading text-ink">{pricing.total === 0 ? "0đ" : formatPrice(pricing.total)}</dd>
      </div>
    </dl>
  );
}

/**
 * Kênh liên hệ Quản trị viên (story Q1): mỗi kênh là nút lớn (52px), kênh trống (null) bị ẩn.
 * SĐT `tel:` gọi được trên điện thoại; Zalo mở tab mới; email `mailto:` kèm sẵn mã đơn ở tiêu đề.
 * Không bao giờ hiện số tài khoản/QR ở đây (story Q2).
 */
export function ContactChannels({ contact, orderCode, className }: { contact: ManualPaymentConfig["contact"]; orderCode?: string; className?: string }) {
  const tel = contact.phone ? contact.phone.replace(/[^\d+]/g, "") : null;
  const subject = orderCode ? `?subject=${encodeURIComponent(`Đơn ${orderCode}`)}` : "";
  const btn = cx(buttonClasses({ variant: "secondary", size: "lg", block: true }), "justify-start gap-3 px-4");
  return (
    <div className={cx("flex flex-col gap-3", className)}>
      <ul className="grid gap-2 sm:grid-cols-2">
        {contact.phone && tel ? (
          <li>
            <a href={`tel:${tel}`} className={btn}>
              <IconPhone className="text-primary" />
              <span className="flex min-w-0 flex-col items-start leading-tight">
                <span className="text-sm font-medium text-ink-soft">Gọi điện</span>
                <span className="num truncate">{contact.phone}</span>
              </span>
            </a>
          </li>
        ) : null}
        {contact.zalo_url ? (
          <li>
            <a href={contact.zalo_url} target="_blank" rel="noopener noreferrer" className={btn}>
              <IconMessageCircle className="text-primary" />
              <span className="flex min-w-0 flex-1 flex-col items-start leading-tight">
                <span className="text-sm font-medium text-ink-soft">Nhắn Zalo</span>
                <span className="truncate">Mở Zalo</span>
              </span>
              <IconExternalLink size={16} className="text-ink-soft" />
              <span className="sr-only">(mở trong tab mới)</span>
            </a>
          </li>
        ) : null}
        {contact.email ? (
          <li className={contact.phone && contact.zalo_url ? "sm:col-span-2" : undefined}>
            <a href={`mailto:${contact.email}${subject}`} className={btn}>
              <IconMail className="text-primary" />
              <span className="flex min-w-0 flex-col items-start leading-tight">
                <span className="text-sm font-medium text-ink-soft">Gửi email</span>
                <span className="truncate">{contact.email}</span>
              </span>
            </a>
          </li>
        ) : null}
      </ul>
      {contact.hours ? (
        <p className="text-sm text-ink-soft">
          <span className="font-semibold text-ink">Giờ hỗ trợ:</span> {contact.hours}
        </p>
      ) : null}
    </div>
  );
}

/** Tên phương thức hiển thị (story Q16). */
export function methodLabel(m: StudentOrder["payment_method"]): string {
  return m === "manual" ? "Liên hệ Quản trị viên" : m === "momo" ? "Ví MoMo" : "Miễn phí (mã giảm giá)";
}
