"use client";

import { Button, ButtonLink, IconCart } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/routes";
import { useCardCart } from "./CardCartProvider";

/**
 * Nút phụ trên thẻ khóa CÓ PHÍ (slot `action` của `CourseCard`): khách -> "Mua khóa học" (đăng nhập rồi quay lại trang khóa),
 * chưa trong giỏ -> "Thêm vào giỏ", đã trong giỏ -> "Xem giỏ hàng". Đã sở hữu -> "Vào học", chờ duyệt -> nhãn "Chờ duyệt"; lỗi tải giữ chỗ trống 44px;
 * khóa miễn phí/thanh toán tạm đóng -> không hiện gì. Logic quyết định nằm ở `resolveCta` (dùng chung với trang chi tiết).
 */
export function CourseCardAction({ course }: { course: { id: number; slug: string; title: string; isFree: boolean } }) {
  const cart = useCardCart();
  if (!cart || !cart.paidCheckoutEnabled || course.isFree) return null;
  const model = cart.modelFor(course);
  switch (model.kind) {
    case "skeleton":
    case "retry":
      // Đang tải hoặc lỗi tải: giữ chỗ cao 44px để thẻ không co/giãn đột ngột.
      return <div aria-hidden="true" className="h-11" />;
    case "login":
      return (
        <ButtonLink href={`/dang-nhap?next=${encodeURIComponent(routes.course(course.slug))}`} variant="secondary" block aria-label={`${model.label}: ${course.title}`}>
          {model.label}
        </ButtonLink>
      );
    case "add_to_cart":
      return (
        <Button
          variant="secondary"
          block
          leadingIcon={<IconCart size={18} />}
          loading={model.busy}
          loadingText="Đang thêm…"
          aria-label={`${model.label}: ${course.title}`}
          onClick={() => cart.addToCart(course.id, course.title)}
        >
          {model.label}
        </Button>
      );
    case "in_cart":
      return (
        <ButtonLink href={model.href} variant="soft" block aria-label={`${model.label}: ${course.title}`}>
          {model.label}
        </ButtonLink>
      );
    case "owned":
      return (
        <ButtonLink href={model.href} variant="soft" block aria-label={`Vào học: ${course.title}`}>
          Vào học
        </ButtonLink>
      );
    case "pending":
      return (
        <p className="flex h-11 items-center justify-center rounded-control bg-sunken text-sm font-semibold text-ink-soft">Chờ duyệt</p>
      );
    default:
      return null;
  }
}
