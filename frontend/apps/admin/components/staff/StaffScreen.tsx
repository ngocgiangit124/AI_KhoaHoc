"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  DataTable,
  EmptyState,
  IconPlus,
  IconRotateCcw,
  IconSearch,
  IconUsers,
  Pagination,
  Select,
  TextInput,
  formatCount,
  formatDateTime,
  useToast,
  type Column,
} from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { useSession } from "@/lib/auth/SessionProvider";
import { STAFF_ROLES, STAFF_ROLE_LABELS } from "@/lib/auth/types";
import { listStaff } from "@/lib/staff/api";
import { isForbidden, staffActionError } from "@/lib/staff/errors";
import { canManageStaff } from "@/lib/staff/permissions";
import { STAFF_PATH, hasStaffFilter, parseStaffQuery, staffQueryToApi, staffQueryToSearch } from "@/lib/staff/query";
import { PER_PAGE_OPTIONS, STAFF_STATUSES, STAFF_STATUS_LABELS, type StaffAccount, type StaffPage, type StaffQuery } from "@/lib/staff/types";
import { PasswordRevealDialog } from "./PasswordRevealDialog";
import { StaffActionDialog, type StaffAction, type StaffActionResult } from "./StaffActionDialog";
import { StaffCreateDialog } from "./StaffCreateDialog";
import { StaffRoleDialog } from "./StaffRoleDialog";

type LoadResult = { key: string; page: StaffPage | null; error: unknown };
type Dlg = { kind: "create" } | { kind: "action"; action: StaffAction; account: StaffAccount } | { kind: "role"; account: StaffAccount } | null;
type Secret = { name: string; password: string; kind: "create" | "reset" };
type Released = { name: string; ids: number[] };

const SEARCH_DEBOUNCE_MS = 300;

export const roleTone = (r: StaffAccount["role"]) => (r === "admin" ? "primary" : r === "quan_ly_trang" ? "info" : "neutral");

function StatusBadge({ account }: { account: StaffAccount }) {
  return (
    <Badge tone={account.status === "active" ? "success" : "danger"} dot size="sm">
      {STAFF_STATUS_LABELS[account.status]}
    </Badge>
  );
}

/**
 * Danh sách tài khoản staff (US-016 §2.4, FA10). Chỉ Admin. Bộ lọc/trang trên URL. Tạo, khóa/mở khóa, đặt lại mật khẩu, đổi vai trò
 * đều qua hộp thoại có xác nhận. Mật khẩu sinh chỉ nằm trong state `secret` tới khi đóng hộp. Quyền thật do API kiểm (403 → trang 403).
 */
export function StaffScreen() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const toast = useToast();
  const { state } = useSession();

  const allowed = state.kind === "staff" && canManageStaff(state.user);
  const query = useMemo(() => parseStaffQuery(searchParams), [searchParams]);
  const apiQs = staffQueryToApi(query);

  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<LoadResult | null>(null);
  const [qInput, setQInput] = useState(query.q);
  const committedQ = useRef(query.q);
  const [dialog, setDialog] = useState<Dlg>(null);
  const [secret, setSecret] = useState<Secret | null>(null);
  const [released, setReleased] = useState<Released | null>(null);

  const requestKey = `${apiQs}#${reloadKey}`;
  const loading = result === null || result.key !== requestKey;
  const page = result?.page ?? null;
  const loadError = !loading && result ? result.error : null;

  useEffect(() => {
    if (!allowed) return;
    const controller = new AbortController();
    listStaff(parseStaffQuery(new URLSearchParams(apiQs)), controller.signal)
      .then((data) => setResult({ key: requestKey, page: data, error: null }))
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;
        setResult((prev) => ({ key: requestKey, page: prev?.page ?? null, error }));
      });
    return () => controller.abort();
  }, [apiQs, requestKey, allowed]);

  // Bộ lọc mới nhất, cập nhật đồng bộ: đổi 2 ô liên tiếp trước khi URL kịp đổi thì thay đổi đầu không bị mất.
  const latest = useRef(query);
  useEffect(() => {
    latest.current = query;
  }, [query]);
  const update = useCallback(
    (patch: Partial<StaffQuery>) => {
      const next = { ...latest.current, ...patch };
      latest.current = next;
      router.replace(`${pathname}${staffQueryToSearch(next)}`, { scroll: false });
    },
    [router, pathname],
  );
  const reload = useCallback(() => setReloadKey((n) => n + 1), []);
  const closeDialog = useCallback(() => setDialog(null), []);

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
      update({ q, page: 1 });
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput, query, update]);

  useEffect(() => {
    if (!loading && result?.page && result.page.data.length === 0 && query.page > 1) {
      update({ page: Math.max(1, Math.min(query.page - 1, result.page.meta.last_page || 1)) });
    }
  }, [loading, result, query, update]);

  const onAction = useCallback(
    (r: StaffActionResult) => {
      setDialog(null);
      if (r.action === "reset") {
        const { initial_password, ...account } = r.account;
        setSecret({ name: account.name, password: initial_password, kind: "reset" });
        toast.show({ tone: "success", title: "Đã đặt lại mật khẩu" });
      } else {
        toast.show({ tone: "success", title: r.action === "lock" ? `Đã khóa tài khoản ${r.account.name}` : `Đã mở khóa tài khoản ${r.account.name}` });
      }
      reload();
    },
    [toast, reload],
  );

  if (state.kind !== "staff") return null;
  if (!allowed || (loadError && isForbidden(loadError))) return <ForbiddenView />;

  const rows = page?.data ?? [];
  const hasFilter = hasStaffFilter(query);
  const total = page?.meta.total ?? 0;
  const lastPage = page?.meta.last_page ?? 1;
  const sel = "max-sm:h-11";
  const btn = "max-sm:h-11";

  const actions = (a: StaffAccount) =>
    a.is_self ? (
      <p className="max-w-44 text-xs text-ink-soft">Tài khoản của bạn: không thể tự khóa, đặt lại mật khẩu hay đổi vai trò.</p>
    ) : (
      <div className="flex flex-col items-start gap-1 sm:flex-row sm:flex-wrap sm:items-center">
        <Button size="sm" variant="ghost" className={btn} aria-label={`Đổi vai trò của ${a.name}`} onClick={() => setDialog({ kind: "role", account: a })}>
          Đổi vai trò
        </Button>
        {a.status === "active" ? (
          <Button size="sm" variant="ghost" className={btn} aria-label={`Khóa tài khoản ${a.name}`} onClick={() => setDialog({ kind: "action", action: "lock", account: a })}>
            Khóa
          </Button>
        ) : (
          <Button size="sm" variant="ghost" className={btn} aria-label={`Mở khóa tài khoản ${a.name}`} onClick={() => setDialog({ kind: "action", action: "unlock", account: a })}>
            Mở khóa
          </Button>
        )}
        <Button size="sm" variant="ghost" className={btn} aria-label={`Đặt lại mật khẩu của ${a.name}`} onClick={() => setDialog({ kind: "action", action: "reset", account: a })}>
          Đặt lại mật khẩu
        </Button>
      </div>
    );

  const columns: Array<Column<StaffAccount>> = [
    {
      key: "name",
      header: "Họ tên",
      cell: (a) => (
        <div className="min-w-0">
          <p className="break-words font-semibold text-ink">
            {a.name}
            {a.is_self ? <span className="ml-2 text-xs font-normal text-ink-soft">(bạn)</span> : null}
          </p>
          <p className="break-all text-xs text-ink-soft">{a.email}</p>
          {/* Dưới 768px: vai trò + trạng thái đi vào dòng phụ để bảng không tràn khung. */}
          <div className="mt-1 flex flex-wrap items-center gap-1.5 md:hidden">
            <Badge tone={roleTone(a.role)} size="sm">
              {STAFF_ROLE_LABELS[a.role]}
            </Badge>
            <StatusBadge account={a} />
          </div>
        </div>
      ),
    },
    {
      key: "role",
      header: "Vai trò",
      hideBelow: "md",
      className: "whitespace-nowrap",
      cell: (a) => (
        <Badge tone={roleTone(a.role)} size="sm">
          {STAFF_ROLE_LABELS[a.role]}
        </Badge>
      ),
    },
    { key: "status", header: "Trạng thái", hideBelow: "md", className: "whitespace-nowrap", cell: (a) => <StatusBadge account={a} /> },
    {
      key: "login",
      header: "Đăng nhập gần nhất",
      hideBelow: "lg",
      cell: (a) => <span className="num whitespace-nowrap text-ink-soft">{a.last_login_at ? formatDateTime(a.last_login_at) : "Chưa đăng nhập"}</span>,
    },
    { key: "act", header: <span className="sr-only">Thao tác</span>, align: "right", cell: actions },
  ];

  const emptyFresh = !loading && !loadError && rows.length === 0 && !hasFilter && query.page === 1;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-title font-extrabold tracking-heading text-ink">Tài khoản staff</h1>
          <p className="num mt-1 text-sm text-ink-soft" aria-live="polite">
            {loading ? "Đang tải…" : `Tổng ${formatCount(total)} tài khoản`}
          </p>
        </div>
        <Button className="max-sm:h-11" leadingIcon={<IconPlus size={18} />} onClick={() => setDialog({ kind: "create" })}>
          Tạo tài khoản
        </Button>
      </div>

      {released && released.ids.length > 0 ? (
        <Alert
          tone="warning"
          title={`${released.ids.length} khóa không còn giáo viên phụ trách, hãy gán lại`}
          action={
            <Button size="sm" variant="secondary" className={btn} onClick={() => setReleased(null)}>
              Đã hiểu
            </Button>
          }
        >
          <p>{released.name} không còn là giáo viên nên đã bị gỡ khỏi các khóa sau:</p>
          <ul className="mt-1 flex flex-wrap gap-x-4 gap-y-1">
            {released.ids.map((id) => (
              <li key={id}>
                <Link href={`/quan-tri/khoa-hoc/${id}/sua`} className="focus-ring inline-flex min-h-11 items-center font-semibold underline underline-offset-2 sm:min-h-0">
                  Khóa #{id}
                </Link>
              </li>
            ))}
          </ul>
        </Alert>
      ) : null}

      {emptyFresh ? (
        <EmptyState
          icon={<IconUsers size={32} />}
          title="Chưa có tài khoản staff nào"
          action={
            <Button leadingIcon={<IconPlus size={18} />} onClick={() => setDialog({ kind: "create" })}>
              Tạo tài khoản
            </Button>
          }
        />
      ) : (
        <>
          <form
            role="search"
            aria-label="Lọc tài khoản staff"
            onSubmit={(e) => {
              e.preventDefault();
              const q = qInput.trim();
              committedQ.current = q;
              if (q !== query.q) update({ q, page: 1 });
            }}
            className="flex flex-col gap-3 sm:flex-row sm:items-end"
          >
            <div className="flex min-w-0 flex-1 flex-col gap-1.5 sm:max-w-sm">
              <label htmlFor="f-q" className="text-sm font-semibold text-ink">
                Tìm theo tên hoặc email
              </label>
              <TextInput
                id="f-q"
                size="sm"
                className={sel}
                type="search"
                name="q"
                value={qInput}
                maxLength={100}
                autoComplete="off"
                leadingIcon={<IconSearch size={16} />}
                onChange={(e) => setQInput(e.target.value)}
              />
            </div>
            <div className="flex flex-col gap-1.5 sm:w-44">
              <label htmlFor="f-role" className="text-sm font-semibold text-ink">
                Vai trò
              </label>
              <Select id="f-role" size="sm" className={sel} name="role" value={query.role} onChange={(e) => update({ role: e.target.value as StaffQuery["role"], page: 1 })}>
                <option value="">Tất cả</option>
                {STAFF_ROLES.map((r) => (
                  <option key={r} value={r}>
                    {STAFF_ROLE_LABELS[r]}
                  </option>
                ))}
              </Select>
            </div>
            <div className="flex flex-col gap-1.5 sm:w-44">
              <label htmlFor="f-status" className="text-sm font-semibold text-ink">
                Trạng thái
              </label>
              <Select id="f-status" size="sm" className={sel} name="status" value={query.status} onChange={(e) => update({ status: e.target.value as StaffQuery["status"], page: 1 })}>
                <option value="">Tất cả</option>
                {STAFF_STATUSES.map((s) => (
                  <option key={s} value={s}>
                    {STAFF_STATUS_LABELS[s]}
                  </option>
                ))}
              </Select>
            </div>
            <button type="submit" className="focus-ring h-9 shrink-0 rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover max-sm:h-11">
              Tìm
            </button>
          </form>

          {loadError ? (
            <Alert
              tone="danger"
              title="Không tải được danh sách tài khoản"
              action={
                <Button size="sm" variant="secondary" className={btn} leadingIcon={<IconRotateCcw size={16} />} onClick={reload}>
                  Thử lại
                </Button>
              }
            >
              {staffActionError(loadError)}
            </Alert>
          ) : (
            <div aria-busy={loading} className="flex flex-col gap-2">
              <DataTable
                caption="Danh sách tài khoản staff"
                columns={columns}
                rows={rows}
                rowKey={(a) => a.id}
                density="compact"
                loadingRows={loading && rows.length === 0 ? 6 : undefined}
                empty={
                  <EmptyState
                    size="inline"
                    icon={<IconSearch size={24} />}
                    title={hasFilter ? "Không có tài khoản nào khớp bộ lọc" : "Chưa có tài khoản staff nào"}
                    headingLevel="h2"
                    action={
                      hasFilter ? (
                        <ButtonLink href={STAFF_PATH} size="sm" variant="secondary" className={btn}>
                          Xoá bộ lọc
                        </ButtonLink>
                      ) : undefined
                    }
                  />
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
                    onChange={(e) => update({ perPage: Number(e.target.value) === 50 ? 50 : 25, page: 1 })}
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
                  hrefFor={(n) => `${pathname}${staffQueryToSearch({ ...query, page: n })}`}
                />
              ) : null}
            </div>
          )}
        </>
      )}

      {dialog?.kind === "create" ? (
        <StaffCreateDialog
          onClose={closeDialog}
          onReload={reload}
          onCreated={(created) => {
            setDialog(null);
            setSecret({ name: created.name, password: created.initial_password, kind: "create" });
            toast.show({ tone: "success", title: "Đã tạo tài khoản staff" });
            reload();
          }}
        />
      ) : null}
      {dialog?.kind === "action" ? <StaffActionDialog action={dialog.action} account={dialog.account} onClose={closeDialog} onDone={onAction} onStale={reload} /> : null}
      {dialog?.kind === "role" ? (
        <StaffRoleDialog
          account={dialog.account}
          onClose={closeDialog}
          onStale={reload}
          onDone={(res) => {
            setDialog(null);
            setReleased(res.released_course_ids.length > 0 ? { name: res.name, ids: res.released_course_ids } : null);
            toast.show({ tone: "success", title: `Đã đổi vai trò của ${res.name} thành ${STAFF_ROLE_LABELS[res.role]}` });
            reload();
          }}
        />
      ) : null}
      {secret ? <PasswordRevealDialog name={secret.name} password={secret.password} kind={secret.kind} onClose={() => setSecret(null)} /> : null}
    </div>
  );
}
