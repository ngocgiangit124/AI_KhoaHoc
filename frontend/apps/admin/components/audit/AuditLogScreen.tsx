"use client";

import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  DataTable,
  EmptyState,
  IconChevronLeft,
  IconChevronRight,
  IconRotateCcw,
  IconSearch,
  Select,
  TextInput,
  cx,
  type Column,
} from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { listAuditLogs } from "@/lib/audit/api";
import { auditLoadError, isForbidden } from "@/lib/audit/errors";
import { KNOWN_ACTIONS, SUBJECT_TYPES, actionLabel, actorView, formatAuditTime, subjectView } from "@/lib/audit/labels";
import {
  ACTION_MAX,
  AUDIT_PATH,
  PER_PAGE_OPTIONS,
  addDays,
  auditQueryToSearch,
  effectiveRange,
  hasAuditFilter,
  parseAuditQuery,
  rangeError,
  todayVn,
  type AuditQuery,
  type PerPage,
} from "@/lib/audit/query";
import type { AuditLog, AuditPage } from "@/lib/audit/schemas";
import { useSession } from "@/lib/auth/SessionProvider";
import { listStaff } from "@/lib/staff/api";
import { canManageStaff } from "@/lib/staff/permissions";
import type { StaffAccount } from "@/lib/staff/types";
import { AuditDetailDialog } from "./AuditDetailDialog";

type LoadResult = { key: string; page: AuditPage | null; error: unknown };

const STAFF_PAGES_MAX = 4;
const sel = "max-sm:h-11";

/** Danh sách staff để chọn "Người làm" (tối đa 4 x 50 tài khoản). Lỗi → null và ô chuyển sang nhập mã số. */
function useStaffOptions(enabled: boolean): StaffAccount[] | null {
  const [list, setList] = useState<StaffAccount[] | null>(null);
  useEffect(() => {
    if (!enabled) return;
    const controller = new AbortController();
    (async () => {
      const all: StaffAccount[] = [];
      for (let page = 1; page <= STAFF_PAGES_MAX; page++) {
        const res = await listStaff({ q: "", role: "", status: "", page, perPage: 50 }, controller.signal);
        all.push(...res.data);
        if (page >= res.meta.last_page) break;
      }
      if (!controller.signal.aborted) setList(all);
    })().catch(() => {
      /* Không có danh sách: ô lọc dùng nhập mã số. */
    });
    return () => controller.abort();
  }, [enabled]);
  return list;
}

function FilterForm({ query, today, staff, onApply }: { query: AuditQuery; today: string; staff: StaffAccount[] | null; onApply: (patch: Partial<AuditQuery>) => void }) {
  const range = effectiveRange(query, today);
  const [from, setFrom] = useState(range.from);
  const [to, setTo] = useState(range.to);
  const [action, setAction] = useState(query.action);
  const [actorId, setActorId] = useState(query.actorId);
  const [subjectType, setSubjectType] = useState(query.subjectType);
  const [subjectId, setSubjectId] = useState(query.subjectId);
  const [error, setError] = useState<string | null>(null);

  const known = (KNOWN_ACTIONS as readonly string[]).includes(action) ? action : "";
  const knownType = SUBJECT_TYPES.some((t) => t.value === subjectType) ? subjectType : "";
  const staffHasActor = actorId === "" || (staff ?? []).some((s) => String(s.id) === actorId);

  function submit(e: FormEvent) {
    e.preventDefault();
    if (!from || !to) {
      setError("Hãy chọn đủ Từ ngày và Đến ngày.");
      return;
    }
    const err = rangeError({ from, to });
    if (err) {
      setError(err);
      return;
    }
    setError(null);
    const nowVn = todayVn();
    const isDefaultRange = from === addDays(nowVn, -6) && to === nowVn;
    onApply({
      from: isDefaultRange ? null : from,
      to: isDefaultRange ? null : to,
      action: action.trim(),
      actorId: /^[1-9]\d{0,17}$/.test(actorId.trim()) ? actorId.trim() : "",
      subjectType: subjectType.trim(),
      subjectId: /^[1-9]\d{0,17}$/.test(subjectId.trim()) ? subjectId.trim() : "",
      page: 1,
    });
  }

  const label = "text-sm font-semibold text-ink";
  return (
    <form role="search" aria-label="Lọc nhật ký" onSubmit={submit} noValidate className="grid gap-3 rounded-card border border-line bg-surface p-3 sm:grid-cols-2 xl:grid-cols-4">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="f-from" className={label}>
          Từ ngày
        </label>
        <TextInput id="f-from" name="from" type="date" size="sm" className={sel} value={from} onChange={(e) => setFrom(e.target.value)} aria-invalid={error ? true : undefined} aria-describedby={error ? "f-range-error" : undefined} />
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="f-to" className={label}>
          Đến ngày
        </label>
        <TextInput id="f-to" name="to" type="date" size="sm" className={sel} value={to} onChange={(e) => setTo(e.target.value)} aria-invalid={error ? true : undefined} aria-describedby={error ? "f-range-error" : undefined} />
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="f-action" className={label}>
          Hành động
        </label>
        <Select id="f-action" name="action_select" size="sm" className={sel} value={known} onChange={(e) => setAction(e.target.value)}>
          <option value="">{action && !known ? "Mã nhập tay (bên dưới)" : "Mọi hành động"}</option>
          {KNOWN_ACTIONS.map((a) => (
            <option key={a} value={a}>
              {actionLabel(a)} ({a})
            </option>
          ))}
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="f-action-code" className={label}>
          Hoặc nhập mã hành động
        </label>
        <TextInput id="f-action-code" name="action" size="sm" className={sel} value={action} maxLength={ACTION_MAX} autoComplete="off" placeholder="ví dụ order.refund" onChange={(e) => setAction(e.target.value)} />
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="f-actor" className={label}>
          Người làm
        </label>
        {staff ? (
          <Select id="f-actor" name="actor_id" size="sm" className={sel} value={actorId} onChange={(e) => setActorId(e.target.value)}>
            <option value="">Mọi người</option>
            {!staffHasActor ? <option value={actorId}>Tài khoản #{actorId}</option> : null}
            {staff.map((s) => (
              <option key={s.id} value={String(s.id)}>
                {s.name} (#{s.id})
              </option>
            ))}
          </Select>
        ) : (
          <TextInput id="f-actor" name="actor_id" size="sm" className={sel} inputMode="numeric" value={actorId} placeholder="Mã số tài khoản" onChange={(e) => setActorId(e.target.value.replace(/\D/g, ""))} />
        )}
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="f-subject-type" className={label}>
          Loại đối tượng
        </label>
        <Select id="f-subject-type" name="subject_type" size="sm" className={sel} value={knownType} onChange={(e) => setSubjectType(e.target.value)}>
          <option value="">{subjectType && !knownType ? subjectType : "Mọi loại"}</option>
          {SUBJECT_TYPES.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </Select>
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="f-subject-id" className={label}>
          Mã số đối tượng
        </label>
        <TextInput id="f-subject-id" name="subject_id" size="sm" className={sel} inputMode="numeric" value={subjectId} placeholder="ví dụ 42" onChange={(e) => setSubjectId(e.target.value.replace(/\D/g, ""))} />
      </div>
      <div className="flex items-end gap-2">
        <button type="submit" className="focus-ring h-9 rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover max-sm:h-11">
          Lọc
        </button>
        <ButtonLink href={AUDIT_PATH} size="sm" variant="secondary" className={sel}>
          Đặt lại
        </ButtonLink>
      </div>
      {error ? (
        <p id="f-range-error" role="alert" className="text-sm font-semibold text-danger sm:col-span-2 xl:col-span-4">
          {error}
        </p>
      ) : null}
    </form>
  );
}

/**
 * Nhật ký thao tác (US-016, FA12). Chỉ Admin, CHỈ ĐỌC. Bộ lọc/trang nằm trên URL (`page` thay cho con trỏ: simplePaginate của Laravel
 * nhận `page`, `links.next` cho biết còn trang sau; không có tổng). Quyền thật do API kiểm (403 → trang không có quyền).
 */
export function AuditLogScreen() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { state } = useSession();

  const allowed = state.kind === "staff" && canManageStaff(state.user);
  const query = useMemo(() => parseAuditQuery(searchParams), [searchParams]);
  const today = todayVn(); // tính lại mỗi lần render: qua nửa đêm, "7 ngày gần nhất" tự dịch theo
  const search = auditQueryToSearch(query);
  const staff = useStaffOptions(allowed);

  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<LoadResult | null>(null);
  const [selected, setSelected] = useState<AuditLog | null>(null);
  // Phần tử đã mở hộp chi tiết: đóng hộp thì trả focus về đó (WCAG 2.4.3), vì hộp bị gỡ khỏi DOM.
  const triggerRef = useRef<HTMLElement | null>(null);
  useEffect(() => {
    if (selected === null && triggerRef.current) {
      const el = triggerRef.current;
      triggerRef.current = null;
      if (el.isConnected) el.focus();
    }
  }, [selected]);

  const requestKey = `${search}#${reloadKey}`;
  const loading = result === null || result.key !== requestKey;
  const page = result?.page ?? null;
  const loadError = !loading && result ? result.error : null;
  const range = effectiveRange(query, today);
  const rangeProblem = rangeError(range);

  useEffect(() => {
    if (!allowed || rangeProblem) return;
    const controller = new AbortController();
    listAuditLogs(parseAuditQuery(new URLSearchParams(search)), today, controller.signal)
      .then((data) => setResult({ key: requestKey, page: data, error: null }))
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;
        setResult({ key: requestKey, page: null, error });
      });
    return () => controller.abort();
  }, [allowed, search, today, requestKey, rangeProblem]);

  const go = useCallback((next: AuditQuery) => router.replace(`${pathname}${auditQueryToSearch(next)}`, { scroll: false }), [router, pathname]);
  const apply = useCallback((patch: Partial<AuditQuery>) => go({ ...query, ...patch }), [go, query]);
  const reload = useCallback(() => setReloadKey((n) => n + 1), []);

  if (state.kind !== "staff") return null;
  if (!allowed || (loadError && isForbidden(loadError))) return <ForbiddenView />;

  const rows = page?.data ?? [];
  const hasNext = page?.hasNext ?? false;
  const filtered = hasAuditFilter(query);
  const pageHref = (p: number) => `${pathname}${auditQueryToSearch({ ...query, page: p })}`;

  const columns: Array<Column<AuditLog>> = [
    {
      key: "time",
      header: "Thời điểm",
      cell: (l) => {
        const [date, time] = formatAuditTime(l.created_at).split(" ");
        return (
          <span className="num block whitespace-nowrap">
            <span className="block">{date}</span>
            <span className="block text-ink-soft">{time}</span>
          </span>
        );
      },
    },
    {
      key: "actor",
      header: "Người làm",
      hideBelow: "md",
      cell: (l) => {
        const a = actorView(l);
        return (
          <div className="min-w-0">
            <p className="break-words font-semibold">{a.name}</p>
            {a.role ? <p className="text-xs text-ink-soft">{a.role}</p> : null}
          </div>
        );
      },
    },
    {
      key: "action",
      header: "Hành động",
      cell: (l) => {
        const a = actorView(l);
        const s = subjectView(l);
        return (
          <div className="min-w-0">
            <p className="break-words font-semibold">{actionLabel(l.action) ?? l.action}</p>
            {actionLabel(l.action) ? <p className="break-all font-mono text-xs text-ink-soft">{l.action}</p> : null}
            {/* Dưới 768px: người làm và đối tượng đi vào dòng phụ để bảng không tràn khung. */}
            <p className="mt-1 break-words text-xs text-ink-soft md:hidden">
              {a.name}
              {a.role ? ` (${a.role})` : ""}
              {s.text !== "—" ? ` · ${s.text}` : ""}
            </p>
          </div>
        );
      },
    },
    {
      key: "subject",
      header: "Đối tượng",
      hideBelow: "md",
      cell: (l) => {
        const s = subjectView(l);
        return s.href ? (
          <Link href={s.href} className="focus-ring rounded font-semibold text-primary underline underline-offset-2">
            {s.text}
          </Link>
        ) : (
          <span>{s.text}</span>
        );
      },
    },
    { key: "ip", header: "IP", hideBelow: "lg", cell: (l) => <span className="font-mono text-xs">{l.ip ?? "—"}</span> },
    {
      key: "detail",
      header: <span className="sr-only">Chi tiết</span>,
      align: "right",
      cell: (l) => (
        <Button size="sm" variant="ghost" className={sel} aria-label={`Xem chi tiết: ${actionLabel(l.action) ?? l.action}, ${formatAuditTime(l.created_at)}`} onClick={(e) => {
            triggerRef.current = e.currentTarget;
            setSelected(l);
          }}>
          Chi tiết
        </Button>
      ),
    },
  ];

  const pager = "focus-ring inline-flex h-11 items-center gap-1 rounded-control px-3 text-sm font-semibold";
  return (
    <div className="flex flex-col gap-4">
      <div>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-title font-extrabold tracking-heading text-ink">Nhật ký thao tác</h1>
          <Badge size="sm">Chỉ đọc</Badge>
        </div>
        <p className="mt-1 max-w-3xl text-sm text-ink-soft">Ghi lại thao tác quản trị và đăng nhập. Không sửa hay xoá được.</p>
      </div>

      <FilterForm key={search} query={query} today={today} staff={staff} onApply={apply} />

      {rangeProblem ? (
        <Alert tone="warning" title="Khoảng ngày không hợp lệ">
          {rangeProblem}
        </Alert>
      ) : loadError ? (
        <Alert
          tone="danger"
          title="Không tải được nhật ký"
          action={
            <Button size="sm" variant="secondary" className={sel} leadingIcon={<IconRotateCcw size={16} />} onClick={reload}>
              Thử lại
            </Button>
          }
        >
          {auditLoadError(loadError)}
        </Alert>
      ) : (
        <div aria-busy={loading} className="flex flex-col gap-3">
          <p className="num text-sm text-ink-soft" aria-live="polite">
            {loading ? "Đang tải…" : `Từ ${range.from.split("-").reverse().join("/")} đến ${range.to.split("-").reverse().join("/")} · Trang ${query.page} · ${rows.length} dòng`}
          </p>
          <DataTable
            caption="Nhật ký thao tác"
            columns={columns}
            rows={rows}
            rowKey={(l) => l.id}
            density="compact"
            loadingRows={loading ? 6 : undefined}
            empty={
              <EmptyState
                size="inline"
                icon={<IconSearch size={24} />}
                title={query.page > 1 ? "Không còn dòng nào ở trang này" : filtered ? "Không có nhật ký khớp bộ lọc" : "Chưa có nhật ký trong 7 ngày gần nhất"}
                headingLevel="h2"
                action={
                  query.page > 1 ? (
                    <ButtonLink href={pageHref(1)} size="sm" variant="secondary" className={sel}>
                      Về trang đầu
                    </ButtonLink>
                  ) : filtered ? (
                    <ButtonLink href={AUDIT_PATH} size="sm" variant="secondary" className={sel}>
                      Xoá bộ lọc
                    </ButtonLink>
                  ) : undefined
                }
              />
            }
          />
          <div className="flex flex-wrap items-center justify-between gap-3">
            <nav aria-label="Phân trang nhật ký" className="flex items-center gap-2">
              {query.page > 1 ? (
                <Link href={pageHref(query.page - 1)} className={cx(pager, "text-ink hover:bg-primary-soft hover:text-primary")}>
                  <IconChevronLeft size={16} />
                  Trang trước
                </Link>
              ) : (
                <span aria-disabled="true" className={cx(pager, "text-ink-soft")}>
                  <IconChevronLeft size={16} />
                  Trang trước
                </span>
              )}
              <span className="num text-sm text-ink-soft">Trang {query.page}</span>
              {hasNext && !loading ? (
                <Link href={pageHref(query.page + 1)} className={cx(pager, "text-ink hover:bg-primary-soft hover:text-primary")}>
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
            <div className="flex items-center gap-2 text-sm text-ink-soft">
              <label htmlFor="f-per-page">Số dòng/trang</label>
              <div className="w-24">
                <Select id="f-per-page" size="sm" className={sel} name="per_page" value={query.perPage} onChange={(e) => apply({ perPage: Number(e.target.value) as PerPage, page: 1 })}>
                  {PER_PAGE_OPTIONS.map((n) => (
                    <option key={n} value={n}>
                      {n}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
          </div>
        </div>
      )}

      {selected ? <AuditDetailDialog log={selected} onClose={() => setSelected(null)} /> : null}
    </div>
  );
}
