"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert, Breadcrumb, ButtonLink, CopyButton, IconClock, IconPlay, Sheet, Skeleton, formatDateTime } from "@vitaminvui/ui/v2";
import { RequireUser, PageSkeletonRegion } from "@/components/my/RequireUser";
import { fetchOrder } from "@/lib/orders/api";
import { deadlineText, hasContactChannel, methodLabel, orderHistory } from "@/lib/orders/format";
import type { ManualContact, OrderDetail } from "@/lib/orders/schemas";
import { usePaymentConfig } from "@/lib/orders/usePaymentConfig";
import { routes } from "@/lib/routes";
import { CancelOrderButton, type CancelResult } from "./CancelOrderButton";
import { ContactChannels, fromOrderItem, OrderItemRows, OrderStatusBadge, PricingSummary } from "./OrderParts";
import { OrdersNotice } from "./OrdersNotice";
import { useOrderLoad } from "./useOrderLoad";

export function OrderDetailSkeleton() {
  return (
    <PageSkeletonRegion label="Đang tải đơn hàng…">
      <Skeleton className="h-8 w-72 max-w-full" />
      <Skeleton className="h-6 w-40" />
      <Sheet padding="md" className="flex flex-col gap-3">
        <Skeleton className="h-6 w-56" />
        <Skeleton className="h-16 w-full" />
        <Skeleton className="h-16 w-full" />
      </Sheet>
    </PageSkeletonRegion>
  );
}

export type OrderNotice = { tone: "success" | "info" | "warning"; title: string; text: string };

/** Giữ đơn trong state để cập nhật sau huỷ / sau 409 (tải lại đơn). */
export function useLiveOrder(initial: OrderDetail) {
  const [order, setOrder] = useState(initial);
  const [notice, setNotice] = useState<OrderNotice | null>(null);

  async function onCancel(r: CancelResult) {
    if (r.type === "cancelled") {
      setOrder(r.order);
      setNotice({ tone: "success", title: "Đã huỷ đơn", text: "Các khóa vẫn nằm trong giỏ hàng nếu bạn muốn đặt lại." });
      return;
    }
    if (r.type === "conflict") {
      try {
        const fresh = await fetchOrder(order.code);
        setOrder(fresh);
        setNotice({
          tone: "info",
          title: fresh.status === "paid" ? "Không huỷ được: đơn vừa được duyệt" : "Đơn đã đổi trạng thái",
          text:
            fresh.status === "paid"
              ? `Quản trị viên đã xác nhận nhận tiền cho đơn này${fresh.paid_at ? ` lúc ${formatDateTime(fresh.paid_at)}` : ""} nên đơn không huỷ được nữa. Khóa học đã mở cho bạn.`
              : r.message,
        });
      } catch {
        setNotice({ tone: "warning", title: "Đơn đã đổi trạng thái", text: `${r.message} Hãy tải lại trang để xem trạng thái mới.` });
      }
      return;
    }
    setNotice({ tone: "warning", title: "Không tìm thấy đơn", text: "Đơn này không còn tồn tại hoặc không thuộc tài khoản của bạn." });
  }
  return { order, notice, onCancel };
}

/** Khung "đang chờ liên hệ" của đơn pending (dùng ở chi tiết đơn). Hướng dẫn liên hệ CHỈ khi còn pending (AC12/AC14). */
function PendingPanel({ order, contact }: { order: OrderDetail; contact: ManualContact | null }) {
  return (
    <section aria-labelledby="trang-thai" className="rounded-sheet border-2 border-warning/40 bg-surface p-5 sm:p-6">
      <h2 id="trang-thai" className="text-heading font-extrabold tracking-heading text-ink">
        Đang chờ Quản trị viên liên hệ
      </h2>
      {order.expires_at ? (
        <p className="mt-2 flex items-start gap-2 text-base text-ink">
          <IconClock size={18} className="mt-1 shrink-0 text-warning" />
          <span>
            Hạn chờ duyệt: <span className="num font-semibold">{deadlineText(order.expires_at)}</span>. Quá hạn mà chưa được duyệt thì đơn tự huỷ.
          </span>
        </p>
      ) : null}
      <p className="mt-2 text-base text-ink">
        Khi chuyển khoản, vui lòng ghi mã đơn <strong className="num">{order.code}</strong> trong nội dung chuyển khoản. Bạn chưa phải trả tiền qua website.
      </p>
      {contact && hasContactChannel(contact) ? <ContactChannels contact={contact} orderCode={order.code} className="mt-4" /> : null}
    </section>
  );
}

function StatusPanel({ order, contact, ttlHours }: { order: OrderDetail; contact: ManualContact | null; ttlHours: number | undefined }) {
  if (order.status === "pending") return <PendingPanel order={order} contact={contact} />;
  if (order.status === "paid") {
    return (
      <Alert
        tone="success"
        title="Đã thanh toán"
        action={
          <ButtonLink href={routes.myCourses} size="sm" leadingIcon={<IconPlay size={16} />}>
            Vào học
          </ButtonLink>
        }
      >
        {order.paid_at ? `Đơn được xác nhận lúc ${formatDateTime(order.paid_at)}. ` : ""}Các khóa trong đơn đã mở trong “Khóa học của tôi”.
      </Alert>
    );
  }
  if (order.status === "refunded") {
    return (
      <Alert tone="info" title="Đơn đã được hoàn tiền">
        Quyền học các khóa trong đơn đã được thu hồi. Cần hỗ trợ thêm, hãy liên hệ Quản trị viên.
      </Alert>
    );
  }
  if (order.status === "failed") {
    return (
      <Alert tone="danger" title="Thanh toán không thành công">
        Đơn này chưa được thanh toán. Bạn có thể đặt lại từ giỏ hàng.
      </Alert>
    );
  }
  const backToCart = (
    <ButtonLink href={routes.cart} variant="secondary" size="sm">
      Về giỏ hàng
    </ButtonLink>
  );
  switch (order.status_reason) {
    case "admin_cancelled":
      return (
        <Alert tone="danger" role="status" title="Quản trị viên đã huỷ đơn" action={backToCart}>
          {order.cancel_reason ? (
            <>
              <span className="font-semibold">Lý do: </span>
              <span className="whitespace-pre-line break-words">{order.cancel_reason}</span>
              <br />
            </>
          ) : null}
          Các khóa vẫn trong giỏ hàng, bạn có thể đặt lại khi sẵn sàng.
        </Alert>
      );
    case "expired":
      return (
        <Alert tone="warning" title="Đơn đã huỷ do quá hạn chờ" action={backToCart}>
          Đơn không được duyệt trong {ttlHours ?? 72} giờ nên tự huỷ{order.cancelled_at ? ` lúc ${formatDateTime(order.cancelled_at)}` : ""}. Nếu bạn đã chuyển khoản, hãy liên hệ Quản trị viên kèm mã đơn.
        </Alert>
      );
    case "superseded":
      return (
        <Alert tone="info" title="Đơn đã được thay bằng đơn mới">
          Bạn đã đặt đơn khác nội dung nên đơn này bị huỷ.{" "}
          {order.replaced_by_code ? (
            <Link href={routes.myOrder(order.replaced_by_code)} className="focus-ring num rounded font-semibold text-primary underline underline-offset-4">
              Xem đơn {order.replaced_by_code}
            </Link>
          ) : null}
        </Alert>
      );
    case "user_cancelled":
      return (
        <Alert tone="info" title="Bạn đã huỷ đơn này" action={backToCart}>
          {order.cancelled_at ? `Huỷ lúc ${formatDateTime(order.cancelled_at)}. ` : ""}Các khóa vẫn trong giỏ hàng.
        </Alert>
      );
    default:
      return (
        <Alert tone="info" title="Đơn đã huỷ" action={backToCart}>
          {order.cancelled_at ? `Huỷ lúc ${formatDateTime(order.cancelled_at)}. ` : ""}Các khóa vẫn trong giỏ hàng.
        </Alert>
      );
  }
}

function OrderDetailView({ initial }: { initial: OrderDetail }) {
  const { order: o, notice, onCancel } = useLiveOrder(initial);
  const payment = usePaymentConfig();
  const manual = payment.status === "ready" ? payment.config.manual_payment : null;
  const items = o.items.map(fromOrderItem);
  const history = orderHistory(o);

  return (
    <div className="flex flex-col gap-5">
      <div>
        <Breadcrumb items={[{ label: "Tài khoản", href: routes.account }, { label: "Đơn hàng của tôi", href: routes.myOrders }, { label: `Đơn ${o.code}` }]} />
        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
          <h1 className="break-all text-title font-extrabold tracking-heading text-ink md:text-title-lg">
            Đơn <span className="num">{o.code}</span>
          </h1>
          <CopyButton value={o.code} label="Sao chép mã" variant="ghost" copiedMessage={`Đã sao chép mã đơn ${o.code}`} />
        </div>
        <div className="mt-2">
          <OrderStatusBadge status={o.status} reason={o.status_reason} />
        </div>
      </div>

      {notice ? (
        <Alert tone={notice.tone} title={notice.title}>
          {notice.text}
        </Alert>
      ) : null}

      <StatusPanel order={o} contact={manual?.contact ?? null} ttlHours={manual?.pending_ttl_hours} />

      <Sheet as="section" aria-labelledby="khoa-trong-don">
        <h2 id="khoa-trong-don" className="text-heading font-extrabold tracking-heading text-ink">
          Khóa học trong đơn
        </h2>
        <div className="mt-2">
          <OrderItemRows items={items} />
        </div>
        <div className="mt-2 border-t border-line pt-4">
          <PricingSummary pricing={o} couponCode={o.coupon_code} />
        </div>
        <dl className="mt-4 grid gap-x-4 gap-y-2 text-base sm:grid-cols-[140px_1fr]">
          <dt className="text-ink-soft">Phương thức</dt>
          <dd className="text-ink">{methodLabel(o.payment_method)}</dd>
          <dt className="text-ink-soft">Ngày đặt</dt>
          <dd className="num text-ink">{formatDateTime(o.created_at)}</dd>
        </dl>
      </Sheet>

      {o.customer_note ? (
        <Sheet as="section" aria-labelledby="ghi-chu">
          <h2 id="ghi-chu" className="text-lg font-semibold text-ink">
            Ghi chú bạn đã gửi
          </h2>
          <p className="mt-2 whitespace-pre-line break-words rounded-control bg-sunken p-3 text-base text-ink">{o.customer_note}</p>
        </Sheet>
      ) : null}

      <Sheet as="section" aria-labelledby="lich-su">
        <h2 id="lich-su" className="text-lg font-semibold text-ink">
          Lịch sử đơn
        </h2>
        <ol className="mt-3 flex flex-col">
          {history.map((h, i, all) => (
            <li key={h.at + h.text} className="relative flex gap-3 pb-4 last:pb-0">
              <span aria-hidden="true" className="relative flex w-3 justify-center">
                <span className="mt-1.5 size-2.5 rounded-full bg-primary" />
                {i < all.length - 1 ? <span className="absolute bottom-[-0.25rem] top-4 w-px bg-line-strong" /> : null}
              </span>
              <div className="flex-1">
                <p className="text-base text-ink">{h.text}</p>
                <p className="num text-sm text-ink-soft">{formatDateTime(h.at)}</p>
              </div>
            </li>
          ))}
        </ol>
      </Sheet>

      {o.can_cancel ? (
        <section aria-labelledby="huy-don" className="rounded-sheet border border-danger/40 bg-surface p-5 sm:p-6">
          <h2 id="huy-don" className="text-lg font-semibold text-ink">
            Không muốn mua nữa?
          </h2>
          <p className="mt-1 text-base text-ink-soft">Huỷ đơn nếu bạn đặt nhầm hoặc đổi ý. Nếu đã chuyển khoản, đừng huỷ — hãy liên hệ Quản trị viên.</p>
          <div className="mt-4">
            <CancelOrderButton code={o.code} total={o.total} onResult={(r) => void onCancel(r)} />
          </div>
        </section>
      ) : null}
    </div>
  );
}

function OrderDetailContent({ code }: { code: string }) {
  const [state, retry] = useOrderLoad(fetchOrder, code);
  useEffect(() => {
    document.title = `Đơn ${code} — VitaminVui`;
  }, [code]);
  if (state.status === "loading") return <OrderDetailSkeleton />;
  if (state.status === "failed") return <OrdersNotice kind={state.kind} onRetry={retry} what="order" />;
  return <OrderDetailView initial={state.data} />;
}

/** `/tai-khoan/don-hang/{code}` (US-022 AC10–AC14): `GET /orders/{code}` (đơn người khác / không có -> thông báo "không tìm thấy"). */
export function OrderDetailScreen({ code }: { code: string }) {
  return (
    <div className="mx-auto w-full max-w-3xl px-4 pb-14 pt-6 sm:px-6">
      <RequireUser next={routes.myOrder(code)} skeleton={<OrderDetailSkeleton />}>
        <OrderDetailContent key={code} code={code} />
      </RequireUser>
    </div>
  );
}

