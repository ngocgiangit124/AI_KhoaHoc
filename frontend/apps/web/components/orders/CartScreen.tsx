"use client";

import Link from "next/link";
import { useRef, useState, type FormEvent } from "react";
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  EmptyState,
  Field,
  IconArrowRight,
  IconCart,
  IconTicket,
  IconTrash,
  IconX,
  Sheet,
  Skeleton,
  TextInput,
  formatPrice,
  useToast,
} from "@vitaminvui/ui/v2";
import { useAuth } from "@/lib/auth/AuthProvider";
import { applyCoupon, fetchCart, removeCartItem, removeCoupon } from "@/lib/orders/api";
import { classifyCartError, classifyCouponError } from "@/lib/orders/errors";
import { deadlineText, payableCount } from "@/lib/orders/format";
import { usePaymentConfig } from "@/lib/orders/usePaymentConfig";
import type { Cart, CartItem, PendingOrderRef } from "@/lib/orders/schemas";
import { routes } from "@/lib/routes";
import { fromCartItem, ItemCover, PriceCell, PricingSummary } from "./OrderParts";
import { OrdersNotice } from "./OrdersNotice";
import { PageSkeletonRegion, RequireUser } from "@/components/my/RequireUser";
import { useOrderLoad } from "./useOrderLoad";

function CartSkeleton() {
  return (
    <PageSkeletonRegion label="Đang tải giỏ hàng…">
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <Sheet padding="md" className="flex flex-col gap-4">
          <Skeleton className="h-7 w-56" />
          {Array.from({ length: 2 }).map((_, i) => (
            <div key={i} className="flex gap-3">
              <Skeleton className="h-14 w-24" />
              <div className="flex flex-1 flex-col gap-2">
                <Skeleton className="h-5 w-3/4" />
                <Skeleton className="h-4 w-24" />
              </div>
            </div>
          ))}
        </Sheet>
        <Sheet padding="md" className="flex flex-col gap-3">
          <Skeleton className="h-6 w-32" />
          <Skeleton className="h-11 w-full" />
          <Skeleton className="h-24 w-full" />
        </Sheet>
      </div>
    </PageSkeletonRegion>
  );
}

/**
 * Giỏ hàng (US-004, US-022 FW3): xoá khóa, nhập/gỡ mã giảm giá, tổng tiền, sang bước thanh toán. Mọi số tiền lấy từ `pricing` server trả về
 * sau mỗi thao tác (không tự tính). Mobile: thanh dính đáy (tổng + nút); desktop: cột phải dính.
 */
export function CartView({ initial }: { initial: Cart }) {
  const toast = useToast();
  const { refresh } = useAuth();
  const payment = usePaymentConfig();
  const [cart, setCart] = useState(initial);
  // Các route ghi giỏ không trả `pending_order` nên giữ lại giá trị của lần tải đầu (`GET /cart`).
  const [pendingOrder] = useState(initial.pending_order ?? null);
  const [code, setCode] = useState("");
  const [couponError, setCouponError] = useState<string>();
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState<string | null>(null);
  const lock = useRef(false);

  const paymentsOpen = payment.status !== "ready" || payment.config.paid_checkout_enabled;
  const available = payableCount(cart.items) > 0;
  const canContinue = available && paymentsOpen;

  /** Mỗi lần chỉ một thao tác (chặn bấm kép); kết quả server thay toàn bộ giỏ. */
  async function run(key: string, action: () => Promise<Cart>, onOk?: (next: Cart) => void, onFail?: (err: unknown) => void) {
    if (lock.current) return;
    lock.current = true;
    setBusy(key);
    setError(undefined);
    try {
      const next = await action();
      setCart(next);
      void refresh(); // `cart_count` của header
      onOk?.(next);
    } catch (err) {
      if (onFail) onFail(err);
      else setError(classifyCartError(err).message);
    } finally {
      lock.current = false;
      setBusy(null);
    }
  }

  function remove(item: CartItem) {
    void run(`rm-${item.course_id}`, () => removeCartItem(item.course_id), () => toast.show({ tone: "success", title: "Đã xoá khỏi giỏ hàng", description: item.title }));
  }

  function apply(e: FormEvent) {
    e.preventDefault();
    const value = code.trim().toUpperCase();
    if (!value) {
      setCouponError("Nhập mã giảm giá trước khi bấm Áp dụng.");
      return;
    }
    void run(
      "apply",
      () => applyCoupon(value),
      () => {
        setCouponError(undefined);
        setCode("");
        toast.show({ tone: "success", title: `Đã áp dụng mã ${value}` });
      },
      (err) => setCouponError(classifyCouponError(err)),
    );
  }

  const coupon = cart.coupon;
  if (cart.items.length === 0) return <EmptyCart pendingOrder={pendingOrder} />;
  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
      <div className="flex min-w-0 flex-col gap-4">
        {error ? <Alert tone="danger" role="alert">{error}</Alert> : null}
        {cart.notices.map((n) => (
          <Alert key={n.code} tone="warning">
            {n.message}
          </Alert>
        ))}
        <PendingOrderAlert order={pendingOrder} />
        {!paymentsOpen ? (
          <Alert tone="info" title="Đặt mua đang tạm đóng">
            Giỏ hàng của bạn vẫn được giữ. Bạn có thể quay lại đặt mua sau.
          </Alert>
        ) : null}

        <Sheet as="section" aria-labelledby="khoa-trong-gio" padding="none">
          <h2 id="khoa-trong-gio" className="px-5 pt-5 text-heading font-extrabold tracking-heading text-ink sm:px-6">
            Khóa học trong giỏ <span className="num text-ink-soft">({cart.items.length})</span>
          </h2>
          <ul className="mt-2 divide-y divide-line px-5 sm:px-6">
            {cart.items.map((it) => {
              const line = fromCartItem(it);
              return (
                <li key={it.course_id} className="flex gap-3 py-4">
                  <Link href={routes.course(it.slug)} tabIndex={-1} aria-hidden="true" className="w-20 shrink-0 sm:w-28">
                    <ItemCover item={line} />
                  </Link>
                  <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:gap-3">
                      <div className="min-w-0 flex-1">
                        <Link
                          href={routes.course(it.slug)}
                          className={`focus-ring break-words rounded text-base font-semibold leading-snug hover:text-primary ${it.unavailable ? "text-ink-soft" : "text-ink"}`}
                        >
                          {it.title}
                        </Link>
                        {it.grade_level ? <p className="text-sm text-ink-soft">Lớp {it.grade_level}</p> : null}
                      </div>
                      {it.unavailable ? null : <PriceCell price={it.price} discount={line.discount} final={line.final} />}
                    </div>
                    {it.unavailable ? (
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone="warning" size="sm">
                          Không còn bán
                        </Badge>
                        <span className="text-sm text-ink-soft">Không tính vào đơn.</span>
                      </div>
                    ) : coupon && coupon.applies_to_course_ids.includes(it.course_id) ? (
                      <p className="text-sm font-medium text-success">Đã áp dụng mã {coupon.code}</p>
                    ) : null}
                    <div>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="-ml-3 h-11 sm:h-9"
                        leadingIcon={<IconTrash size={16} />}
                        loading={busy === `rm-${it.course_id}`}
                        loadingText="Đang xoá…"
                        disabled={busy !== null && busy !== `rm-${it.course_id}`}
                        onClick={() => remove(it)}
                        aria-label={`Xoá khóa ${it.title} khỏi giỏ`}
                      >
                        Xoá
                      </Button>
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>
        </Sheet>
      </div>

      <aside aria-label="Mã giảm giá và tổng tiền" className="flex flex-col gap-4 lg:sticky lg:top-24">
        <Sheet as="section" aria-labelledby="ma-giam-gia" padding="md">
          <h2 id="ma-giam-gia" className="flex items-center gap-2 text-lg font-semibold text-ink">
            <IconTicket className="text-primary" />
            Mã giảm giá
          </h2>
          {coupon ? (
            <div className="mt-3 flex items-start gap-3 rounded-control bg-success-soft p-3">
              <div className="min-w-0 flex-1">
                <p className="num break-all font-semibold text-ink">{coupon.code}</p>
                {coupon.name ? <p className="text-sm text-ink">{coupon.name}</p> : null}
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="h-11 sm:h-9"
                leadingIcon={<IconX size={16} />}
                loading={busy === "unapply"}
                loadingText="Đang gỡ…"
                disabled={busy !== null && busy !== "unapply"}
                onClick={() => void run("unapply", removeCoupon, () => toast.show({ tone: "info", title: `Đã gỡ mã ${coupon.code}` }))}
              >
                Gỡ mã
              </Button>
            </div>
          ) : (
            <form onSubmit={apply} className="mt-3 flex flex-col gap-2" noValidate>
              <div className="flex items-start gap-2">
                <Field label="Nhập mã" error={couponError} className="flex-1">
                  <TextInput
                    value={code}
                    onChange={(e) => {
                      setCode(e.target.value);
                      if (couponError) setCouponError(undefined);
                    }}
                    autoCapitalize="characters"
                    autoComplete="off"
                    spellCheck={false}
                    maxLength={50}
                    className="uppercase"
                  />
                </Field>
                <Button type="submit" variant="secondary" className="mt-6.5" loading={busy === "apply"} loadingText="Đang áp dụng…" disabled={busy !== null && busy !== "apply"}>
                  Áp dụng
                </Button>
              </div>
              <p className="text-sm text-ink-soft">Mỗi đơn dùng một mã.</p>
            </form>
          )}
        </Sheet>

        <Sheet as="section" padding="md" aria-labelledby="tom-tat" className="hidden lg:block">
          <h2 id="tom-tat" className="text-lg font-semibold text-ink">
            Tóm tắt
          </h2>
          <PricingSummary pricing={cart.pricing} couponCode={coupon?.code} className="mt-3" />
          <ContinueButton enabled={canContinue} className="mt-5" />
          <NextStepNote available={available} paymentsOpen={paymentsOpen} />
        </Sheet>

        <Sheet as="section" padding="md" aria-label="Tóm tắt" className="lg:hidden">
          <PricingSummary pricing={cart.pricing} couponCode={coupon?.code} />
          <NextStepNote available={available} paymentsOpen={paymentsOpen} />
        </Sheet>
      </aside>

      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3 shadow-overlay lg:hidden">
        <div className="mx-auto flex max-w-2xl items-center gap-3">
          <div className="num flex-1">
            <p className="text-sm text-ink-soft">Tổng cộng</p>
            <p className="text-lg font-extrabold text-ink">{cart.pricing.total === 0 ? "0đ" : formatPrice(cart.pricing.total)}</p>
          </div>
          <ContinueButton enabled={canContinue} />
        </div>
      </div>
    </div>
  );
}

function ContinueButton({ enabled, className }: { enabled: boolean; className?: string }) {
  if (!enabled) {
    return (
      <Button size="lg" disabled className={className} block>
        Tiếp tục đặt mua
      </Button>
    );
  }
  return (
    <ButtonLink href={routes.checkout} size="lg" block className={className} trailingIcon={<IconArrowRight size={18} />}>
      Tiếp tục đặt mua
    </ButtonLink>
  );
}

function NextStepNote({ available, paymentsOpen }: { available: boolean; paymentsOpen: boolean }) {
  return (
    <p className="mt-3 text-sm text-ink-soft">
      {!available
        ? "Giỏ không còn khóa nào đang bán. Xoá khóa không còn bán rồi chọn khóa khác để tiếp tục."
        : !paymentsOpen
          ? "Đặt mua đang tạm đóng nên chưa thể tiếp tục."
          : "Bước sau: chọn cách thanh toán và gửi đơn. Bạn chưa phải trả tiền ở bước này."}
    </p>
  );
}

/** Cảnh báo "đang có đơn chờ duyệt" (T16-1): dùng cho giỏ có hàng lẫn giỏ trống (vừa đặt đơn xong giỏ rỗng). Không chặn thanh toán. */
function PendingOrderAlert({ order }: { order: PendingOrderRef | null }) {
  if (!order) return null;
  return (
    <Alert
      tone="info"
      title={`Bạn đang có đơn ${order.code} chờ Quản trị viên duyệt`}
      action={
        <ButtonLink href={routes.orderSent(order.code)} variant="secondary" size="sm">
          Xem đơn
        </ButtonLink>
      }
    >
      {order.expires_at ? `Hạn chờ: ${deadlineText(order.expires_at)}. ` : ""}
      Nếu bạn đã chuyển khoản cho đơn đó, đừng đặt đơn mới — hãy chờ Quản trị viên xác nhận. Bạn vẫn có thể tiếp tục thanh toán giỏ này.
    </Alert>
  );
}

function EmptyCart({ pendingOrder }: { pendingOrder: PendingOrderRef | null }) {
  return (
    <div className="flex flex-col gap-4">
    <PendingOrderAlert order={pendingOrder} />
    <Sheet>
      <EmptyState
        icon={<IconCart size={32} />}
        title="Giỏ hàng đang trống"
        description="Thêm khóa học có phí vào giỏ để đặt mua một lần. Khóa miễn phí thì đăng ký trực tiếp ở trang khóa học."
        action={<ButtonLink href={routes.catalog}>Khám phá khóa học</ButtonLink>}
      />
    </Sheet>
    </div>
  );
}

function CartContent() {
  const [state, retry] = useOrderLoad(fetchCart, 0);
  if (state.status === "loading") return <CartSkeleton />;
  if (state.status === "failed") return <OrdersNotice kind={state.kind} onRetry={retry} what="cart" />;
  if (state.data.items.length === 0) return <EmptyCart pendingOrder={state.data.pending_order ?? null} />;
  return <CartView initial={state.data} />;
}

/** `/gio-hang` (US-004 + US-022): `GET /cart`. Khách -> đăng nhập rồi quay lại. */
export function CartScreen() {
  return (
    <div className="mx-auto w-full max-w-6xl px-4 pb-28 pt-6 sm:px-6 lg:pb-14">
      <h1 className="text-title font-extrabold tracking-heading text-ink md:text-title-lg">Giỏ hàng</h1>
      <div className="mt-6">
        <RequireUser next={routes.cart} skeleton={<CartSkeleton />}>
          <CartContent />
        </RequireUser>
      </div>
    </div>
  );
}
