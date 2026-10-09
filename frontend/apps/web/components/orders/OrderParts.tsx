import {
  Badge,
  CourseCover,
  IconExternalLink,
  IconMail,
  IconMessageCircle,
  IconPhone,
  buttonClasses,
  cx,
  formatPrice,
} from "@vitaminvui/ui/v2";
import { CourseImage } from "@/components/catalog/CourseImage";
import { safeEmail, safeZaloUrl, studentStatus, telHref } from "@/lib/orders/format";
import type { CartItem, ManualContact, OrderItem } from "@/lib/orders/schemas";

/* Khối hiển thị dùng chung cho giỏ, thanh toán, "Đơn đã gửi", đơn của tôi. Dựng từ bản xem trước `components/v2/orders` của designer. */

/** Một dòng khóa (giỏ/preview/đơn) đã chuẩn hoá để dùng chung. */
export interface LineItem {
  course_id: number;
  title: string;
  slug: string | null;
  grade_level: number | null;
  thumbnail_url: string | null;
  price: number;
  discount: number;
  final: number;
}

export function fromCartItem(i: CartItem): LineItem {
  return {
    course_id: i.course_id,
    title: i.title,
    slug: i.slug,
    grade_level: i.grade_level,
    thumbnail_url: i.thumbnail_url ?? null,
    price: i.price,
    discount: i.discount_amount ?? 0,
    final: i.final_amount ?? i.price,
  };
}

export function fromOrderItem(i: OrderItem): LineItem {
  return {
    course_id: i.course_id,
    title: i.title,
    slug: i.slug,
    grade_level: i.grade_level,
    thumbnail_url: null,
    price: i.unit_price,
    discount: i.discount_amount,
    final: i.final_amount,
  };
}

export function OrderStatusBadge({ status, reason, size = "md" }: { status: string; reason: string | null; size?: "sm" | "md" }) {
  const s = studentStatus(status, reason);
  return (
    <Badge tone={s.tone} dot size={size}>
      {s.label}
    </Badge>
  );
}

export function ItemCover({ item, className }: { item: LineItem; className?: string }) {
  return (
    <div className={className}>
      <CourseCover
        title={item.title}
        gradeLevel={item.grade_level ?? 0}
        subjectSlug={item.slug ?? undefined}
        size="thumb"
        image={item.thumbnail_url ? <CourseImage url={item.thumbnail_url} sizes="112px" /> : undefined}
      />
    </div>
  );
}

/** Danh sách khóa trong đơn: bìa nhỏ, tên, lớp, giá (gạch giá gốc khi có giảm). Văn bản thuần (không HTML). */
export function OrderItemRows({ items, compact = false }: { items: LineItem[]; compact?: boolean }) {
  return (
    <ul className="divide-y divide-line">
      {items.map((it) => (
        <li key={it.course_id} className={cx("flex items-start gap-3", compact ? "py-2.5" : "py-3")}>
          {compact ? null : <ItemCover item={it} className="w-20 shrink-0 sm:w-24" />}
          <div className="flex min-w-0 flex-1 flex-col gap-1 sm:flex-row sm:items-start sm:gap-3">
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
              <p className="break-words text-base font-semibold leading-snug text-ink">{it.title}</p>
              {it.grade_level ? <p className="text-sm text-ink-soft">Lớp {it.grade_level}</p> : null}
            </div>
            <PriceCell price={it.price} discount={it.discount} final={it.final} />
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

/** Bảng tiền: Tạm tính / Giảm giá (mã) / Tổng cộng. */
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
 * SĐT `tel:`, Zalo mở tab mới (`noopener noreferrer`), email `mailto:` kèm mã đơn ở tiêu đề.
 * Không bao giờ hiện số tài khoản/QR (story Q2).
 */
export function ContactChannels({ contact, orderCode, className }: { contact: ManualContact; orderCode?: string; className?: string }) {
  const tel = contact.phone ? telHref(contact.phone) : null;
  const zalo = safeZaloUrl(contact.zalo_url);
  const email = safeEmail(contact.email);
  const subject = orderCode ? `?subject=${encodeURIComponent(`Đơn ${orderCode}`)}` : "";
  const btn = cx(buttonClasses({ variant: "secondary", size: "lg", block: true }), "justify-start gap-3 px-4");
  const count = [tel, zalo, email].filter(Boolean).length;
  if (count === 0 && !contact.hours) return null;
  return (
    <div className={cx("flex flex-col gap-3", className)}>
      {count > 0 ? (
        <ul className="grid gap-2 sm:grid-cols-2">
          {contact.phone && tel ? (
            <li>
              <a href={tel} className={btn}>
                <IconPhone className="text-primary" />
                <span className="flex min-w-0 flex-col items-start leading-tight">
                  <span className="text-sm font-medium text-ink-soft">Gọi điện</span>
                  <span className="num truncate">{contact.phone}</span>
                </span>
              </a>
            </li>
          ) : null}
          {zalo ? (
            <li>
              <a href={zalo} target="_blank" rel="noopener noreferrer" className={btn}>
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
          {email ? (
            <li className={tel && zalo ? "sm:col-span-2" : undefined}>
              <a href={`mailto:${email}${subject}`} className={btn}>
                <IconMail className="text-primary" />
                <span className="flex min-w-0 flex-col items-start leading-tight">
                  <span className="text-sm font-medium text-ink-soft">Gửi email</span>
                  <span className="truncate">{email}</span>
                </span>
              </a>
            </li>
          ) : null}
        </ul>
      ) : null}
      {contact.hours ? (
        <p className="text-sm text-ink-soft">
          <span className="font-semibold text-ink">Giờ hỗ trợ:</span> {contact.hours}
        </p>
      ) : null}
    </div>
  );
}
