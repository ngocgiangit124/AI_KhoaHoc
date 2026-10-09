"use client";

import Link from "next/link";
import { Breadcrumb, ButtonLink, EmptyState, IconChevronRight, IconClock, IconReceipt, Pagination, Skeleton, formatDate, formatPrice } from "@vitaminvui/ui/v2";
import { RequireUser, PageSkeletonRegion } from "@/components/my/RequireUser";
import { fetchOrders } from "@/lib/orders/api";
import { deadlineText, itemsSummary, methodLabel } from "@/lib/orders/format";
import type { OrdersPage } from "@/lib/orders/schemas";
import { routes } from "@/lib/routes";
import { OrderStatusBadge } from "./OrderParts";
import { OrdersNotice } from "./OrdersNotice";
import { useOrderLoad } from "./useOrderLoad";

function OrdersSkeleton({ rows = 4 }: { rows?: number }) {
  return (
    <PageSkeletonRegion label="Đang tải đơn hàng…">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex gap-3 rounded-sheet border border-line bg-surface p-4 sm:p-5">
          <div className="flex flex-1 flex-col gap-2">
            <Skeleton className="h-6 w-64 max-w-full" />
            <Skeleton className="h-5 w-4/5" />
            <Skeleton className="h-4 w-48" />
          </div>
          <Skeleton className="h-6 w-20" />
        </div>
      ))}
    </PageSkeletonRegion>
  );
}

function OrdersList({ data, page }: { data: OrdersPage; page: number }) {
  if (data.data.length === 0) {
    if (data.meta.total > 0 && page > 1) {
      return (
        <div className="rounded-sheet border border-line bg-surface">
          <EmptyState icon={<IconReceipt size={32} />} title="Trang này không có đơn hàng" action={<ButtonLink href={routes.myOrders}>Về trang đầu</ButtonLink>} />
        </div>
      );
    }
    return (
      <div className="rounded-sheet border border-line bg-surface">
        <EmptyState
          icon={<IconReceipt size={32} />}
          title="Bạn chưa có đơn hàng nào"
          description="Khi bạn đặt mua khóa học có phí, đơn hàng và trạng thái thanh toán sẽ hiện ở đây."
          action={<ButtonLink href={routes.catalog}>Khám phá khóa học</ButtonLink>}
        />
      </div>
    );
  }
  return (
    <>
      <ul className="flex flex-col gap-3">
        {data.data.map((o) => (
          <li key={o.code}>
            <Link
              href={routes.myOrder(o.code)}
              className="focus-ring group flex items-start gap-3 rounded-sheet border border-line bg-surface p-4 transition-colors duration-150 hover:border-primary sm:p-5"
            >
              <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                  <span className="num break-all text-base font-semibold text-ink group-hover:text-primary">
                    <span className="sr-only">Đơn </span>
                    {o.code}
                  </span>
                  <OrderStatusBadge status={o.status} reason={o.status_reason} size="sm" />
                </div>
                <p className="line-clamp-2 break-words text-base text-ink">{itemsSummary(o.item_titles, o.items_count)}</p>
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
                <span className="text-base font-semibold text-ink">{o.total === 0 ? "0đ" : formatPrice(o.total)}</span>
                {o.discount > 0 ? <span className="text-sm text-success">đã giảm {formatPrice(o.discount)}</span> : null}
                <IconChevronRight className="mt-2 text-ink-soft" />
              </div>
            </Link>
          </li>
        ))}
      </ul>
      <Pagination
        currentPage={data.meta.current_page}
        lastPage={data.meta.last_page}
        hrefFor={(p) => (p > 1 ? `${routes.myOrders}?trang=${p}` : routes.myOrders)}
        className="mt-4"
      />
    </>
  );
}

function OrdersContent({ page }: { page: number }) {
  const [state, retry] = useOrderLoad(fetchOrders, page);
  if (state.status === "loading") return <OrdersSkeleton />;
  if (state.status === "failed") return <OrdersNotice kind={state.kind} onRetry={retry} what="orders" />;
  return <OrdersList data={state.data} page={page} />;
}

/** `/tai-khoan/don-hang` (US-022 AC10): `GET /orders?page=`, mới nhất trước, 10/trang. */
export function OrdersScreen({ page }: { page: number }) {
  return (
    <div className="mx-auto w-full max-w-3xl px-4 pb-14 pt-6 sm:px-6">
      <Breadcrumb items={[{ label: "Tài khoản", href: routes.account }, { label: "Đơn hàng của tôi" }]} />
      <h1 className="mt-2 text-title font-extrabold tracking-heading text-ink md:text-title-lg">Đơn hàng của tôi</h1>
      <div className="mt-6">
        <RequireUser next={page > 1 ? `${routes.myOrders}?trang=${page}` : routes.myOrders} skeleton={<OrdersSkeleton />}>
          <OrdersContent key={page} page={page} />
        </RequireUser>
      </div>
    </div>
  );
}

