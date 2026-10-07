"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import {
  Alert,
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  EmptyState,
  Field,
  IconPencil,
  IconPlus,
  IconSearch,
  IconShapes,
  IconTrash,
  Pagination,
  Select,
  Switch,
  TextInput,
  useToast,
  type Column,
} from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { useSession } from "@/lib/auth/SessionProvider";
import { deleteSubject, listSubjects, setSubjectStatus } from "@/lib/subjects/api";
import { isForbidden, isSubjectGone, isSubjectInUse, subjectActionError } from "@/lib/subjects/errors";
import { parseSubjectQuery, subjectQueryToApi, subjectQueryToSearch } from "@/lib/subjects/query";
import { PER_PAGE_OPTIONS, type Subject, type SubjectPage, type SubjectQuery } from "@/lib/subjects/types";
import { SubjectFormModal } from "./SubjectFormModal";
import { SubjectInUseModal } from "./SubjectInUseModal";

type Dialog =
  | { kind: "form"; subject?: Subject }
  | { kind: "delete"; subject: Subject }
  | { kind: "in-use"; subject: Subject; count: number | null };

type LoadResult = { key: string; page: SubjectPage | null; error: unknown };

const SEARCH_DEBOUNCE_MS = 300;

/** Màn quản lý chuyên đề (US-011, FA2). Bộ lọc/trang trên URL. Nút ghi chỉ cho staff; API vẫn kiểm quyền thật. */
export function SubjectsScreen() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const toast = useToast();
  const { state } = useSession();

  const canWrite = state.kind === "staff" && (state.user.permissions?.manage_subjects ?? state.user.role !== "giao_vien");
  const query = useMemo(() => parseSubjectQuery(searchParams), [searchParams]);
  const apiQs = subjectQueryToApi(query, { includeStatus: canWrite });

  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<LoadResult | null>(null);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [busyIds, setBusyIds] = useState<ReadonlySet<number>>(new Set());
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [qInput, setQInput] = useState(query.q);
  const openerRef = useRef<HTMLElement | null>(null);
  // Đang gọi API trong hộp thoại (xoá/ẩn): chặn Esc/overlay/Huỷ để không đóng giữa chừng.
  const dialogBusyRef = useRef(false);
  // Giá trị `q` đã đẩy lên URL từ ô nhập; khác `query.q` nghĩa là URL đổi từ bên ngoài (link sidebar, back/forward).
  const committedQ = useRef(query.q);

  const requestKey = `${apiQs}#${reloadKey}`;
  const loading = result === null || result.key !== requestKey;
  const page = result?.page ?? null;
  const loadError = !loading && result ? result.error : null;

  useEffect(() => {
    if (state.kind !== "staff") return;
    const controller = new AbortController();
    listSubjects(parseSubjectQuery(new URLSearchParams(apiQs)), { includeStatus: canWrite, signal: controller.signal })
      .then((data) => setResult({ key: requestKey, page: data, error: null }))
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;
        setResult((prev) => ({ key: requestKey, page: prev?.page ?? null, error }));
      });
    return () => controller.abort();
  }, [apiQs, requestKey, canWrite, state.kind]);

  const navigate = useCallback(
    (next: SubjectQuery) => router.replace(`${pathname}${subjectQueryToSearch(next)}`, { scroll: false }),
    [router, pathname],
  );

  // URL đổi từ bên ngoài → kéo ô nhập theo, để debounce bên dưới không đẩy giá trị cũ lên lại.
  useEffect(() => {
    if (query.q !== committedQ.current) {
      committedQ.current = query.q;
      // Đồng bộ state cục bộ với URL (nguồn ngoài).
      setQInput(query.q);
    }
  }, [query.q]);

  // Gõ tới đâu lọc tới đó (debounce), về trang 1 khi đổi từ khoá.
  useEffect(() => {
    const q = qInput.trim();
    if (q === query.q) return;
    // Người dùng chưa gõ gì mới mà URL đã đổi (chưa kịp đồng bộ ở effect trên) → không đẩy ngược.
    if (query.q !== committedQ.current) return;
    const t = setTimeout(() => {
      committedQ.current = q;
      navigate({ ...query, q, page: 1 });
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput, query, navigate]);

  // Trang vượt quá số trang hiện có (vừa xoá dòng cuối, hoặc URL cũ) → lùi về trang cuối.
  useEffect(() => {
    if (!loading && result?.page && result.page.data.length === 0 && query.page > 1) {
      navigate({ ...query, page: Math.max(1, Math.min(query.page - 1, result.page.meta.last_page || 1)) });
    }
  }, [loading, result, query, navigate]);

  const reload = useCallback(() => setReloadKey((n) => n + 1), []);

  const openDialog = useCallback((d: Dialog) => {
    openerRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    setDialog(d);
  }, []);
  const closeDialog = useCallback(() => {
    dialogBusyRef.current = false;
    setDialog(null);
    const el = openerRef.current;
    // Chờ DOM cập nhật; nếu nút gốc đã bị gỡ (sau tải lại danh sách) → về nút "Tạo chuyên đề".
    setTimeout(() => (el?.isConnected ? el : document.querySelector<HTMLElement>("[data-create-subject]"))?.focus(), 0);
  }, []);

  const closeIfIdle = useCallback(() => {
    if (!dialogBusyRef.current) closeDialog();
  }, [closeDialog]);

  const withBusy = useCallback(async (id: number, fn: () => Promise<void>) => {
    setBusyIds((s) => new Set(s).add(id));
    try {
      await fn();
    } finally {
      setBusyIds((s) => {
        const n = new Set(s);
        n.delete(id);
        return n;
      });
    }
  }, []);

  const changeStatus = useCallback(
    async (subject: Subject, status: Subject["status"]): Promise<boolean> => {
      let ok = false;
      await withBusy(subject.id, async () => {
        try {
          const saved = await setSubjectStatus(subject.id, status);
          toast.show({ tone: "success", title: saved.status === "hidden" ? "Đã ẩn chuyên đề khỏi bộ lọc công khai" : "Đã hiển thị lại chuyên đề" });
          reload();
          ok = true;
        } catch (err) {
          toast.show({ tone: "danger", title: subjectActionError(err) });
          // Dòng đã cũ (chuyên đề bị xoá từ nơi khác): tải lại để dòng chết biến mất (QA FA2 BUG-1).
          if (isSubjectGone(err)) reload();
        }
      });
      return ok;
    },
    [withBusy, toast, reload],
  );

  const onSaved = useCallback(
    () => {
      toast.show({ tone: "success", title: "Đã lưu chuyên đề" });
      closeDialog();
      reload();
    },
    [toast, closeDialog, reload],
  );

  if (state.kind !== "staff") return null;

  const rows = page?.data ?? [];
  const hasFilter = query.q !== "" || query.status !== "";
  const showEmptyFresh = !loading && !loadError && rows.length === 0 && !hasFilter && query.page === 1;

  if (loadError && isForbidden(loadError)) return <ForbiddenView />;

  const hrefFor = (n: number) => `${pathname}${subjectQueryToSearch({ ...query, page: n })}`;

  const columns: Array<Column<Subject>> = [
    {
      key: "name",
      header: "Tên chuyên đề",
      className: "min-w-32 sm:min-w-40",
      cell: (s) => (
        <div className="min-w-0">
          <p className="break-words font-semibold text-ink">{s.name}</p>
          <p className="break-words text-xs text-ink-soft">
            /{s.slug}
            {canWrite ? <span className="md:hidden"> · {s.courses_count ?? 0} khóa học</span> : null}
            {canWrite ? <span className="sm:hidden"> · {s.status === "active" ? "Đang hiển thị" : "Đã ẩn"}</span> : null}
          </p>
        </div>
      ),
    },
  ];
  if (canWrite) {
    columns.push(
      { key: "courses_count", header: "Khóa học đang gán", align: "right", hideBelow: "md", cell: (s) => s.courses_count ?? 0 },
      {
        key: "status",
        // Tiêu đề dài làm cột rộng ~150px ở 375px đẩy "Thao tác" ra ngoài khung (QA FA-V2 BUG-2): rút gọn dưới `sm`.
        header: (
          <>
            <span className="max-sm:hidden">Hiển thị công khai</span>
            <span className="sm:hidden" aria-hidden="true">
              Hiện
            </span>
            <span className="sr-only sm:hidden">Hiển thị công khai</span>
          </>
        ),
        className: "whitespace-nowrap",
        cell: (s) => (
          <div className="flex items-center gap-1">
            <Switch
              checked={s.status === "active"}
              label={`Hiển thị chuyên đề ${s.name}`}
              showState={false}
              disabled={busyIds.has(s.id)}
              onCheckedChange={() => void changeStatus(s, s.status === "active" ? "hidden" : "active")}
            />
            <Badge size="sm" dot className="max-sm:hidden" tone={s.status === "active" ? "success" : "neutral"}>
              {s.status === "active" ? "Đang hiển thị" : "Đã ẩn"}
            </Badge>
          </div>
        ),
      },
      {
        key: "actions",
        header: <span className="max-sm:hidden">Thao tác</span>,
        className: "whitespace-nowrap",
        cell: (s) => (
          <div className="flex justify-end gap-1">
            <Button
              size="sm"
              variant="ghost"
              leadingIcon={<IconPencil size={16} />}
              className="max-sm:size-11 max-sm:[&>span]:hidden"
              aria-label={`Sửa chuyên đề ${s.name}`}
              onClick={() => openDialog({ kind: "form", subject: s })}
            >
              Sửa
            </Button>
            <Button
              size="sm"
              variant="ghost"
              leadingIcon={<IconTrash size={16} />}
              className="text-danger hover:bg-danger-soft hover:text-danger max-sm:size-11 max-sm:[&>span]:hidden"
              aria-label={`Xoá chuyên đề ${s.name}`}
              onClick={() =>
                openDialog(
                  (s.courses_count ?? 0) > 0 ? { kind: "in-use", subject: s, count: s.courses_count ?? 0 } : { kind: "delete", subject: s },
                )
              }
            >
              Xoá
            </Button>
          </div>
        ),
      },
    );
  }

  const total = page?.meta.total ?? 0;
  const lastPage = page?.meta.last_page ?? 1;
  const createButton = (label: string) => (
    <Button size="sm" className="max-sm:h-11" data-create-subject leadingIcon={<IconPlus size={16} />} onClick={() => openDialog({ kind: "form" })}>
      {label}
    </Button>
  );

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-title font-extrabold tracking-heading text-ink">Chuyên đề</h1>
          <p className="mt-1 max-w-3xl text-sm text-ink-soft">
            Chuyên đề dùng để lọc khóa học ở danh mục và gán cho khóa học. Chuyên đề bị ẩn không hiện ở bộ lọc công khai.
          </p>
        </div>
        {canWrite ? createButton("Tạo chuyên đề") : null}
      </div>

      {!canWrite ? (
        <Alert tone="info" title="Chế độ chỉ xem">
          Danh sách chuyên đề đang hiển thị để chọn khi tạo hoặc sửa khóa học. Admin/Quản lý trang tạo, sửa và ẩn chuyên đề.
        </Alert>
      ) : null}

      {showEmptyFresh ? (
        <EmptyState
          icon={<IconShapes size={32} />}
          title="Chưa có chuyên đề nào"
          description={canWrite ? "Tạo chuyên đề đầu tiên để phân loại khóa học." : undefined}
          action={canWrite ? createButton("Tạo chuyên đề đầu tiên") : undefined}
        />
      ) : (
        <>
          <form
            role="search"
            aria-label="Tìm và lọc chuyên đề"
            onSubmit={(e) => {
              e.preventDefault();
              const q = qInput.trim();
              if (q !== query.q) navigate({ ...query, q, page: 1 });
            }}
            className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto]"
          >
            <Field label="Tìm theo tên">
              <TextInput
                size="sm"
                className="max-sm:h-11"
                type="search"
                name="q"
                value={qInput}
                maxLength={100}
                placeholder="Nhập tên chuyên đề"
                autoComplete="off"
                leadingIcon={<IconSearch size={16} />}
                onChange={(e) => setQInput(e.target.value)}
              />
            </Field>
            {canWrite ? (
              <Field label="Trạng thái">
                <Select
                  size="sm"
                  className="max-sm:h-11"
                  name="status"
                  value={query.status}
                  onChange={(e) => navigate({ ...query, status: e.target.value === "active" || e.target.value === "hidden" ? e.target.value : "", page: 1 })}
                >
                  <option value="">Tất cả</option>
                  <option value="active">Đang hiển thị</option>
                  <option value="hidden">Đã ẩn</option>
                </Select>
              </Field>
            ) : null}
            <Field label="Số dòng/trang">
              <Select
                size="sm"
                className="max-sm:h-11"
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
            </Field>
          </form>

          {loadError ? (
            <Alert
              tone="danger"
              title="Không tải được danh sách chuyên đề"
              action={
                <Button size="sm" variant="secondary" onClick={reload}>
                  Thử lại
                </Button>
              }
            >
              {subjectActionError(loadError)}
            </Alert>
          ) : (
            <div aria-busy={loading} className="flex flex-col gap-2">
              <DataTable
                caption="Danh sách chuyên đề"
                columns={columns}
                rows={rows}
                rowKey={(s) => s.id}
                density="compact"
                loadingRows={loading && rows.length === 0 ? 5 : undefined}
                empty={
                  <EmptyState
                    size="inline"
                    icon={<IconSearch size={24} />}
                    title={hasFilter ? "Không có chuyên đề phù hợp với bộ lọc." : "Chưa có chuyên đề nào"}
                  />
                }
              />
              <p className="text-sm text-ink-soft" aria-live="polite">
                {loading ? "Đang tải…" : `Tổng ${total} chuyên đề`}
              </p>
              {lastPage > 1 ? <Pagination currentPage={page?.meta.current_page ?? query.page} lastPage={lastPage} hrefFor={hrefFor} /> : null}
            </div>
          )}
        </>
      )}

      {dialog?.kind === "form" ? <SubjectFormModal subject={dialog.subject} onClose={closeDialog} onSaved={onSaved} onGone={reload} /> : null}

      {dialog?.kind === "delete" ? (
        <ConfirmDialog
          open
          tone="danger"
          title="Xoá chuyên đề"
          description={`Xoá chuyên đề '${dialog.subject.name}'? Hành động này không thể hoàn tác.`}
          confirmLabel="Xoá"
          loading={deleteBusy}
          loadingText="Đang xoá…"
          onClose={closeIfIdle}
          onConfirm={async () => {
            const target = dialog.subject;
            dialogBusyRef.current = true;
            setDeleteBusy(true);
            try {
              await deleteSubject(target.id);
              toast.show({ tone: "success", title: "Đã xoá chuyên đề" });
              closeDialog();
              reload();
            } catch (err) {
              if (isSubjectInUse(err)) {
                // Số liệu cũ (vừa có khóa học gán): chuyển sang hộp thoại chặn + gợi ý Ẩn.
                dialogBusyRef.current = false;
                setDialog({ kind: "in-use", subject: target, count: null });
                reload();
              } else {
                toast.show({ tone: "danger", title: subjectActionError(err) });
                closeDialog();
                reload();
              }
            } finally {
              setDeleteBusy(false);
            }
          }}
        />
      ) : null}

      {dialog?.kind === "in-use" ? (
        <SubjectInUseModal
          subject={dialog.subject}
          count={dialog.count}
          onClose={closeIfIdle}
          onHide={async () => {
            dialogBusyRef.current = true;
            const ok = await changeStatus(dialog.subject, "hidden");
            dialogBusyRef.current = false;
            // Chỉ đóng khi ẩn thành công; thất bại giữ hộp thoại (toast đã báo lỗi) để thử lại.
            if (ok) closeDialog();
          }}
        />
      ) : null}
    </div>
  );
}
