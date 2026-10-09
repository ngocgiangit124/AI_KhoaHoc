import { ButtonLink, EmptyState, IconReceipt } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

/** Đơn không tồn tại hoặc không phải của mình (404, AC11 — không nói rõ là đơn của người khác). */
export default function OrderNotFound() {
  return (
    <main id="noi-dung" className="mx-auto w-full max-w-xl flex-1 px-4 py-8">
      <EmptyState
        headingLevel="h1"
        icon={<IconReceipt size={32} />}
        title="Không tìm thấy đơn hàng"
        description="Mã đơn không đúng hoặc đơn không thuộc tài khoản của bạn."
        action={<ButtonLink href={routes.myOrders}>Xem đơn hàng của tôi</ButtonLink>}
      />
    </main>
  );
}
