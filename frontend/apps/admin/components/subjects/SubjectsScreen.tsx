"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { Alert, Badge, Button, ConfirmModal, EmptyState, FormField, Select, Table, TextInput, useToast, type TableColumn } from "@vitaminvui/ui";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { useSession } from "@/lib/auth/SessionProvider";
import { deleteSubject, listSubjects, setSubjectStatus } from "@/lib/subjects/api";
import { isForbidden, isSubjectInUse, subjectActionError } from "@/lib/subjects/errors";
import { parseSubjectQuery, subjectQueryToApi, subjectQueryToSearch } from "@/lib/subjects/query";
import { PER_PAGE_OPTIONS, type Subject, type SubjectPage, type SubjectQuery } from "@/lib/subjects/types";
import { StatusSwitch } from "./StatusSwitch";
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
          toast.show("success", saved.status === "hidden" ? "Đã ẩn chuyên đề khỏi bộ lọc công khai" : "Đã hiển thị lại chuyên đề");
          reload();
          ok = true;
        } catch (err) {
          toast.show("danger", subjectActionError(err));
        }
      });
      return ok;
    },
    [withBusy, toast, reload],
  );

  const onSaved = useCallback(
    () => {
      toast.show("success", "Đã lưu chuyên đề");
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

  const columns: TableColumn<Subject>[] = [
    {
      key: "name",
      header: "Tên chuyên đề",
      className: "min-w-44 break-words",
      render: (s) => <span className="break-words font-medium text-gray-900">{s.name}</span>,
    },
  ];
  if (canWrite) {
    columns.push(
      { key: "courses_count", header: "Số khóa học đang gán", className: "whitespace-nowrap", render: (s) => s.courses_count ?? 0 },
      {
        key: "status",
        header: "Trạng thái",
        className: "whitespace-nowrap",
        render: (s) => (
          <div className="flex items-center gap-1">
            <StatusSwitch
              status={s.status}
              name={s.name}
              busy={busyIds.has(s.id)}
              onToggle={() => void changeStatus(s, s.status === "active" ? "hidden" : "active")}
            />
            <Badge variant={s.status === "active" ? "success" : "neutral"}>{s.status === "active" ? "Đang hiển thị" : "Đã ẩn"}</Badge>
          </div>
        ),
      },
      {
        key: "actions",
        header: "Thao tác",
        className: "whitespace-nowrap",
        render: (s) => (
          <div className="flex gap-1">
            <Button size="md" variant="ghost" aria-label={`Sửa chuyên đề ${s.name}`} onClick={() => openDialog({ kind: "form", subject: s })}>
              Sửa
            </Button>
            <Button
              size="md"
              variant="ghost"
              className="text-rose-700 hover:bg-rose-50"
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

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-gray-900">Chuyên đề</h1>
        {canWrite ? (
          <Button data-create-subject onClick={() => openDialog({ kind: "form" })}>
            + Tạo chuyên đề
          </Button>
        ) : null}
      </div>

      {!canWrite ? <p className="text-sm text-gray-700">Danh sách chuyên đề đang hiển thị để chọn khi tạo hoặc sửa khóa học.</p> : null}

      {showEmptyFresh ? (
        <EmptyState
          title="Chưa có chuyên đề nào"
          description={canWrite ? "Tạo chuyên đề đầu tiên để phân loại khóa học." : undefined}
          actionLabel={canWrite ? "+ Tạo chuyên đề đầu tiên" : undefined}
          onAction={canWrite ? () => openDialog({ kind: "form" }) : undefined}
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
            <FormField label="Tìm theo tên">
              <TextInput
                type="search"
                name="q"
                value={qInput}
                maxLength={100}
                placeholder="Nhập tên chuyên đề"
                autoComplete="off"
                onChange={(e) => setQInput(e.target.value)}
              />
            </FormField>
            {canWrite ? (
              <FormField label="Trạng thái">
                <Select
                  name="status"
                  value={query.status}
                  onChange={(e) => navigate({ ...query, status: e.target.value === "active" || e.target.value === "hidden" ? e.target.value : "", page: 1 })}
                >
                  <option value="">Tất cả</option>
                  <option value="active">Đang hiển thị</option>
                  <option value="hidden">Đã ẩn</option>
                </Select>
              </FormField>
            ) : null}
            <FormField label="Số dòng/trang">
              <Select
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
            </FormField>
          </form>

          {loadError ? (
            <Alert variant="danger" title="Không tải được danh sách chuyên đề">
              <p>{subjectActionError(loadError)}</p>
              <Button className="mt-3" variant="outline" onClick={reload}>
                Thử lại
              </Button>
            </Alert>
          ) : (
            <div aria-busy={loading}>
              <Table
                columns={columns}
                rows={rows}
                rowKey={(s) => s.id}
                isLoading={loading && rows.length === 0}
                emptyMessage={hasFilter ? "Không có chuyên đề phù hợp với bộ lọc." : "Chưa có chuyên đề nào"}
                className={loading && rows.length > 0 ? "opacity-60" : ""}
              />
              <p className="mt-2 text-sm text-gray-700" aria-live="polite">
                {loading ? "Đang tải…" : `Tổng ${total} chuyên đề`}
              </p>
              {lastPage > 1 ? (
                <nav aria-label="Phân trang" className="mt-2 flex items-center justify-between gap-3">
                  <Button variant="outline" disabled={query.page <= 1 || loading} onClick={() => navigate({ ...query, page: query.page - 1 })}>
                    Trang trước
                  </Button>
                  <span className="text-sm text-gray-700">
                    Trang {page?.meta.current_page ?? query.page}/{lastPage}
                  </span>
                  <Button variant="outline" disabled={query.page >= lastPage || loading} onClick={() => navigate({ ...query, page: query.page + 1 })}>
                    Trang sau
                  </Button>
                </nav>
              ) : null}
            </div>
          )}
        </>
      )}

      {dialog?.kind === "form" ? <SubjectFormModal subject={dialog.subject} onClose={closeDialog} onSaved={onSaved} /> : null}

      {dialog?.kind === "delete" ? (
        <ConfirmModal
          title="Xoá chuyên đề"
          description={`Xoá chuyên đề '${dialog.subject.name}'? Hành động này không thể hoàn tác.`}
          confirmLabel="Xoá"
          onClose={closeIfIdle}
          onConfirm={async () => {
            const target = dialog.subject;
            dialogBusyRef.current = true;
            try {
              await deleteSubject(target.id);
              toast.show("success", "Đã xoá chuyên đề");
              closeDialog();
              reload();
            } catch (err) {
              if (isSubjectInUse(err)) {
                // Số liệu cũ (vừa có khóa học gán): chuyển sang hộp thoại chặn + gợi ý Ẩn.
                dialogBusyRef.current = false;
                setDialog({ kind: "in-use", subject: target, count: null });
                reload();
              } else {
                toast.show("danger", subjectActionError(err));
                closeDialog();
                reload();
              }
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
