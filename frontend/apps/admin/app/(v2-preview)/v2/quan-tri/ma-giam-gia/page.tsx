import Link from "next/link";
import {
  Badge,
  ButtonLink,
  DataTable,
  EmptyState,
  IconPencil,
  IconPlus,
  IconSearch,
  IconTicket,
  LinkTabs,
  ProgressBar,
  TextInput,
  formatDate,
  formatPrice,
  type BadgeTone,
  type Column,
} from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { ForbiddenView } from "@/components/v2/ForbiddenView";
import { COUPONS, COUPON_STATE_LABEL, type Coupon, type CouponState } from "@/lib/mock/v2/ops";

export const dynamic = "force-dynamic";

const TONE: Record<CouponState, BadgeTone> = { active: "success", upcoming: "info", expired: "neutral", exhausted: "warning", inactive: "neutral" };

/** Mã giảm giá (US-013, GET /admin/coupons). Chỉ Admin/QLT. Trạng thái suy ra (`state`) hiện bằng chữ. */
export default async function CouponsPreview({ searchParams }: PageProps<"/v2/quan-tri/ma-giam-gia">) {
  const sp = await searchParams;
  const role = roleFrom(sp["vai-tro"]);
  const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
  const stateFilter = one(sp.state) as CouponState | undefined;
  const q = (one(sp.q) ?? "").trim().toUpperCase();
  const roleQ = role === "admin" ? "" : `vai-tro=${role}`;
  const base = "/v2/quan-tri/ma-giam-gia";
  const href = (st?: string) => {
    const p = [roleQ, st ? `state=${st}` : "", q ? `q=${encodeURIComponent(q)}` : ""].filter(Boolean).join("&");
    return p ? `${base}?${p}` : base;
  };
  const rows = COUPONS.filter((c) => (!stateFilter || c.state === stateFilter) && (!q || c.code.includes(q) || (c.name ?? "").toUpperCase().includes(q)));

  const columns: Array<Column<Coupon>> = [
    {
      key: "code",
      header: "Mã",
      cell: (c) => (
        <div>
          <Link href={`${base}/${c.id}${roleQ ? `?${roleQ}` : ""}`} className="focus-ring rounded font-mono font-semibold text-ink hover:text-primary">
            {c.code}
          </Link>
          {c.name ? <p className="text-xs text-ink-soft">{c.name}</p> : null}
        </div>
      ),
    },
    { key: "value", header: "Giảm", align: "right", cell: (c) => (c.discount_type === "percent" ? `${c.discount_value}%` : formatPrice(c.discount_value)) },
    {
      key: "scope",
      header: "Phạm vi",
      hideBelow: "lg",
      cell: (c) => (c.is_restricted ? `${c.courses_count ? `${c.courses_count} khóa` : ""}${c.courses_count && c.subjects_count ? ", " : ""}${c.subjects_count ? `${c.subjects_count} chuyên đề` : ""}` : "Toàn bộ khóa học"),
    },
    { key: "time", header: "Hiệu lực", hideBelow: "md", cell: (c) => <span className="num whitespace-nowrap">{`${formatDate(c.valid_from)} – ${c.valid_until ? formatDate(c.valid_until) : "không hạn"}`}</span> },
    {
      key: "uses",
      header: "Lượt dùng",
      cell: (c) =>
        c.max_uses ? (
          <ProgressBar className="w-28" size="sm" value={(c.used_count / c.max_uses) * 100} label={`Lượt dùng mã ${c.code}`} valueText={`${c.used_count}/${c.max_uses}`} hideLabel />
        ) : (
          <span className="num">{c.used_count} / không giới hạn</span>
        ),
    },
    {
      key: "state",
      header: "Trạng thái",
      cell: (c) => (
        <Badge size="sm" dot tone={TONE[c.state]}>
          {COUPON_STATE_LABEL[c.state]}
        </Badge>
      ),
    },
    {
      key: "act",
      header: <span className="sr-only">Thao tác</span>,
      align: "right",
      cell: (c) => (
        <ButtonLink href={`${base}/${c.id}${roleQ ? `?${roleQ}` : ""}`} variant="ghost" size="sm" leadingIcon={<IconPencil size={16} />} aria-label={`Sửa mã ${c.code}`}>
          Sửa
        </ButtonLink>
      ),
    },
  ];

  return (
    <AdminPreviewShell role={role} roles={["admin", "quan_ly_trang", "giao_vien"]} current="coupons" basePath={base}>
      {role === "giao_vien" ? (
        <ForbiddenView homeHref="/v2/quan-tri/khoa-hoc?vai-tro=giao_vien" />
      ) : (
        <>
          <div className="flex flex-wrap items-end justify-between gap-3">
            <h1 className="text-title font-extrabold tracking-heading text-ink">Mã giảm giá</h1>
            <ButtonLink href={`${base}/tao${roleQ ? `?${roleQ}` : ""}`} leadingIcon={<IconPlus size={18} />}>
              Tạo mã giảm giá
            </ButtonLink>
          </div>
          <form role="search" aria-label="Tìm mã giảm giá" className="mt-5 flex max-w-md gap-2">
            {roleQ ? <input type="hidden" name="vai-tro" value={role} /> : null}
            {stateFilter ? <input type="hidden" name="state" value={stateFilter} /> : null}
            <label htmlFor="coupon-q" className="sr-only">
              Tìm theo mã hoặc tên
            </label>
            <TextInput id="coupon-q" name="q" size="sm" defaultValue={q} placeholder="Tìm theo mã hoặc tên" leadingIcon={<IconSearch size={16} />} />
            <button type="submit" className="focus-ring h-9 rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover">
              Tìm
            </button>
          </form>
          <LinkTabs
            className="mt-4"
            label="Lọc theo trạng thái"
            items={[
              { href: href(), label: "Tất cả", current: !stateFilter },
              ...(["active", "upcoming", "expired", "exhausted", "inactive"] as CouponState[]).map((s) => ({ href: href(s), label: COUPON_STATE_LABEL[s], current: stateFilter === s })),
            ]}
          />
          <div className="mt-4">
            <DataTable
              caption="Danh sách mã giảm giá"
              columns={columns}
              rows={rows}
              rowKey={(c) => c.id}
              density="compact"
              empty={
                stateFilter || q ? (
                  <EmptyState size="inline" icon={<IconSearch size={24} />} title="Không có mã nào khớp bộ lọc" action={<ButtonLink href={href()} size="sm" variant="secondary">Xoá bộ lọc</ButtonLink>} headingLevel="h2" />
                ) : (
                  <EmptyState size="inline" icon={<IconTicket size={24} />} title="Chưa có mã giảm giá nào" headingLevel="h2" />
                )
              }
            />
          </div>
          <p className="mt-3 text-sm text-ink-soft">Thanh toán trực tuyến đang tạm khoá (V2): mã vẫn tạo được, lượt dùng chỉ tăng khi đơn được thanh toán.</p>
        </>
      )}
    </AdminPreviewShell>
  );
}
