import { Alert, ButtonLink, EmptyState, IconCart, IconRotateCcw, LoadingRegion, Sheet, Skeleton } from "@vitaminvui/ui/v2";
import { CartView } from "@/components/v2/orders/CartView";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { COUPON_ERRORS, cart, cartCouponRemoved, cartNoCoupon, cartWithUnavailable, emptyCart, pendingOrder } from "@/lib/mock/v2/orders";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const STATES: Array<{ key?: string; label: string }> = [
  { label: "Có mã giảm giá" },
  { key: "chua-co-ma", label: "Chưa nhập mã" },
  { key: "ma-loi", label: "Mã sai" },
  { key: "ngung-ban", label: "Có khóa ngừng bán" },
  { key: "ma-bi-go", label: "Mã bị tự gỡ" },
  { key: "co-don-cho", label: "Đang có đơn chờ duyệt" },
  { key: "rong", label: "Giỏ trống" },
  { key: "dang-tai", label: "Đang tải" },
  { key: "loi", label: "Lỗi tải" },
];

/**
 * Giỏ hàng (US-004, US-022 FW3). Route thật `/gio-hang`, chỉ hiện khi `payment_methods` (config/public) khác rỗng.
 * TODO(dev): GET /cart (SSR có cookie); khách chưa đăng nhập → /dang-nhap?next=/gio-hang.
 */
export default async function CartPreview({ searchParams }: PageProps<"/v2/gio-hang">) {
  const sp = await searchParams;
  const state = one(sp["trang-thai"]);
  const data =
    state === "chua-co-ma" || state === "ma-loi"
      ? cartNoCoupon
      : state === "ngung-ban"
        ? cartWithUnavailable
        : state === "ma-bi-go"
          ? cartCouponRemoved
          : state === "rong"
            ? emptyCart
            : cart;

  let body: React.ReactNode;
  if (state === "dang-tai") {
    body = (
      <LoadingRegion label="Đang tải giỏ hàng…" className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <Sheet padding="md" className="flex flex-col gap-4">
          <Skeleton className="h-7 w-56" />
          {Array.from({ length: 2 }).map((_, i) => (
            <div key={i} className="flex gap-3">
              <Skeleton className="aspect-video w-20 sm:w-28" />
              <div className="flex flex-1 flex-col gap-2">
                <Skeleton className="h-5 w-4/5" />
                <Skeleton className="h-4 w-16" />
                <Skeleton className="h-9 w-20" />
              </div>
            </div>
          ))}
        </Sheet>
        <Sheet padding="md" className="flex flex-col gap-3">
          <Skeleton className="h-6 w-32" />
          <Skeleton className="h-11 w-full" />
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-13 w-full" />
        </Sheet>
      </LoadingRegion>
    );
  } else if (state === "loi") {
    body = (
      <Alert
        tone="danger"
        title="Không tải được giỏ hàng"
        action={
          <ButtonLink href={routes.cart} variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />}>
            Thử lại
          </ButtonLink>
        }
      >
        Kiểm tra kết nối mạng rồi thử lại. Các khóa bạn đã thêm vẫn được giữ.
      </Alert>
    );
  } else if (data.items.length === 0) {
    body = (
      <Sheet>
        <EmptyState
          icon={<IconCart size={32} />}
          title="Giỏ hàng đang trống"
          description="Thêm khóa học có phí vào giỏ để đặt mua một lần. Khóa miễn phí thì đăng ký trực tiếp ở trang khóa học."
          action={<ButtonLink href={routes.catalog}>Khám phá khóa học</ButtonLink>}
        />
      </Sheet>
    );
  } else {
    body = (
      <div className="flex flex-col gap-4">
        {state === "co-don-cho" ? (
          <Alert
            tone="info"
            title={`Bạn đang có đơn ${pendingOrder.code} chờ Quản trị viên duyệt`}
            action={
              <ButtonLink href={routes.myOrder(pendingOrder.code)} variant="secondary" size="sm">
                Xem đơn
              </ButtonLink>
            }
          >
            Nếu bạn đã chuyển khoản cho đơn đó, đừng đặt đơn mới — hãy chờ Quản trị viên xác nhận. Đặt đơn khác nội dung sẽ huỷ đơn cũ.
          </Alert>
        ) : null}
        <CartView
          key={state ?? "mac-dinh"}
          initial={data}
          initialCouponError={state === "ma-loi" ? COUPON_ERRORS.COUPON_EXPIRED : undefined}
          initialCouponInput={state === "ma-loi" ? "TOAN2025" : ""}
        />
      </div>
    );
  }

  return (
    <StudentShell
      current="cart"
      loggedIn
      paidCheckoutEnabled
      cartCount={data.items.length}
      hideBottomNav
      reserveBottomBar
      preview={<PreviewBar variants={STATES.map((s) => ({ label: s.label, href: s.key ? `${routes.cart}?trang-thai=${s.key}` : routes.cart, current: s.key === state }))} />}
    >
      <div className="mx-auto max-w-6xl px-4 pb-14 pt-6 sm:px-6">
        <h1 className="text-title font-extrabold tracking-heading text-ink md:text-title-lg">Giỏ hàng</h1>
        <div className="mt-6">{body}</div>
      </div>
    </StudentShell>
  );
}
