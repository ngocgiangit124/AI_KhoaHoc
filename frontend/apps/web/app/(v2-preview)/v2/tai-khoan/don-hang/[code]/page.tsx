import Link from "next/link";
import { notFound } from "next/navigation";
import {
  Alert,
  Breadcrumb,
  ButtonLink,
  CopyButton,
  IconClock,
  IconPlay,
  IconRotateCcw,
  Sheet,
  formatDateTime,
} from "@vitaminvui/ui/v2";
import { CancelOrderButton } from "@/components/v2/orders/CancelOrderButton";
import { ContactChannels, OrderItemRows, OrderStatusBadge, PricingSummary, deadlineText, methodLabel } from "@/components/v2/orders/OrderParts";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { PREVIEW_NOW, checkoutConfig, findMyOrder, myOrders, pendingOrder, type StudentOrder } from "@/lib/mock/v2/orders";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

function statusVariantLabel(o: StudentOrder): string {
  if (o.status === "pending") return "Chờ duyệt";
  if (o.status === "paid") return "Đã thanh toán";
  if (o.status === "refunded") return "Đã hoàn tiền";
  return { admin_cancelled: "QTV huỷ (có lý do)", expired: "Quá hạn", superseded: "Thay bằng đơn mới", user_cancelled: "Tự huỷ" }[o.status_reason as string] ?? "Đã huỷ";
}

/** Dòng lịch sử phía học sinh (không có ghi chú nội bộ — AC14). */
function history(o: StudentOrder): Array<{ at: string; text: string }> {
  const rows = [{ at: o.created_at, text: "Bạn đặt đơn, chọn “Liên hệ Quản trị viên”" }];
  if (o.status === "paid" || o.status === "refunded") rows.push({ at: o.paid_at ?? o.created_at, text: "Quản trị viên xác nhận đã nhận tiền, khóa học được mở" });
  if (o.status === "refunded") rows.push({ at: "2026-09-05T09:00:00+07:00", text: "Đơn được hoàn tiền, quyền học bị thu hồi" });
  if (o.status === "cancelled" && o.cancelled_at) {
    const text = {
      admin_cancelled: "Quản trị viên huỷ đơn",
      expired: "Đơn tự huỷ vì quá hạn chờ duyệt",
      superseded: "Đơn được thay bằng đơn mới",
      user_cancelled: "Bạn huỷ đơn",
    }[o.status_reason as string] ?? "Đơn bị huỷ";
    rows.push({ at: o.cancelled_at, text });
  }
  return rows;
}

/**
 * Chi tiết đơn của học sinh (US-022 AC10–AC14). Route thật `/tai-khoan/don-hang/{code}`.
 * Đơn của người khác / không tồn tại → 404 (AC11, không lộ đơn có tồn tại).
 * TODO(dev): GET /orders/{code}; kênh liên hệ từ GET /config/public.
 */
export default async function MyOrderPreview({ params, searchParams }: PageProps<"/v2/tai-khoan/don-hang/[code]">) {
  const { code } = await params;
  const sp = await searchParams;
  const result = one(sp["ket-qua"]);
  const state = one(sp["trang-thai"]);
  const found = findMyOrder(code);
  if (!found) notFound();

  // Kết quả giả lập sau khi bấm "Huỷ đơn": thành công, hoặc 409 vì QTV vừa duyệt.
  let order: StudentOrder = found;
  if (result === "da-huy") order = { ...found, status: "cancelled", status_reason: "user_cancelled", cancelled_at: PREVIEW_NOW };
  if (result === "xung-dot") order = { ...found, status: "paid", status_reason: "manual_confirmed", paid_at: "2026-10-08T19:58:00+07:00" };

  const variants = [
    ...myOrders.map((o) => ({ label: statusVariantLabel(o), href: routes.myOrder(o.code), current: o.code === code && !result && !state })),
    { label: "Vừa huỷ xong", href: `${routes.myOrder(pendingOrder.code)}?ket-qua=da-huy`, current: result === "da-huy" },
    { label: "Huỷ bị 409 (QTV vừa duyệt)", href: `${routes.myOrder(pendingOrder.code)}?ket-qua=xung-dot`, current: result === "xung-dot" },
    { label: "Lỗi tải", href: `${routes.myOrder(pendingOrder.code)}?trang-thai=loi`, current: state === "loi" },
  ];

  const header = (
    <>
      <Breadcrumb items={[{ label: "Tài khoản", href: routes.account }, { label: "Đơn hàng của tôi", href: routes.myOrders }, { label: `Đơn ${order.code}` }]} />
      <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
        <h1 className="text-title font-extrabold tracking-heading text-ink md:text-title-lg">
          Đơn <span className="num">{order.code}</span>
        </h1>
        <CopyButton value={order.code} label="Sao chép mã" variant="ghost" copiedMessage={`Đã sao chép mã đơn ${order.code}`} />
      </div>
      <div className="mt-2">
        <OrderStatusBadge order={order} />
      </div>
    </>
  );

  let body: React.ReactNode;
  if (state === "loi") {
    body = (
      <Alert
        tone="danger"
        title="Không tải được đơn hàng"
        action={
          <ButtonLink href={routes.myOrder(code)} variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />}>
            Thử lại
          </ButtonLink>
        }
      >
        Kiểm tra kết nối mạng rồi thử lại.
      </Alert>
    );
  } else {
    body = (
      <>
        {result === "da-huy" ? (
          <Alert tone="success" title="Đã huỷ đơn">
            Các khóa vẫn nằm trong giỏ hàng nếu bạn muốn đặt lại.
          </Alert>
        ) : null}
        {result === "xung-dot" ? (
          <Alert tone="info" title="Không huỷ được: đơn vừa được duyệt">
            Quản trị viên đã xác nhận nhận tiền cho đơn này lúc {formatDateTime(order.paid_at ?? PREVIEW_NOW)} nên đơn không huỷ được nữa. Khóa học đã mở cho bạn.
          </Alert>
        ) : null}

        <StatusPanel order={order} />

        <Sheet as="section" aria-labelledby="khoa-trong-don">
          <h2 id="khoa-trong-don" className="text-heading font-extrabold tracking-heading text-ink">
            Khóa học trong đơn
          </h2>
          <div className="mt-2">
            <OrderItemRows items={order.items} />
          </div>
          <div className="mt-2 border-t border-line pt-4">
            <PricingSummary pricing={order.pricing} couponCode={order.coupon?.code} />
          </div>
          <dl className="mt-4 grid gap-x-4 gap-y-2 text-base sm:grid-cols-[140px_1fr]">
            <dt className="text-ink-soft">Phương thức</dt>
            <dd className="text-ink">{methodLabel(order.payment_method)}</dd>
            <dt className="text-ink-soft">Ngày đặt</dt>
            <dd className="num text-ink">{formatDateTime(order.created_at)}</dd>
          </dl>
        </Sheet>

        {order.customer_note ? (
          <Sheet as="section" aria-labelledby="ghi-chu">
            <h2 id="ghi-chu" className="text-lg font-semibold text-ink">
              Ghi chú bạn đã gửi
            </h2>
            <p className="mt-2 whitespace-pre-line rounded-control bg-sunken p-3 text-base text-ink">{order.customer_note}</p>
          </Sheet>
        ) : null}

        <Sheet as="section" aria-labelledby="lich-su">
          <h2 id="lich-su" className="text-lg font-semibold text-ink">
            Lịch sử đơn
          </h2>
          <ol className="mt-3 flex flex-col">
            {history(order).map((h, i, all) => (
              <li key={h.at + h.text} className="relative flex gap-3 pb-4 last:pb-0">
                <span aria-hidden="true" className="relative flex w-3 justify-center">
                  <span className="mt-1.5 size-2.5 rounded-full bg-primary" />
                  {i < all.length - 1 ? <span className="absolute top-4 bottom-[-0.25rem] w-px bg-line-strong" /> : null}
                </span>
                <div className="flex-1">
                  <p className="text-base text-ink">{h.text}</p>
                  <p className="num text-sm text-ink-soft">{formatDateTime(h.at)}</p>
                </div>
              </li>
            ))}
          </ol>
        </Sheet>

        {order.status === "pending" ? (
          <section aria-labelledby="huy-don" className="rounded-sheet border border-danger/40 bg-surface p-5 sm:p-6">
            <h2 id="huy-don" className="text-lg font-semibold text-ink">
              Không muốn mua nữa?
            </h2>
            <p className="mt-1 text-base text-ink-soft">
              Huỷ đơn nếu bạn đặt nhầm hoặc đổi ý. Nếu đã chuyển khoản, đừng huỷ — hãy liên hệ Quản trị viên.
            </p>
            <div className="mt-4">
              <CancelOrderButton code={order.code} total={order.pricing.total} resultHref={`${routes.myOrder(order.code)}?ket-qua=da-huy`} />
            </div>
          </section>
        ) : null}
      </>
    );
  }

  return (
    <StudentShell current="account" loggedIn paidCheckoutEnabled cartCount={2} preview={<PreviewBar variants={variants} />}>
      <div className="mx-auto flex max-w-3xl flex-col gap-5 px-4 pb-14 pt-6 sm:px-6">
        <div>{header}</div>
        {body}
      </div>
    </StudentShell>
  );
}

function StatusPanel({ order }: { order: StudentOrder }) {
  if (order.status === "pending") {
    return (
      <section aria-labelledby="trang-thai" className="rounded-sheet border-2 border-warning/40 bg-surface p-5 sm:p-6">
        <h2 id="trang-thai" className="text-heading font-extrabold tracking-heading text-ink">
          Đang chờ Quản trị viên liên hệ
        </h2>
        {order.expires_at ? (
          <p className="mt-2 flex items-start gap-2 text-base text-ink">
            <IconClock size={18} className="mt-1 text-warning" />
            <span>
              Hạn chờ duyệt: <span className="num font-semibold">{deadlineText(order.expires_at)}</span>. Quá hạn mà chưa được duyệt thì đơn tự huỷ.
            </span>
          </p>
        ) : null}
        <p className="mt-2 text-base text-ink">
          Khi chuyển khoản, vui lòng ghi mã đơn <strong className="num">{order.code}</strong> trong nội dung chuyển khoản.
        </p>
        <ContactChannels contact={checkoutConfig.manual_payment.contact} orderCode={order.code} className="mt-4" />
      </section>
    );
  }
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
        Quản trị viên xác nhận đã nhận tiền lúc {formatDateTime(order.paid_at ?? order.created_at)}. Các khóa trong đơn đã mở trong “Khóa học của tôi”.
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
  const backToCart = (
    <ButtonLink href={routes.cart} variant="secondary" size="sm">
      Về giỏ hàng
    </ButtonLink>
  );
  switch (order.status_reason) {
    case "admin_cancelled":
      return (
        <Alert tone="danger" role="status" title="Quản trị viên đã huỷ đơn" action={backToCart}>
          <span className="font-semibold">Lý do: </span>
          <span className="whitespace-pre-line">{order.cancel_reason_public}</span>
          <br />
          Các khóa vẫn trong giỏ hàng, bạn có thể đặt lại khi sẵn sàng.
        </Alert>
      );
    case "expired":
      return (
        <Alert tone="warning" title="Đơn đã huỷ do quá hạn chờ" action={backToCart}>
          Đơn không được duyệt trong {checkoutConfig.manual_payment.pending_ttl_hours} giờ nên tự huỷ lúc {formatDateTime(order.cancelled_at ?? order.created_at)}. Nếu bạn đã chuyển khoản, hãy liên hệ Quản trị viên kèm mã đơn.
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
    default:
      return (
        <Alert tone="info" title="Bạn đã huỷ đơn này" action={backToCart}>
          Huỷ lúc {formatDateTime(order.cancelled_at ?? order.created_at)}. Các khóa vẫn trong giỏ hàng.
        </Alert>
      );
  }
}
