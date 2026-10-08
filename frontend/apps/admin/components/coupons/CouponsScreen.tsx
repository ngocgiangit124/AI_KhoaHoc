"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import {
  Alert,
  Button,
  ButtonLink,
  DataTable,
  EmptyState,
  IconPencil,
  IconPlus,
  IconRotateCcw,
  IconSearch,
  IconTicket,
  LinkTabs,
  Pagination,
  ProgressBar,
  Select,
  TextInput,
  formatCount,
  formatDate,
  formatPrice,
  type Column,
} from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { useSession } from "@/lib/auth/SessionProvider";
import { listCoupons } from "@/lib/coupons/api";
import { couponActionError, isForbidden } from "@/lib/coupons/errors";
import { canManageCoupons } from "@/lib/coupons/permissions";
import { COUPONS_PATH, couponQueryToApi, couponQueryToSearch, hasCouponFilter, parseCouponQuery } from "@/lib/coupons/query";
import { COUPON_STATES, COUPON_STATE_LABELS, PER_PAGE_OPTIONS, type Coupon, type CouponPage, type CouponQuery } from "@/lib/coupons/types";
import { CouponStateBadge } from "./CouponStateBadge";

type LoadResult = { key: string; page: CouponPage | null; error: unknown };

const SEARCH_DEBOUNCE_MS = 300;

/** Mã giới hạn phạm vi nhưng không còn khóa/chuyên đề nào (vd chuyên đề bị xoá): backend coi là KHÔNG áp cho khóa nào. */
export const isEmptyScope = (c: Pick<Coupon, "is_restricted" | "courses_count" | "subjects_count">) =>
  c.is_restricted && c.courses_count === 0 && c.subjects_count === 0;

export function scopeText(c: Pick<Coupon, "is_restricted" | "courses_count" | "subjects_count">): string {
  if (!c.is_restricted) return "Toàn bộ khóa học";
  if (isEmptyScope(c)) return "Phạm vi trống";
  const parts: string[] = [];
  if (c.subjects_count) parts.push(`${formatCount(c.subjects_count)} chuyên đề`);
  if (c.courses_count) parts.push(`${formatCount(c.courses_count)} khóa`);
  return parts.join(", ") || "Theo phạm vi";
}

export const discountText = (c: Pick<Coupon, "discount_type" | "discount_value">) => (c.discount_type === "percent" ? `${c.discount_value}%` : formatPrice(c.discount_value));

/**
 * Danh sách mã giảm giá (US-013 §2.1, FA7), hình thức theo bản xem trước `/v2/quan-tri/ma-giam-gia`.
 * Bộ lọc `state`/`q`/`page` nằm trên URL. Bật/tắt và xoá làm ở trang sửa. Giáo viên: trang 403.
 */
export function CouponsScreen() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { state } = useSession();

  const allowed = state.kind === "staff" && canManageCoupons(state.user);
  const query = useMemo(() => parseCouponQuery(searchParams), [searchParams]);
  const apiQs = couponQueryToApi(query);

  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<LoadResult | null>(null);
  const [qInput, setQInput] = useState(query.q);
  const committedQ = useRef(query.q);

  const requestKey = `${apiQs}#${reloadKey}`;
  const loading = result === null || result.key !== requestKey;
  const page = result?.page ?? null;
  const loadError = !loading && result ? result.error : null;

  useEffect(() => {
    if (!allowed) return;
    const controller = new AbortController();
    listCoupons(parseCouponQuery(new URLSearchParams(apiQs)), controller.signal)
      .then((data) => setResult({ key: requestKey, page: data, error: null }))
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;
        setResult((prev) => ({ key: requestKey, page: prev?.page ?? null, error }));
      });
    return () => controller.abort();
  }, [apiQs, requestKey, allowed]);

  const navigate = useCallback((next: CouponQuery) => router.replace(`${pathname}${couponQueryToSearch(next)}`, { scroll: false }), [router, pathname]);

  // URL đổi từ ngoài (link sidebar, back/forward) → kéo ô nhập theo.
  useEffect(() => {
    if (query.q !== committedQ.current) {
      committedQ.current = query.q;
      setQInput(query.q);
    }
  }, [query.q]);

  useEffect(() => {
    const q = qInput.trim();
    if (q === query.q) return;
    if (query.q !== committedQ.current) return;
    const t = setTimeout(() => {
      committedQ.current = q;
      navigate({ ...query, q, page: 1 });
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput, query, navigate]);

  // Trang vượt quá số trang hiện có → lùi về trang cuối.
  useEffect(() => {
    if (!loading && result?.page && result.page.data.length === 0 && query.page > 1) {
      navigate({ ...query, page: Math.max(1, Math.min(query.page - 1, result.page.meta.last_page || 1)) });
    }
  }, [loading, result, query, navigate]);

  const reload = useCallback(() => setReloadKey((n) => n + 1), []);

  if (state.kind !== "staff") return null;
  if (!allowed || (loadError && isForbidden(loadError))) return <ForbiddenView />;

  const rows = page?.data ?? [];
  const hasFilter = hasCouponFilter(query);
  const showEmptyFresh = !loading && !loadError && rows.length === 0 && !hasFilter && query.page === 1;
  const total = page?.meta.total ?? 0;
  const lastPage = page?.meta.last_page ?? 1;
  const createHref = `${COUPONS_PATH}/tao`;
  const editHref = (id: number) => `${COUPONS_PATH}/${id}`;
  const sel = "max-sm:h-11";
  const tabHref = (st: CouponQuery["state"]) => `${pathname}${couponQueryToSearch({ ...query, state: st, page: 1 })}`;

  const usesCell = (c: Coupon) =>
    c.max_uses ? (
      <ProgressBar className="w-28" size="sm" value={(c.used_count / c.max_uses) * 100} label={`Lượt dùng mã ${c.code}`} valueText={`${formatCount(c.used_count)}/${formatCount(c.max_uses)}`} hideLabel />
    ) : (
      <span className="num whitespace-nowrap">{formatCount(c.used_count)} / không giới hạn</span>
    );

  const columns: Array<Column<Coupon>> = [
    {
      key: "code",
      header: "Mã",
      cell: (c) => (
        <div className="min-w-0">
          <Link href={editHref(c.id)} className="focus-ring break-all rounded font-mono font-semibold text-ink hover:text-primary">
            {c.code}
          </Link>
          {c.name ? <p className="truncate text-xs text-ink-soft">{c.name}</p> : null}
          {/* Dưới 768px: giảm, trạng thái, lượt dùng đi vào dòng phụ để bảng không tràn khung. */}
          <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-soft md:hidden">
            <span className="num font-semibold text-ink">{discountText(c)}</span>
            <CouponStateBadge state={c.state} />
            <span className="num">
              {formatCount(c.used_count)}
              {c.max_uses ? `/${formatCount(c.max_uses)}` : ""} lượt
            </span>
          </div>
        </div>
      ),
    },
    { key: "value", header: "Giảm", align: "right", hideBelow: "md", className: "whitespace-nowrap", cell: (c) => <span className="num">{discountText(c)}</span> },
    { key: "scope", header: "Phạm vi", hideBelow: "lg", cell: scopeCell },
    {
      key: "time",
      header: "Hiệu lực",
      hideBelow: "md",
      cell: (c) => <span className="num whitespace-nowrap">{`${formatDate(c.valid_from)} – ${c.valid_until ? formatDate(c.valid_until) : "không hạn"}`}</span>,
    },
    { key: "uses", header: "Lượt dùng", hideBelow: "md", cell: usesCell },
    { key: "state", header: "Trạng thái", hideBelow: "md", className: "whitespace-nowrap", cell: (c) => <CouponStateBadge state={c.state} /> },
    {
      key: "act",
      header: <span className="sr-only">Thao tác</span>,
      align: "right",
      cell: (c) => (
        <ButtonLink href={editHref(c.id)} variant="ghost" size="sm" className="max-sm:h-11" leadingIcon={<IconPencil size={16} />} aria-label={`Sửa mã ${c.code}`}>
          Sửa
        </ButtonLink>
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-title font-extrabold tracking-heading text-ink">Mã giảm giá</h1>
          <p className="num mt-1 text-sm text-ink-soft" aria-live="polite">
            {loading ? "Đang tải…" : `Tổng ${formatCount(total)} mã`}
          </p>
        </div>
        <ButtonLink href={createHref} className="max-sm:h-11" leadingIcon={<IconPlus size={18} />}>
          Tạo mã giảm giá
        </ButtonLink>
      </div>

      {showEmptyFresh ? (
        <EmptyState
          icon={<IconTicket size={32} />}
          title="Chưa có mã giảm giá nào"
          description="Tạo mã đầu tiên để chạy chương trình khuyến mãi."
          action={
            <ButtonLink href={createHref} leadingIcon={<IconPlus size={18} />}>
              Tạo mã đầu tiên
            </ButtonLink>
          }
        />
      ) : (
        <>
          <form
            role="search"
            aria-label="Tìm mã giảm giá"
            onSubmit={(e) => {
              e.preventDefault();
              const q = qInput.trim();
              committedQ.current = q;
              if (q !== query.q) navigate({ ...query, q, page: 1 });
            }}
            className="flex max-w-md gap-2"
          >
            <label className="sr-only" htmlFor="f-q">
              Tìm theo mã hoặc tên
            </label>
            <TextInput
              id="f-q"
              size="sm"
              className={sel}
              type="search"
              name="q"
              value={qInput}
              maxLength={100}
              placeholder="Tìm theo mã hoặc tên"
              autoComplete="off"
              leadingIcon={<IconSearch size={16} />}
              onChange={(e) => setQInput(e.target.value)}
            />
            <button type="submit" className="focus-ring h-9 shrink-0 rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover max-sm:h-11">
              Tìm
            </button>
          </form>

          <LinkTabs
            label="Lọc theo trạng thái"
            items={[
              { href: tabHref(""), label: "Tất cả", current: query.state === "" },
              ...COUPON_STATES.map((s) => ({ href: tabHref(s), label: COUPON_STATE_LABELS[s], current: query.state === s })),
            ]}
          />

          {loadError ? (
            <Alert
              tone="danger"
              title="Không tải được danh sách mã giảm giá"
              action={
                <Button size="sm" variant="secondary" className="max-sm:h-11" leadingIcon={<IconRotateCcw size={16} />} onClick={reload}>
                  Thử lại
                </Button>
              }
            >
              {couponActionError(loadError)}
            </Alert>
          ) : (
            <div aria-busy={loading} className="flex flex-col gap-2">
              <DataTable
                caption="Danh sách mã giảm giá"
                columns={columns}
                rows={rows}
                rowKey={(c) => c.id}
                density="compact"
                loadingRows={loading && rows.length === 0 ? 6 : undefined}
                empty={
                  hasFilter ? (
                    <EmptyState
                      size="inline"
                      icon={<IconSearch size={24} />}
                      title="Không có mã nào khớp bộ lọc"
                      headingLevel="h2"
                      action={
                        <ButtonLink href={COUPONS_PATH} size="sm" variant="secondary" className="max-sm:h-11">
                          Xoá bộ lọc
                        </ButtonLink>
                      }
                    />
                  ) : (
                    <EmptyState size="inline" icon={<IconTicket size={24} />} title="Chưa có mã giảm giá nào" headingLevel="h2" />
                  )
                }
              />
              <div className="flex flex-wrap items-center justify-end gap-2 text-sm text-ink-soft">
                <label htmlFor="f-per-page">Số dòng/trang</label>
                <div className="w-24">
                  <Select
                    id="f-per-page"
                    size="sm"
                    className={sel}
                    name="per_page"
                    value={query.perPage}
                    onChange={(e) => navigate({ ...query, perPage: Number(e.target.value) === 50 ? 50 : 25, page: 1 })}
                  >
                    {PER_PAGE_OPTIONS.map((n) => (
                      <option key={n} value={n}>
                        {n}
                      </option>
                    ))}
                  </Select>
                </div>
              </div>
              {lastPage > 1 ? (
                <Pagination
                  className="mt-2"
                  currentPage={page?.meta.current_page ?? query.page}
                  lastPage={lastPage}
                  hrefFor={(n) => `${pathname}${couponQueryToSearch({ ...query, page: n })}`}
                />
              ) : null}
            </div>
          )}
          <p className="text-sm text-ink-soft">Thanh toán trực tuyến đang tạm khoá (V2): mã vẫn tạo được, lượt dùng chỉ tăng khi đơn được thanh toán.</p>
        </>
      )}
    </div>
  );
}

function scopeCell(c: Coupon) {
  return scopeText(c);
}
