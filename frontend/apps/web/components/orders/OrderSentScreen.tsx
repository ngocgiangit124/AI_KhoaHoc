"use client";

import { useEffect } from "react";
import { Alert, ButtonLink, CopyButton, IconCheckCircle, IconClock, IconInfo, IconPlay, Sheet, formatDateTime, formatPrice } from "@vitaminvui/ui/v2";
import { RequireUser } from "@/components/my/RequireUser";
import { fetchOrder } from "@/lib/orders/api";
import { deadlineText, hasContactChannel, methodLabel, studentStatus } from "@/lib/orders/format";
import { usePaymentConfig } from "@/lib/orders/usePaymentConfig";
import { routes } from "@/lib/routes";
import { CancelOrderButton } from "./CancelOrderButton";
import { OrderDetailSkeleton, useLiveOrder } from "./OrderDetailScreen";
import { ContactChannels, fromOrderItem, OrderItemRows, OrderStatusBadge } from "./OrderParts";
import { OrdersNotice } from "./OrdersNotice";
import { useOrderLoad } from "./useOrderLoad";
import type { OrderDetail } from "@/lib/orders/schemas";

/** Đơn không còn `pending` (đã duyệt/huỷ/hết hạn): chỉ hiện trạng thái + liên kết chi tiết, KHÔNG hiện hướng dẫn liên hệ (AC9). */
function ClosedOrder({ order }: { order: OrderDetail }) {
  const s = studentStatus(order.status, order.status_reason);
  return (
    <Sheet className="flex flex-col items-start gap-3">
      <OrderStatusBadge status={order.status} reason={order.status_reason} />
      <h1 className="break-all text-title font-extrabold tracking-heading text-ink">
        Đơn <span className="num">{order.code}</span>
      </h1>
      <p className="text-base text-ink">
        {order.status === "paid"
          ? `Đơn đã thanh toán${order.paid_at ? ` lúc ${formatDateTime(order.paid_at)}` : ""}. Các khóa trong đơn đã mở trong “Khóa học của tôi”.`
          : `Trạng thái hiện tại: ${s.label.toLowerCase()}. Không cần liên hệ thanh toán cho đơn này nữa.`}
      </p>
      <div className="flex flex-wrap gap-2">
        {order.status === "paid" ? (
          <ButtonLink href={routes.myCourses} leadingIcon={<IconPlay size={16} />}>
            Vào học
          </ButtonLink>
        ) : null}
        <ButtonLink href={routes.myOrder(order.code)} variant="secondary">
          Xem chi tiết đơn
        </ButtonLink>
      </div>
    </Sheet>
  );
}

function PendingSent({ initial, reused }: { initial: OrderDetail; reused: boolean }) {
  const { order, notice, onCancel } = useLiveOrder(initial);
  const payment = usePaymentConfig();
  const manual = payment.status === "ready" ? payment.config.manual_payment : null;
  const items = order.items.map(fromOrderItem);

  if (order.status !== "pending") {
    return (
      <>
        {notice ? (
          <Alert tone={notice.tone} title={notice.title}>
            {notice.text}
          </Alert>
        ) : null}
        <ClosedOrder order={order} />
      </>
    );
  }

  return (
    <>
      {reused ? (
        <Alert tone="info" title="Đơn này bạn đã gửi trước đó">
          Giỏ hàng không đổi nên hệ thống giữ nguyên đơn cũ, không tạo đơn mới. Quản trị viên vẫn đang xử lý đơn này.
        </Alert>
      ) : null}

      <Sheet as="section" aria-labelledby="da-gui" padding="none" className="overflow-hidden">
        <div className="flex flex-col gap-3 p-5 sm:p-6">
          <span className="flex size-12 items-center justify-center rounded-full bg-success-soft text-success" aria-hidden="true">
            <IconCheckCircle size={28} />
          </span>
          <h1 id="da-gui" className="text-title font-extrabold tracking-heading text-ink md:text-title-lg">
            Đã gửi đơn
          </h1>
          <p className="text-base leading-relaxed text-ink">
            Quản trị viên sẽ liên hệ bạn để hướng dẫn thanh toán. Bạn <strong>chưa phải trả tiền</strong> trên website.
          </p>
          <div>
            <OrderStatusBadge status={order.status} reason={order.status_reason} />
          </div>
        </div>

        <div className="mx-5 mb-5 rounded-card border-2 border-dashed border-primary/50 bg-primary-soft p-4 sm:mx-6 sm:mb-6 sm:p-5">
          <p className="text-sm font-semibold text-primary">Mã đơn của bạn</p>
          <p className="num mt-1 break-all text-title font-extrabold tracking-wide text-ink md:text-title-lg">{order.code}</p>
          <div className="mt-3">
            <CopyButton value={order.code} label="Sao chép mã đơn" copiedMessage={`Đã sao chép mã đơn ${order.code}`} className="w-full sm:w-auto" />
          </div>
          <p className="mt-3 text-base text-ink">
            Khi chuyển khoản, vui lòng ghi mã đơn <strong className="num">{order.code}</strong> trong nội dung chuyển khoản.
          </p>
        </div>
      </Sheet>

      <Sheet as="section" aria-labelledby="lien-he">
        <h2 id="lien-he" className="text-heading font-extrabold tracking-heading text-ink">
          Liên hệ Quản trị viên
        </h2>
        <p className="mt-1 text-base text-ink-soft">Quản trị viên sẽ gọi hoặc nhắn cho bạn. Bạn cũng có thể chủ động liên hệ:</p>
        {manual && hasContactChannel(manual.contact) ? (
          <ContactChannels contact={manual.contact} orderCode={order.code} className="mt-4" />
        ) : payment.status === "loading" ? null : (
          <p className="mt-4 text-base text-ink">Quản trị viên sẽ liên hệ bạn qua email và số điện thoại trong tài khoản.</p>
        )}
        <div className="mt-4 flex gap-3 rounded-control bg-sunken p-3 text-sm text-ink">
          <IconInfo className="mt-0.5 shrink-0 text-info" />
          <p>VitaminVui không đăng số tài khoản trên website. Chỉ chuyển khoản theo hướng dẫn nhận được từ các kênh liên hệ ở trên.</p>
        </div>
      </Sheet>

      <Sheet as="section" aria-labelledby="thong-tin-don">
        <h2 id="thong-tin-don" className="text-heading font-extrabold tracking-heading text-ink">
          Thông tin đơn
        </h2>
        <dl className="mt-4 grid gap-x-4 gap-y-3 text-base sm:grid-cols-[140px_1fr]">
          <dt className="text-ink-soft">Hạn chờ duyệt</dt>
          <dd className="flex items-start gap-2 text-ink">
            <IconClock size={18} className="mt-0.5 shrink-0 text-warning" />
            <span className="num">{order.expires_at ? deadlineText(order.expires_at) : "—"}</span>
          </dd>
          <dt className="text-ink-soft">Tổng tiền</dt>
          <dd className="num font-semibold text-ink">
            {formatPrice(order.total)}
            {order.discount > 0 ? <span className="font-normal text-ink-soft"> (đã giảm {formatPrice(order.discount)})</span> : null}
          </dd>
          <dt className="text-ink-soft">Phương thức</dt>
          <dd className="text-ink">{methodLabel(order.payment_method)}</dd>
        </dl>
        <p className="mt-3 text-sm text-ink-soft">Quá hạn mà chưa được duyệt thì đơn tự huỷ. Các khóa vẫn nằm trong giỏ hàng cho tới khi đơn được duyệt.</p>
        <h3 className="mt-5 text-lg font-semibold text-ink">Gồm {order.items.length} khóa</h3>
        <OrderItemRows items={items} compact />
      </Sheet>

      <div className="flex flex-col gap-3 sm:flex-row">
        <ButtonLink href={routes.myOrder(order.code)} size="lg" block className="sm:w-auto">
          Xem đơn của tôi
        </ButtonLink>
        {order.can_cancel ? <CancelOrderButton code={order.code} total={order.total} onResult={(r) => void onCancel(r)} block /> : null}
      </div>
      <p className="-mt-2 text-sm text-ink-soft">Bạn cũng có thể xem lại mã đơn bất cứ lúc nào trong Tài khoản › Đơn hàng của tôi.</p>
    </>
  );
}

function SentContent({ code, reused }: { code: string; reused: boolean }) {
  const [state, retry] = useOrderLoad(fetchOrder, code);
  useEffect(() => {
    document.title = `Đơn ${code} đã gửi — VitaminVui`;
  }, [code]);
  if (state.status === "loading") return <OrderDetailSkeleton />;
  if (state.status === "failed") return <OrdersNotice kind={state.kind} onRetry={retry} what="order" />;
  return <PendingSent initial={state.data} reused={reused} />;
}

/** `/thanh-toan/da-gui/{code}` — "Đơn đã gửi" (US-022 AC9) và đích của link trong thư. Đơn của người khác / không có -> thông báo không tìm thấy. */
export function OrderSentScreen({ code, reused }: { code: string; reused: boolean }) {
  return (
    <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 px-4 pb-14 pt-6 sm:px-6">
      <RequireUser next={routes.orderSent(code)} skeleton={<OrderDetailSkeleton />}>
        <SentContent key={code} code={code} reused={reused} />
      </RequireUser>
    </div>
  );
}
