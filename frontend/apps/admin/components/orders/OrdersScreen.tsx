"use client";

import { useEffect, useMemo, useState, type FormEvent } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import {
  Alert,
  Button,
  ButtonLink,
  Checkbox,
  DataTable,
  EmptyState,
  IconChevronLeft,
  IconChevronRight,
  IconInbox,
  IconRotateCcw,
  IconSearch,
  LinkTabs,
  Select,
  TextInput,
  cx,
  formatCount,
  formatPrice,
  type Column,
} from "@vitaminvui/ui/v2";
import { ApiError } from "@vitaminvui/api-client";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { useSession } from "@/lib/auth/SessionProvider";
import { listOrders } from "@/lib/orders/api";
import { listErrorMessage } from "@/lib/orders/errors";
import { formatWhen, methodLabel } from "@/lib/orders/format";
import { canViewOrders } from "@/lib/orders/permissions";
import { usePendingOrders } from "@/lib/orders/PendingOrders";
import {
  MAX_RANGE_DAYS,
  ORDERS_PATH,
  ORDER_TABS,
  ORDER_TAB_LABELS,
  effectiveRange,
  orderQueryToApi,
  orderQueryToSearch,
  parseOrderQuery,
  rangeError,
  todayVn,
  type MethodFilter,
  type OrderQuery,
  type OrderTab,
} from "@/lib/orders/query";
import { ORDER_STATUSES, type OrderListItem, type OrderPage } from "@/lib/orders/schemas";
import { AdminStatusBadge, DeadlineCell } from "./OrderBadges";

type LoadResult = { key: string; page: OrderPage | null; error: unknown; at: number };

const STATUS_FILTER_LABELS: Record<(typeof ORDER_STATUSES)[number], string> = {
  pending: "Chờ duyệt",
  paid: "Đã thanh toán",
  failed: "Thất bại",
  cancelled: "Đã huỷ",
  refunded: "Đã hoàn tiền",
};

/**
 * Đơn hàng quản trị (US-010 §2.1 + US-022 AC15, FA8). Mọi bộ lọc nằm trên URL (chia sẻ link, F5 không mất).
 * Tab "Chờ duyệt" (mặc định): đơn `manual` `pending`, CŨ NHẤT trước, không cần khoảng ngày. Tab khác: khoảng ngày
 * ≤ 366, mới nhất trước, phân trang cursor Trước/Sau. Email/SĐT ở danh sách đã che bởi server. Giáo viên: 403.
 */
export function OrdersScreen() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { state } = useSession();
  const { count: pendingCount } = usePendingOrders();

  const allowed = state.kind === "staff" && canViewOrders(state.user);
  const query = useMemo(() => parseOrderQuery(searchParams), [searchParams]);
  // Đồng hồ chụp lúc tải/tải lại/đổi bộ lọc (không chạy từng giây): "còn N giờ" và ngày mặc định được tính lại mỗi lần lấy dữ liệu mới.
  const [clock, setClock] = useState(() => ({ today: todayVn(), now: Date.now() }));
  const { today } = clock;
  const tick = () => setClock({ today: todayVn(), now: Date.now() });
  const range = effectiveRange(query, today);
  const rangeProblem = query.tab === "cho-duyet" ? null : rangeError(range);
  const apiQs = rangeProblem ? null : orderQueryToApi(query, today).toString();

  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<LoadResult | null>(null);
  const requestKey = `${apiQs}#${reloadKey}`;
  const loading = apiQs !== null && (result === null || result.key !== requestKey);
  const page = result?.key === requestKey ? result.page : null;
  const loadError = !loading && result?.key === requestKey ? result.error : null;

  useEffect(() => {
    if (!allowed || apiQs === null) return;
    const controller = new AbortController();
    listOrders(query, today, controller.signal)
      .then((data) => setResult({ key: requestKey, page: data, error: null, at: Date.now() }))
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;
        setResult({ key: requestKey, page: null, error, at: Date.now() });
      });
    return () => controller.abort();
    // `query` đã được mã hoá trong `apiQs`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [apiQs, requestKey, allowed, today]);

  const navigate = (next: OrderQuery) => {
    tick();
    router.replace(`${pathname}${orderQueryToSearch(next)}`, { scroll: false });
  };
  const hrefFor = (next: OrderQuery) => `${pathname}${orderQueryToSearch(next)}`;

  if (state.kind !== "staff") return null;
  if (!allowed || (loadError instanceof ApiError && loadError.status === 403)) return <ForbiddenView />;

  const now = result?.at ?? clock.now; // mốc của lần lấy dữ liệu gần nhất
  const rows = page?.data ?? [];
  const isPendingTab = query.tab === "cho-duyet";
  const hasFilter = query.q !== "" || (!isPendingTab && (query.method !== "" || query.review || query.status !== ""));
  const reset: OrderQuery = { ...query, q: "", from: null, to: null, method: "", status: "", review: false, cursor: "" };
  const detailHref = (code: string) => `${ORDERS_PATH}/${encodeURIComponent(code)}`;
  const tabHref = (tab: OrderTab) => hrefFor({ ...reset, tab, perPage: query.perPage });

  const columns: Array<Column<OrderListItem>> = [
    {
      key: "code",
      header: "Mã đơn",
      cell: (o) => (
        <div className="flex flex-col gap-1">
          <Link href={detailHref(o.code)} className="focus-ring num break-all rounded font-semibold text-primary underline-offset-4 hover:underline">
            {o.code}
          </Link>
          {/* Màn hẹp: cột phụ bị ẩn → hạn chờ (tab Chờ duyệt) hoặc trạng thái hiện ngay dưới mã. */}
          {isPendingTab && o.expires_at ? (
            <DeadlineCell expiresAt={o.expires_at} expiringSoon={o.expiring_soon} now={now} className="md:hidden" />
          ) : (
            <span className="lg:hidden">
              <AdminStatusBadge order={o} />
            </span>
          )}
        </div>
      ),
    },
    {
      key: "student",
      header: "Học sinh",
      cell: (o) => (
        <div className="min-w-40">
          <p className="break-words font-semibold">{o.student.is_deleted ? "Tài khoản đã xoá" : o.student.name}</p>
          <p className="break-all text-xs text-ink-soft">{[o.student.email_masked, o.student.phone_masked].filter(Boolean).join(" · ") || "—"}</p>
        </div>
      ),
    },
    {
      key: "items",
      header: "Khóa học",
      hideBelow: "xl",
      cell: (o) => (
        <div className="max-w-64">
          <p className="line-clamp-1">{o.first_item_title ?? "—"}</p>
          {o.items_count > 1 ? <p className="text-xs text-ink-soft">và {o.items_count - 1} khóa khác</p> : null}
        </div>
      ),
    },
    {
      key: "total",
      header: "Tổng tiền",
      align: "right",
      cell: (o) => (
        <div>
          <p className="num font-semibold">{formatPrice(o.total)}</p>
          {o.discount > 0 ? <p className="num text-xs text-ink-soft">giảm {formatPrice(o.discount)}</p> : null}
        </div>
      ),
    },
    { key: "created", header: "Đặt lúc", hideBelow: "md", cell: (o) => <span className="num whitespace-nowrap">{formatWhen(o.created_at)}</span> },
    isPendingTab
      ? { key: "deadline", header: "Hạn chờ", hideBelow: "md", cell: (o) => (o.expires_at ? <DeadlineCell expiresAt={o.expires_at} expiringSoon={o.expiring_soon} now={now} /> : "—") }
      : { key: "status", header: "Trạng thái", hideBelow: "lg", cell: (o) => <AdminStatusBadge order={o} /> },
    { key: "method", header: "Phương thức", hideBelow: "2xl", cell: (o) => methodLabel(o.payment_method) },
    {
      key: "act",
      header: <span className="sr-only">Thao tác</span>,
      align: "right",
      cell: (o) => (
        <ButtonLink
          href={detailHref(o.code)}
          size="sm"
          variant={isPendingTab ? "soft" : "secondary"}
          className="max-sm:h-11"
          aria-label={`${isPendingTab ? "Xử lý" : "Xem"} đơn ${o.code}`}
        >
          {isPendingTab ? "Xử lý" : "Xem"}
        </ButtonLink>
      ),
    },
  ];

  const emptyNode =
    isPendingTab && !hasFilter ? (
      <EmptyState size="inline" icon={<IconInbox size={24} />} title="Không có đơn nào đang chờ duyệt" description="Đơn mới sẽ hiện ở đây và hộp thư hỗ trợ nhận email báo." headingLevel="h2" />
    ) : (
      <EmptyState
        size="inline"
        icon={<IconSearch size={24} />}
        title="Không tìm thấy đơn phù hợp với bộ lọc"
        headingLevel="h2"
        action={
          <ButtonLink href={tabHref(query.tab)} size="sm" variant="secondary" className="max-sm:h-11">
            Xoá bộ lọc
          </ButtonLink>
        }
      />
    );

  const pager = "focus-ring inline-flex h-9 items-center gap-1 rounded-control px-3 text-sm font-semibold max-sm:h-11";
  const prev = page?.meta.prev_cursor ?? null;
  const next = page?.meta.next_cursor ?? null;
  const total = page?.meta.total ?? 0;

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-title font-extrabold tracking-heading text-ink">Đơn hàng</h1>
        <p className="mt-1 max-w-3xl text-sm text-ink-soft">
          Đơn “Liên hệ Quản trị viên” chờ bạn liên hệ học sinh, nhận tiền rồi duyệt. Đơn chờ quá hạn (72 giờ) tự huỷ.
        </p>
      </div>

      <LinkTabs
        label="Nhóm đơn hàng"
        items={ORDER_TABS.map((t) => ({
          href: tabHref(t),
          label: ORDER_TAB_LABELS[t],
          current: t === query.tab,
          ...(t === "cho-duyet" && pendingCount ? { count: pendingCount } : {}),
        }))}
      />

      <FilterForm key={orderQueryToSearch(query)} query={query} range={range} problem={rangeProblem} onApply={(next) => navigate(next)} />

      {loadError ? (
        <Alert
          tone="danger"
          title="Không tải được danh sách đơn"
          action={
            <Button size="sm" variant="secondary" className="max-sm:h-11" leadingIcon={<IconRotateCcw size={16} />} onClick={() => {
                tick();
                setReloadKey((n) => n + 1);
              }}>
              Tải lại
            </Button>
          }
        >
          {listErrorMessage(loadError)}
        </Alert>
      ) : rangeProblem ? null : (
        <div aria-busy={loading} className="flex flex-col gap-2">
          <DataTable
            caption={isPendingTab ? "Đơn chờ duyệt, cũ nhất trước" : "Danh sách đơn hàng, mới nhất trước"}
            columns={columns}
            rows={rows}
            rowKey={(o) => o.code}
            density="compact"
            loadingRows={loading ? 5 : undefined}
            empty={emptyNode}
          />
          {!loading && page ? (
            <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
              <p className="num text-sm text-ink-soft" aria-live="polite">
                {isPendingTab ? `${formatCount(total)} đơn đang chờ${query.q ? " khớp tìm kiếm" : ""}` : `Khoảng ${formatCount(total)} đơn khớp bộ lọc`}
              </p>
              <nav aria-label="Phân trang đơn hàng" className="flex gap-2">
                {prev ? (
                  <Link href={hrefFor({ ...query, cursor: prev })} className={cx(pager, "border border-line-strong text-ink hover:border-primary")}>
                    <IconChevronLeft size={16} />
                    Trang trước
                  </Link>
                ) : (
                  <span aria-disabled="true" className={cx(pager, "text-ink-soft")}>
                    <IconChevronLeft size={16} />
                    Trang trước
                  </span>
                )}
                {next ? (
                  <Link href={hrefFor({ ...query, cursor: next })} className={cx(pager, "border border-line-strong text-ink hover:border-primary")}>
                    Trang sau
                    <IconChevronRight size={16} />
                  </Link>
                ) : (
                  <span aria-disabled="true" className={cx(pager, "text-ink-soft")}>
                    Trang sau
                    <IconChevronRight size={16} />
                  </span>
                )}
              </nav>
            </div>
          ) : null}
        </div>
      )}
    </div>
  );
}

function FilterForm({
  query,
  range,
  problem,
  onApply,
}: {
  query: OrderQuery;
  range: { from: string; to: string };
  problem: string | null;
  onApply: (next: OrderQuery) => void;
}) {
  const [q, setQ] = useState(query.q);
  const [from, setFrom] = useState(range.from);
  const [to, setTo] = useState(range.to);
  const [method, setMethod] = useState<MethodFilter>(query.method);
  const [status, setStatus] = useState<OrderQuery["status"]>(query.status);
  const [review, setReview] = useState(query.review);
  const [localProblem, setLocalProblem] = useState<string | null>(null);
  const isPendingTab = query.tab === "cho-duyet";
  const btn = "focus-ring h-9 shrink-0 rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover max-sm:h-11";
  const labelCls = "text-xs font-semibold text-ink-soft";

  function submit(e: FormEvent) {
    e.preventDefault();
    if (isPendingTab) {
      onApply({ ...query, q: q.trim(), cursor: "" });
      return;
    }
    const err = rangeError({ from, to });
    setLocalProblem(err);
    if (err) return;
    onApply({ ...query, q: q.trim(), from, to, method, status: query.tab === "tat-ca" ? status : "", review, cursor: "" });
  }

  if (isPendingTab) {
    return (
      <form role="search" aria-label="Tìm đơn chờ duyệt" onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-card border border-line bg-surface p-3">
        <div className="flex min-w-56 flex-1 flex-col gap-1">
          <label htmlFor="f-q" className={labelCls}>
            Mã đơn hoặc tên học sinh
          </label>
          <TextInput id="f-q" name="q" size="sm" className="max-sm:h-11" value={q} maxLength={100} onChange={(e) => setQ(e.target.value)} placeholder="VV2610… hoặc Minh Anh" leadingIcon={<IconSearch size={16} />} autoComplete="off" />
        </div>
        <button type="submit" className={btn}>
          Tìm
        </button>
        <p className="w-full text-xs text-ink-soft">Cũ nhất trước. Tab này không cần chọn khoảng ngày.</p>
      </form>
    );
  }

  const shownProblem = localProblem ?? problem;
  return (
    <form
      role="search"
      aria-label="Lọc đơn hàng"
      onSubmit={submit}
      className="grid gap-3 rounded-card border border-line bg-surface p-3 sm:grid-cols-2 xl:grid-cols-[repeat(4,minmax(0,1fr))_auto]"
    >
      <div className="flex flex-col gap-1">
        <label htmlFor="f-from" className={labelCls}>
          Từ ngày <span aria-hidden="true" className="text-danger">*</span>
          <span className="sr-only"> (bắt buộc)</span>
        </label>
        <TextInput id="f-from" name="from" type="date" size="sm" className="max-sm:h-11" required value={from} onChange={(e) => setFrom(e.target.value)} />
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor="f-to" className={labelCls}>
          Đến ngày <span aria-hidden="true" className="text-danger">*</span>
          <span className="sr-only"> (bắt buộc)</span>
        </label>
        <TextInput id="f-to" name="to" type="date" size="sm" className="max-sm:h-11" required value={to} onChange={(e) => setTo(e.target.value)} />
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor="f-q2" className={labelCls}>
          Mã đơn, tên, email hoặc SĐT
        </label>
        <TextInput id="f-q2" name="q" size="sm" className="max-sm:h-11" value={q} maxLength={100} onChange={(e) => setQ(e.target.value)} autoComplete="off" />
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor="f-pt" className={labelCls}>
          Phương thức
        </label>
        <Select id="f-pt" name="method" size="sm" className="max-sm:h-11" value={method} onChange={(e) => setMethod(e.target.value as MethodFilter)}>
          <option value="">Mọi phương thức</option>
          <option value="manual">Liên hệ Quản trị viên</option>
          <option value="momo">MoMo</option>
          <option value="none">Miễn phí (0đ)</option>
        </Select>
      </div>
      <button type="submit" className={cx(btn, "self-end")}>
        Lọc
      </button>
      {query.tab === "tat-ca" ? (
        <div className="flex flex-col gap-1 sm:col-span-2 xl:col-span-2">
          <label htmlFor="f-st" className={labelCls}>
            Trạng thái
          </label>
          <Select id="f-st" name="status" size="sm" className="max-sm:h-11 sm:max-w-xs" value={status} onChange={(e) => setStatus(e.target.value as OrderQuery["status"])}>
            <option value="">Mọi trạng thái</option>
            {ORDER_STATUSES.map((s) => (
              <option key={s} value={s}>
                {STATUS_FILTER_LABELS[s]}
              </option>
            ))}
          </Select>
        </div>
      ) : null}
      <div className="flex flex-col gap-1 sm:col-span-2 xl:col-span-5">
        <Checkbox name="review" value="1" checked={review} onChange={(e) => setReview(e.target.checked)} label="Chỉ đơn “Cần xem lại”" className="text-sm" />
        <p className="text-xs text-ink-soft">
          Khoảng ngày tối đa {MAX_RANGE_DAYS} ngày. Tìm theo email/SĐT được ghi vào nhật ký và giới hạn 30 lần/phút.
        </p>
        {shownProblem ? (
          <p role="alert" className="text-sm font-medium text-danger">
            {shownProblem}
          </p>
        ) : null}
      </div>
    </form>
  );
}
