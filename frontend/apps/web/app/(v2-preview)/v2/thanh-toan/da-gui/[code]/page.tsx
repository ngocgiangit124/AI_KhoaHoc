import { notFound } from "next/navigation";
import {
  Alert,
  ButtonLink,
  CopyButton,
  IconCheckCircle,
  IconClock,
  IconInfo,
  IconPlay,
  Sheet,
  formatDateTime,
  formatPrice,
} from "@vitaminvui/ui/v2";
import { CancelOrderButton } from "@/components/v2/orders/CancelOrderButton";
import { ContactChannels, OrderItemRows, OrderStatusBadge, deadlineText, methodLabel, studentStatus } from "@/components/v2/orders/OrderParts";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { checkoutConfig, contactEmailOnly, findMyOrder, pendingOrder } from "@/lib/mock/v2/orders";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

/**
 * "Đơn đã gửi — chờ Quản trị viên liên hệ" (US-022 AC9). Route thật đề xuất `/thanh-toan/da-gui/{code}`.
 * Mở lại khi đơn đã duyệt/huỷ: chỉ hiện trạng thái hiện tại + liên kết chi tiết, không hiện hướng dẫn liên hệ.
 * KHÔNG hiện số tài khoản / QR (story Q2). Kênh liên hệ trống thì ẩn (Q1).
 * TODO(dev): GET /orders/{code} (404 nếu không phải đơn của mình) + `manual_payment.contact` từ GET /config/public.
 */
export default async function OrderSentPreview({ params, searchParams }: PageProps<"/v2/thanh-toan/da-gui/[code]">) {
  const { code } = await params;
  const sp = await searchParams;
  const state = one(sp["trang-thai"]);
  const order = findMyOrder(code);
  if (!order) notFound();

  const contact = state === "it-kenh" ? contactEmailOnly : checkoutConfig.manual_payment.contact;
  const variants = [
    { label: "Vừa gửi (đủ 4 kênh)", href: routes.orderSent(pendingOrder.code), current: order.code === pendingOrder.code && !state },
    { label: "Chỉ có email hỗ trợ", href: `${routes.orderSent(pendingOrder.code)}?trang-thai=it-kenh`, current: state === "it-kenh" },
    { label: "Gửi lại cùng giỏ (dùng lại đơn)", href: `${routes.orderSent(pendingOrder.code)}?trang-thai=dung-lai`, current: state === "dung-lai" },
    { label: "Mở lại khi đã duyệt", href: routes.orderSent("VV2610023M8RTA"), current: order.code === "VV2610023M8RTA" },
    { label: "Mở lại khi đã huỷ", href: routes.orderSent("VV260925Q1ZD4H"), current: order.code === "VV260925Q1ZD4H" },
  ];

  const shell = (children: React.ReactNode) => (
    <StudentShell current="account" loggedIn paidCheckoutEnabled cartCount={2} preview={<PreviewBar variants={variants} />}>
      <div className="mx-auto flex max-w-2xl flex-col gap-5 px-4 pb-14 pt-6 sm:px-6">{children}</div>
    </StudentShell>
  );

  if (order.status !== "pending") {
    const s = studentStatus(order.status, order.status_reason);
    return shell(
      <Sheet className="flex flex-col items-start gap-3">
        <OrderStatusBadge order={order} />
        <h1 className="text-title font-extrabold tracking-heading text-ink">
          Đơn <span className="num">{order.code}</span>
        </h1>
        <p className="text-base text-ink">
          {order.status === "paid"
            ? `Đơn đã thanh toán lúc ${formatDateTime(order.paid_at ?? order.created_at)}. Các khóa trong đơn đã mở trong “Khóa học của tôi”.`
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
      </Sheet>,
    );
  }

  return shell(
    <>
      {state === "dung-lai" ? (
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
            <OrderStatusBadge order={order} />
          </div>
        </div>

        {/* Điểm nhấn của màn: "phiếu" mã đơn — to, dễ đọc, sao chép một chạm. */}
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
        <ContactChannels contact={contact} orderCode={order.code} className="mt-4" />
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
            <IconClock size={18} className="mt-0.5 text-warning" />
            <span className="num">{order.expires_at ? deadlineText(order.expires_at) : "—"}</span>
          </dd>
          <dt className="text-ink-soft">Tổng tiền</dt>
          <dd className="num font-semibold text-ink">
            {formatPrice(order.pricing.total)}
            {order.pricing.discount > 0 ? <span className="font-normal text-ink-soft"> (đã giảm {formatPrice(order.pricing.discount)})</span> : null}
          </dd>
          <dt className="text-ink-soft">Phương thức</dt>
          <dd className="text-ink">{methodLabel(order.payment_method)}</dd>
        </dl>
        <p className="mt-3 text-sm text-ink-soft">
          Quá hạn mà chưa được duyệt thì đơn tự huỷ. Các khóa vẫn nằm trong giỏ hàng cho tới khi đơn được duyệt.
        </p>
        <h3 className="mt-5 text-lg font-semibold text-ink">Gồm {order.items.length} khóa</h3>
        <OrderItemRows items={order.items} compact />
      </Sheet>

      <div className="flex flex-col gap-3 sm:flex-row">
        <ButtonLink href={routes.myOrder(order.code)} size="lg" block className="sm:w-auto">
          Xem đơn của tôi
        </ButtonLink>
        <CancelOrderButton code={order.code} total={order.pricing.total} resultHref={`${routes.myOrder(order.code)}?ket-qua=da-huy`} block />
      </div>
      <p className="-mt-2 text-sm text-ink-soft">Bạn cũng có thể xem lại mã đơn bất cứ lúc nào trong Tài khoản › Đơn hàng của tôi.</p>
    </>,
  );
}
