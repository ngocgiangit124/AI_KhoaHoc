import Link from "next/link";
import {
  Alert,
  Breadcrumb,
  ButtonLink,
  EmptyState,
  IconChevronRight,
  IconClock,
  IconReceipt,
  IconRotateCcw,
  formatDate,
  formatPrice,
} from "@vitaminvui/ui/v2";
import { OrdersSkeleton } from "@/components/v2/orders/OrdersSkeleton";
import { OrderStatusBadge, deadlineText, methodLabel } from "@/components/v2/orders/OrderParts";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { myOrders, type StudentOrder } from "@/lib/mock/v2/orders";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const STATES: Array<{ key?: string; label: string }> = [
  { label: "Có đơn" },
  { key: "dang-tai", label: "Đang tải" },
  { key: "rong", label: "Chưa có đơn" },
  { key: "loi", label: "Lỗi tải" },
];

function itemsText(o: StudentOrder): string {
  const first = o.items[0]?.title ?? "";
  return o.items.length > 1 ? `${first} và ${o.items.length - 1} khóa khác` : first;
}

/**
 * Đơn hàng của tôi (US-022 AC10, US-005 §2.3). Route thật `/tai-khoan/don-hang`. Mới nhất trước.
 * TODO(dev): GET /orders?page= (length-aware) → `Pagination` khi > 1 trang.
 */
export default async function MyOrdersPreview({ searchParams }: PageProps<"/v2/tai-khoan/don-hang">) {
  const sp = await searchParams;
  const state = one(sp["trang-thai"]);
  const orders = state === "rong" ? [] : myOrders;

  let body: React.ReactNode;
  if (state === "dang-tai") {
    body = <OrdersSkeleton />;
  } else if (state === "loi") {
    body = (
      <Alert
        tone="danger"
        title="Không tải được danh sách đơn hàng"
        action={
          <ButtonLink href={routes.myOrders} variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />}>
            Thử lại
          </ButtonLink>
        }
      >
        Kiểm tra kết nối mạng rồi thử lại.
      </Alert>
    );
  } else if (orders.length === 0) {
    body = (
      <div className="rounded-sheet border border-line bg-surface">
        <EmptyState
          icon={<IconReceipt size={32} />}
          title="Bạn chưa có đơn hàng nào"
          description="Khi bạn đặt mua khóa học có phí, đơn hàng và trạng thái thanh toán sẽ hiện ở đây."
          action={<ButtonLink href={routes.catalog}>Khám phá khóa học</ButtonLink>}
        />
      </div>
    );
  } else {
    body = (
      <ul className="flex flex-col gap-3">
        {orders.map((o) => (
          <li key={o.code}>
            <Link
              href={routes.myOrder(o.code)}
              className="focus-ring group flex items-start gap-3 rounded-sheet border border-line bg-surface p-4 transition-colors duration-150 hover:border-primary sm:p-5"
            >
              <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                  <span className="num text-base font-semibold text-ink group-hover:text-primary">
                    <span className="sr-only">Đơn </span>
                    {o.code}
                  </span>
                  <OrderStatusBadge order={o} size="sm" />
                </div>
                <p className="line-clamp-2 text-base text-ink">{itemsText(o)}</p>
                <p className="num text-sm text-ink-soft">
                  Đặt ngày {formatDate(o.created_at)} · {methodLabel(o.payment_method)}
                </p>
                {o.status === "pending" && o.expires_at ? (
                  <p className="flex items-center gap-1.5 text-sm font-medium text-warning">
                    <IconClock size={16} />
                    <span className="num">Hạn chờ duyệt: {deadlineText(o.expires_at)}</span>
                  </p>
                ) : null}
              </div>
              <div className="num flex shrink-0 flex-col items-end gap-0.5 text-right">
                <span className="text-base font-semibold text-ink">{formatPrice(o.pricing.total)}</span>
                {o.pricing.discount > 0 ? <span className="text-sm text-success">đã giảm {formatPrice(o.pricing.discount)}</span> : null}
                <IconChevronRight className="mt-2 text-ink-soft" />
              </div>
            </Link>
          </li>
        ))}
      </ul>
    );
  }

  return (
    <StudentShell
      current="account"
      loggedIn
      paidCheckoutEnabled
      cartCount={2}
      preview={<PreviewBar variants={STATES.map((s) => ({ label: s.label, href: s.key ? `${routes.myOrders}?trang-thai=${s.key}` : routes.myOrders, current: s.key === state }))} />}
    >
      <div className="mx-auto max-w-3xl px-4 pb-14 pt-6 sm:px-6">
        <Breadcrumb items={[{ label: "Tài khoản", href: routes.account }, { label: "Đơn hàng của tôi" }]} />
        <h1 className="mt-2 text-title font-extrabold tracking-heading text-ink md:text-title-lg">Đơn hàng của tôi</h1>
        <div className="mt-6">{body}</div>
      </div>
    </StudentShell>
  );
}
