import Link from "next/link";
import { IconChevronLeft } from "@vitaminvui/ui/v2";
import { CheckoutForm, type CheckoutDemo } from "@/components/v2/orders/CheckoutForm";
import { OrderItemRows } from "@/components/v2/orders/OrderParts";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { cart, cartZeroTotal, checkoutConfig, pendingOrder, replacementOrder, type OrderItem, type PaymentMethod } from "@/lib/mock/v2/orders";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const STATES: Array<{ key?: string; label: string; demo?: CheckoutDemo }> = [
  { label: "Mặc định (chỉ Liên hệ QTV)" },
  { key: "momo", label: "Sau này: có cả MoMo" },
  { key: "dang-gui", label: "Đang gửi" },
  { key: "co-don-cho", label: "Đã có đơn chờ (bấm Gửi đơn)", demo: "pending-exists" },
  { key: "thay-doi", label: "Giá/mã đổi (bấm Gửi đơn)", demo: "changed" },
  { key: "qua-nhieu", label: "Quá 5 đơn/ngày (bấm Gửi đơn)", demo: "too-many" },
  { key: "tam-dong", label: "Tạm đóng 503 (bấm Gửi đơn)", demo: "disabled" },
  { key: "chua-xac-thuc", label: "Chưa xác thực (bấm Gửi đơn)", demo: "not-verified" },
  { key: "don-0d", label: "Đơn 0đ" },
];

const toItems = (c: typeof cart): OrderItem[] =>
  c.items
    .filter((i) => !i.unavailable)
    .map((i) => ({ course_id: i.course_id, title: i.title, slug: i.slug, grade_level: i.grade_level, price: i.price, discount_amount: i.discount_amount ?? 0, final_amount: i.final_amount ?? i.price }));

/**
 * Thanh toán (US-022 FW3; US-005 cho MoMo sau này). Route thật đề xuất `/thanh-toan` (thay `/checkout` của đặc tả v1).
 * Danh sách phương thức do server quyết định (`payment_methods` của GET /checkout/preview); hiện chỉ có `manual`.
 * TODO(dev): GET /checkout/preview (SSR); `can_checkout=false` + giỏ trống → redirect /gio-hang; `removed_items[]` → Alert.
 */
export default async function CheckoutPreview({ searchParams }: PageProps<"/v2/thanh-toan">) {
  const sp = await searchParams;
  const state = one(sp["trang-thai"]);
  const demo: CheckoutDemo = STATES.find((s) => s.key === state)?.demo ?? "ok";

  const methods: PaymentMethod[] = state === "momo" ? ["manual", "momo"] : checkoutConfig.payment_methods;
  const zero = state === "don-0d";
  const replacing = state === "co-don-cho";
  const items = zero ? toItems(cartZeroTotal) : replacing ? replacementOrder.items : toItems(cart);
  const pricing = zero ? cartZeroTotal.pricing : replacing ? replacementOrder.pricing : cart.pricing;
  const couponCode = zero ? cartZeroTotal.coupon?.code ?? null : cart.coupon?.code ?? null;

  return (
    <StudentShell
      current="cart"
      loggedIn
      paidCheckoutEnabled
      cartCount={items.length}
      hideBottomNav
      reserveBottomBar
      preview={
        <PreviewBar
          variants={STATES.map((s) => ({ label: s.label, href: s.key ? `${routes.checkout}?trang-thai=${s.key}` : routes.checkout, current: s.key === state }))}
          note="Biến thể “bấm Gửi đơn”: lần bấm đầu trả lỗi, bấm lại thì thành công."
        />
      }
    >
      <div className="mx-auto max-w-6xl px-4 pb-14 pt-4 sm:px-6">
        <Link href={routes.cart} className="focus-ring -ml-1 inline-flex min-h-11 items-center gap-1 rounded px-1 text-sm font-semibold text-primary">
          <IconChevronLeft size={18} /> Quay lại giỏ hàng
        </Link>
        <h1 className="mt-1 text-title font-extrabold tracking-heading text-ink md:text-title-lg">Thanh toán</h1>
        <div className="mt-6">
          <CheckoutForm
            key={state ?? "mac-dinh"}
            items={<OrderItemRows items={items} />}
            methods={methods}
            pricing={pricing}
            couponCode={couponCode}
            ttlHours={checkoutConfig.manual_payment.pending_ttl_hours}
            pendingOrder={pendingOrder}
            demo={demo}
            // Đơn 0đ đã `paid` ngay → màn kết quả hiện trạng thái "Đã thanh toán" (mã mẫu của một đơn đã duyệt).
            successCode={replacing ? replacementOrder.code : zero ? "VV2610023M8RTA" : pendingOrder.code}
            initialSubmitting={state === "dang-gui"}
          />
        </div>
      </div>
    </StudentShell>
  );
}
