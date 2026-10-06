import Link from "next/link";
import {
  Badge,
  ButtonLink,
  DataTable,
  EmptyState,
  IconChevronLeft,
  IconChevronRight,
  IconSearch,
  Select,
  TextInput,
  cx,
  formatDateTime,
  type Column,
} from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { ForbiddenView } from "@/components/v2/ForbiddenView";
import { ROLE_LABEL } from "@/lib/mock/v2/data";
import { ACTION_LABEL, AUDIT_LOGS, STAFF_ACCOUNTS, type AuditLog } from "@/lib/mock/v2/ops";

export const dynamic = "force-dynamic";

const PER_PAGE = 6;

function subjectLabel(l: AuditLog) {
  if (!l.subject_type) return "—";
  const type = l.subject_type.split("\\").pop();
  const vi: Record<string, string> = { User: "Tài khoản", Course: "Khóa học", Coupon: "Mã giảm giá", Subject: "Chuyên đề", Enrollment: "Đăng ký" };
  return `${vi[type ?? ""] ?? type} #${l.subject_id}`;
}

/**
 * Nhật ký thao tác (US-016 §2.7, GET /admin/audit-logs, chỉ Admin, chỉ đọc).
 * Lọc qua URL (from/to/action/actor_id). simplePaginate: chỉ "Trang trước/Trang sau", không có tổng số.
 * Không có nút sửa/xoá nào.
 */
export default async function AuditLogPreview({ searchParams }: PageProps<"/v2/quan-tri/nhat-ky">) {
  const sp = await searchParams;
  const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
  const role = roleFrom(sp["vai-tro"]);
  const action = one(sp.action) ?? "";
  const actor = Number(one(sp.actor_id)) || 0;
  const from = one(sp.from) ?? "";
  const to = one(sp.to) ?? "";
  const page = Math.max(1, Number(one(sp.page)) || 1);

  const filtered = AUDIT_LOGS.filter(
    (l) => (!action || l.action === action) && (!actor || l.actor_id === actor) && (!from || l.created_at.slice(0, 10) >= from) && (!to || l.created_at.slice(0, 10) <= to),
  );
  const rows = filtered.slice((page - 1) * PER_PAGE, page * PER_PAGE);
  const hasNext = filtered.length > page * PER_PAGE;
  const base = "/v2/quan-tri/nhat-ky";
  const pageHref = (p: number) => {
    const q = new URLSearchParams();
    if (action) q.set("action", action);
    if (actor) q.set("actor_id", String(actor));
    if (from) q.set("from", from);
    if (to) q.set("to", to);
    if (p > 1) q.set("page", String(p));
    const s = q.toString();
    return s ? `${base}?${s}` : base;
  };

  const columns: Array<Column<AuditLog>> = [
    { key: "time", header: "Thời điểm", cell: (l) => <span className="num whitespace-nowrap">{formatDateTime(l.created_at)}</span> },
    {
      key: "actor",
      header: "Người thực hiện",
      cell: (l) =>
        l.actor_name ? (
          <div>
            <p className="font-semibold">{l.actor_name}</p>
            <p className="text-xs text-ink-soft">{l.actor_role === "cli" ? "Lệnh máy chủ" : ROLE_LABEL[l.actor_role]}</p>
          </div>
        ) : (
          <span className="text-ink-soft">Không xác định</span>
        ),
    },
    {
      key: "action",
      header: "Hành động",
      cell: (l) => (
        <div>
          <p>{ACTION_LABEL[l.action] ?? l.action}</p>
          <p className="font-mono text-xs text-ink-soft">{l.action}</p>
        </div>
      ),
    },
    { key: "subject", header: "Đối tượng", hideBelow: "md", cell: (l) => subjectLabel(l) },
    { key: "ip", header: "IP", hideBelow: "lg", cell: (l) => <span className="font-mono text-xs">{l.ip ?? "—"}</span> },
    {
      key: "changes",
      header: "Chi tiết",
      cell: (l) =>
        l.changes ? (
          <details className="max-w-64">
            <summary className="focus-ring cursor-pointer rounded text-sm font-semibold text-primary">Xem</summary>
            <pre className="mt-1 overflow-x-auto whitespace-pre-wrap rounded-control bg-sunken p-2 font-mono text-xs text-ink">{JSON.stringify(l.changes, null, 2)}</pre>
          </details>
        ) : (
          <span className="text-ink-soft">—</span>
        ),
    },
  ];

  const pager = "focus-ring inline-flex h-9 items-center gap-1 rounded-control px-3 text-sm font-semibold";
  return (
    <AdminPreviewShell role={role} current="audit" basePath={base}>
      {role !== "admin" ? (
        <ForbiddenView homeHref={`/v2/quan-tri/khoa-hoc?vai-tro=${role}`} />
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-title font-extrabold tracking-heading text-ink">Nhật ký thao tác</h1>
            <Badge size="sm">Chỉ đọc</Badge>
          </div>
          <p className="mt-1 max-w-3xl text-sm text-ink-soft">Ghi lại thao tác quản trị và đăng nhập. Không sửa hay xoá được.</p>
          <form role="search" aria-label="Lọc nhật ký" className="mt-5 grid gap-3 rounded-card border border-line bg-surface p-3 sm:grid-cols-2 xl:grid-cols-[repeat(4,minmax(0,1fr))_auto]">
            <div className="flex flex-col gap-1">
              <label htmlFor="f-from" className="text-xs font-semibold text-ink-soft">
                Từ ngày
              </label>
              <TextInput id="f-from" name="from" type="date" size="sm" defaultValue={from} />
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="f-to" className="text-xs font-semibold text-ink-soft">
                Đến ngày
              </label>
              <TextInput id="f-to" name="to" type="date" size="sm" defaultValue={to} />
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="f-action" className="text-xs font-semibold text-ink-soft">
                Hành động
              </label>
              <Select id="f-action" name="action" size="sm" defaultValue={action}>
                <option value="">Mọi hành động</option>
                {Object.entries(ACTION_LABEL).map(([k, v]) => (
                  <option key={k} value={k}>
                    {v}
                  </option>
                ))}
              </Select>
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="f-actor" className="text-xs font-semibold text-ink-soft">
                Người thực hiện
              </label>
              <Select id="f-actor" name="actor_id" size="sm" defaultValue={actor || ""}>
                <option value="">Mọi người</option>
                {STAFF_ACCOUNTS.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </Select>
            </div>
            <button type="submit" className="focus-ring h-9 self-end rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover">
              Lọc
            </button>
          </form>
          <div className="mt-4">
            <DataTable
              caption="Nhật ký thao tác"
              columns={columns}
              rows={rows}
              rowKey={(l) => l.id}
              density="compact"
              empty={<EmptyState size="inline" icon={<IconSearch size={24} />} title="Không tìm thấy nhật ký phù hợp với bộ lọc" action={<ButtonLink href={base} size="sm" variant="secondary">Xoá bộ lọc</ButtonLink>} headingLevel="h2" />}
            />
          </div>
          <nav aria-label="Phân trang nhật ký" className="mt-4 flex justify-between">
            {page > 1 ? (
              <Link href={pageHref(page - 1)} className={cx(pager, "text-ink hover:bg-primary-soft hover:text-primary")}>
                <IconChevronLeft size={16} />
                Trang trước
              </Link>
            ) : (
              <span aria-disabled="true" className={cx(pager, "text-ink-soft")}>
                <IconChevronLeft size={16} />
                Trang trước
              </span>
            )}
            {hasNext ? (
              <Link href={pageHref(page + 1)} className={cx(pager, "text-ink hover:bg-primary-soft hover:text-primary")}>
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
        </>
      )}
    </AdminPreviewShell>
  );
}
