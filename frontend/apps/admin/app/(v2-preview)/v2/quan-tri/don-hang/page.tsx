import Link from "next/link";
import {
  Alert,
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
  formatDateTime,
  formatPrice,
  type Column,
} from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { ForbiddenView } from "@/components/v2/ForbiddenView";
import { AdminStatusBadge, DeadlineCell, methodLabel } from "@/components/v2/orders/OrderBadges";
import { ORDERS, type AdminOrderListItem } from "@/lib/mock/v2/orders";

export const dynamic = "force-dynamic";

type Tab = "cho-duyet" | "da-thanh-toan" | "da-huy" | "hoan-tien" | "tat-ca";
const TABS: Array<{ key: Tab; label: string }> = [
  { key: "cho-duyet", label: "Chờ duyệt" },
  { key: "da-thanh-toan", label: "Đã thanh toán" },
  { key: "da-huy", label: "Đã huỷ" },
  { key: "hoan-tien", label: "Đã hoàn tiền" },
  { key: "tat-ca", label: "Tất cả" },
];

const STATES = [
  { label: "Có dữ liệu" },
  { key: "dang-tai", label: "Đang tải" },
  { key: "rong", label: "Không có đơn chờ" },
  { key: "loi", label: "Lỗi tải" },
];

const inTab = (o: AdminOrderListItem, tab: Tab) =>
  tab === "tat-ca" ||
  (tab === "cho-duyet" && o.status === "pending") ||
  (tab === "da-thanh-toan" && o.status === "paid") ||
  (tab === "da-huy" && (o.status === "cancelled" || o.status === "failed")) ||
  (tab === "hoan-tien" && o.status === "refunded");

/**
 * Đơn hàng (US-010 + US-022, FA8). Tab mặc định "Chờ duyệt": đơn `pending`, CŨ NHẤT trước, không cần khoảng ngày.
 * Tab khác: bắt buộc khoảng ngày ≤ 366 (T24), mới nhất trước, phân trang cursor "Trang trước/Trang sau".
 * Email/SĐT đã che (S14). Giáo viên → 403.
 * TODO(dev): GET /admin/orders?status=pending (tab chờ) / ?from&to&status[]&q&payment_method&needs_review&cursor;
 * số trên menu từ GET /admin/orders/pending-count (hoặc /admin/auth/me — Architect chọn); xuất file (FA9) để sau.
 */
export default async function OrdersPreview({ searchParams }: PageProps<"/v2/quan-tri/don-hang">) {
  const sp = await searchParams;
  const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
  const role = roleFrom(sp["vai-tro"]);
  const state = one(sp["trang-thai"]);
  const tab = (TABS.find((t) => t.key === one(sp.tab))?.key ?? "cho-duyet") as Tab;
  const q = (one(sp.q) ?? "").trim();
  const from = one(sp.from) ?? "2026-09-08";
  const to = one(sp.to) ?? "2026-10-08";
  const method = one(sp.pt) ?? "";
  const review = one(sp["xem-lai"]) === "1";
  const base = "/v2/quan-tri/don-hang";
  const roleQ = role === "admin" ? "" : `vai-tro=${role}`;
  const tabHref = (t: Tab) => {
    const p = [roleQ, t === "cho-duyet" ? "" : `tab=${t}`].filter(Boolean).join("&");
    return p ? `${base}?${p}` : base;
  };

  const all = state === "rong" ? ORDERS.filter((o) => o.status !== "pending") : ORDERS;
  const qLower = q.toLowerCase();
  const rows = all
    .filter((o) => inTab(o, tab))
    .filter((o) => !q || o.code.toLowerCase().includes(qLower) || o.student.name.toLowerCase().includes(qLower))
    .filter((o) => tab === "cho-duyet" || ((!method || o.payment_method === method) && (!review || o.needs_review) && o.created_at.slice(0, 10) >= from && o.created_at.slice(0, 10) <= to))
    .sort((a, b) => (tab === "cho-duyet" ? a.created_at.localeCompare(b.created_at) : b.created_at.localeCompare(a.created_at)));
  const pendingCount = all.filter((o) => o.status === "pending").length;

  const detailHref = (code: string) => `${base}/${code}${roleQ ? `?${roleQ}` : ""}`;
  const columns: Array<Column<AdminOrderListItem>> = [
    {
      key: "code",
      header: "Mã đơn",
      cell: (o) => (
        <div className="flex flex-col gap-1">
          <Link href={detailHref(o.code)} className="focus-ring num rounded font-semibold text-primary underline-offset-4 hover:underline">
            {o.code}
          </Link>
          {/* Màn hẹp: cột phụ bị ẩn → hiện ngay dưới mã: hạn chờ (tab Chờ duyệt) hoặc trạng thái. */}
          {tab === "cho-duyet" && o.expires_at ? (
            <DeadlineCell expiresAt={o.expires_at} className="md:hidden" />
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
          <p className="font-semibold">{o.student.name}</p>
          <p className="text-xs text-ink-soft">{[o.student.email_masked, o.student.phone_masked].filter(Boolean).join(" · ") || "—"}</p>
        </div>
      ),
    },
    {
      key: "items",
      header: "Khóa học",
      hideBelow: "xl",
      cell: (o) => (
        <div className="max-w-64">
          <p className="line-clamp-1">{o.first_item_title}</p>
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
          <p className="font-semibold">{formatPrice(o.total)}</p>
          {o.discount > 0 ? <p className="text-xs text-ink-soft">giảm {formatPrice(o.discount)}</p> : null}
        </div>
      ),
    },
    { key: "created", header: "Đặt lúc", hideBelow: "md", cell: (o) => <span className="num whitespace-nowrap">{formatDateTime(o.created_at)}</span> },
    tab === "cho-duyet"
      ? { key: "deadline", header: "Hạn chờ", hideBelow: "md", cell: (o) => (o.expires_at ? <DeadlineCell expiresAt={o.expires_at} /> : "—") }
      : { key: "status", header: "Trạng thái", hideBelow: "lg", cell: (o) => <AdminStatusBadge order={o} /> },
    { key: "method", header: "Phương thức", hideBelow: "2xl", cell: (o) => methodLabel(o.payment_method) },
    {
      key: "act",
      header: <span className="sr-only">Thao tác</span>,
      align: "right",
      cell: (o) => (
        <ButtonLink href={detailHref(o.code)} size="sm" variant={tab === "cho-duyet" ? "soft" : "secondary"} aria-label={`${tab === "cho-duyet" ? "Xử lý" : "Xem"} đơn ${o.code}`}>
          {tab === "cho-duyet" ? "Xử lý" : "Xem"}
        </ButtonLink>
      ),
    },
  ];

  let table: React.ReactNode;
  if (state === "loi") {
    table = (
      <Alert
        tone="danger"
        title="Không tải được danh sách đơn"
        action={
          <ButtonLink href={tabHref(tab)} size="sm" variant="secondary" leadingIcon={<IconRotateCcw size={16} />}>
            Tải lại
          </ButtonLink>
        }
      >
        Kiểm tra kết nối rồi tải lại trang.
      </Alert>
    );
  } else {
    table = (
      <DataTable
        caption={tab === "cho-duyet" ? "Đơn chờ duyệt, cũ nhất trước" : "Danh sách đơn hàng, mới nhất trước"}
        columns={columns}
        rows={state === "dang-tai" ? [] : rows}
        loadingRows={state === "dang-tai" ? 5 : undefined}
        rowKey={(o) => o.code}
        density="compact"
        empty={
          tab === "cho-duyet" && !q ? (
            <EmptyState size="inline" icon={<IconInbox size={24} />} title="Không có đơn nào đang chờ duyệt" description="Đơn mới sẽ hiện ở đây và hộp thư hỗ trợ nhận email báo." headingLevel="h2" />
          ) : (
            <EmptyState
              size="inline"
              icon={<IconSearch size={24} />}
              title="Không tìm thấy đơn phù hợp với bộ lọc"
              action={
                <ButtonLink href={tabHref(tab)} size="sm" variant="secondary">
                  Xoá bộ lọc
                </ButtonLink>
              }
              headingLevel="h2"
            />
          )
        }
      />
    );
  }

  const pager = "focus-ring inline-flex h-9 items-center gap-1 rounded-control px-3 text-sm font-semibold";
  return (
    <AdminPreviewShell role={role} current="orders" basePath={base} extraQuery={tab === "cho-duyet" ? "" : `tab=${tab}`} states={STATES} state={state}>
      {role === "giao_vien" ? (
        <ForbiddenView homeHref="/v2/quan-tri/khoa-hoc?vai-tro=giao_vien" />
      ) : (
        <>
          <h1 className="text-title font-extrabold tracking-heading text-ink">Đơn hàng</h1>
          <p className="mt-1 max-w-3xl text-sm text-ink-soft">
            Đơn “Liên hệ Quản trị viên” chờ bạn liên hệ học sinh, nhận tiền rồi duyệt. Đơn chờ quá 72 giờ tự huỷ.
          </p>

          <LinkTabs
            className="mt-5"
            label="Nhóm đơn hàng"
            items={TABS.map((t) => ({ href: tabHref(t.key), label: t.label, current: t.key === tab, count: t.key === "cho-duyet" ? pendingCount : undefined }))}
          />

          {tab === "cho-duyet" ? (
            <form role="search" aria-label="Tìm đơn chờ duyệt" className="mt-4 flex flex-wrap items-end gap-3 rounded-card border border-line bg-surface p-3">
              {roleQ ? <input type="hidden" name="vai-tro" value={role} /> : null}
              <div className="flex min-w-56 flex-1 flex-col gap-1">
                <label htmlFor="f-q" className="text-xs font-semibold text-ink-soft">
                  Mã đơn hoặc tên học sinh
                </label>
                <TextInput id="f-q" name="q" size="sm" defaultValue={q} placeholder="VV2610… hoặc Minh Anh" leadingIcon={<IconSearch size={16} />} />
              </div>
              <button type="submit" className="focus-ring h-9 rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover">
                Tìm
              </button>
              <p className="w-full text-xs text-ink-soft">Cũ nhất trước. Tab này không cần chọn khoảng ngày.</p>
            </form>
          ) : (
            <form role="search" aria-label="Lọc đơn hàng" className="mt-4 grid gap-3 rounded-card border border-line bg-surface p-3 sm:grid-cols-2 xl:grid-cols-[repeat(4,minmax(0,1fr))_auto]">
              {roleQ ? <input type="hidden" name="vai-tro" value={role} /> : null}
              <input type="hidden" name="tab" value={tab} />
              <div className="flex flex-col gap-1">
                <label htmlFor="f-from" className="text-xs font-semibold text-ink-soft">
                  Từ ngày <span aria-hidden="true" className="text-danger">*</span>
                  <span className="sr-only"> (bắt buộc)</span>
                </label>
                <TextInput id="f-from" name="from" type="date" size="sm" required defaultValue={from} />
              </div>
              <div className="flex flex-col gap-1">
                <label htmlFor="f-to" className="text-xs font-semibold text-ink-soft">
                  Đến ngày <span aria-hidden="true" className="text-danger">*</span>
                  <span className="sr-only"> (bắt buộc)</span>
                </label>
                <TextInput id="f-to" name="to" type="date" size="sm" required defaultValue={to} />
              </div>
              <div className="flex flex-col gap-1">
                <label htmlFor="f-q2" className="text-xs font-semibold text-ink-soft">
                  Mã đơn, tên, email hoặc SĐT
                </label>
                <TextInput id="f-q2" name="q" size="sm" defaultValue={q} />
              </div>
              <div className="flex flex-col gap-1">
                <label htmlFor="f-pt" className="text-xs font-semibold text-ink-soft">
                  Phương thức
                </label>
                <Select id="f-pt" name="pt" size="sm" defaultValue={method}>
                  <option value="">Mọi phương thức</option>
                  <option value="manual">Liên hệ Quản trị viên</option>
                  <option value="momo">MoMo</option>
                  <option value="none">Miễn phí (0đ)</option>
                </Select>
              </div>
              <button type="submit" className="focus-ring h-9 self-end rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover">
                Lọc
              </button>
              <div className="sm:col-span-2 xl:col-span-5">
                <Checkbox name="xem-lai" value="1" defaultChecked={review} label="Chỉ đơn “Cần xem lại”" className="text-sm" />
                <p className="text-xs text-ink-soft">Khoảng ngày tối đa 366 ngày.</p>
              </div>
            </form>
          )}

          <div className="mt-4">{table}</div>

          {tab !== "cho-duyet" && state !== "loi" ? (
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-ink-soft">Khoảng {rows.length} đơn khớp bộ lọc</p>
              <nav aria-label="Phân trang đơn hàng" className="flex gap-2">
                <span aria-disabled="true" className={cx(pager, "text-ink-soft")}>
                  <IconChevronLeft size={16} />
                  Trang trước
                </span>
                <span aria-disabled="true" className={cx(pager, "text-ink-soft")}>
                  Trang sau
                  <IconChevronRight size={16} />
                </span>
              </nav>
            </div>
          ) : null}
        </>
      )}
    </AdminPreviewShell>
  );
}
