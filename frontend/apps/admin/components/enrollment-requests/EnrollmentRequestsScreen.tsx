"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import {
  Alert,
  Badge,
  Button,
  DataTable,
  Dialog,
  EmptyState,
  Field,
  IconInbox,
  IconRotateCcw,
  LinkTabs,
  Pagination,
  Select,
  Textarea,
  formatDateTime,
  useToast,
  type Column,
} from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { useSession } from "@/lib/auth/SessionProvider";
import { isCourseStaff } from "@/lib/courses/permissions";
import { approveRequest, listFreeCourses, listRequests, rejectRequest } from "@/lib/enrollment-requests/api";
import { classifyDecisionError, listErrorMessage } from "@/lib/enrollment-requests/errors";
import { parseRequestQuery, requestQueryToSearch } from "@/lib/enrollment-requests/query";
import { PER_PAGE_OPTIONS, REASON_MAX, STATUS_LABELS, type EnrollmentRequest, type EnrollmentRequestPage, type RequestQuery, type RequestStatus } from "@/lib/enrollment-requests/types";
import { ApiError } from "@vitaminvui/api-client";

type LoadResult = { key: string; page: EnrollmentRequestPage | null; error: unknown };
type CourseOption = { id: number; title: string };

const STATUS_ORDER: readonly RequestStatus[] = ["pending_approval", "active", "rejected"];

/**
 * Duyệt đăng ký khóa miễn phí (US-012, FA6), hình thức theo `/v2/quan-tri/duyet-dang-ky`.
 * - Bộ lọc (trạng thái, khóa), trang và số dòng nằm trên URL; mặc định "Chờ duyệt", cũ nhất trước (do API sắp).
 * - Chỉ hiện thông tin học sinh API trả (tên, lớp, email/SĐT đã che). Giáo viên chỉ thấy khóa mình phụ trách (API lọc).
 * - Duyệt/Từ chối chặn bấm kép bằng ref; từ chối mở hộp thoại lý do tuỳ chọn; 409 đã xử lý/403/404 → báo và tải lại.
 * - API hiện KHÔNG có: thu hồi, duyệt hàng loạt, tìm theo tên học sinh → không dựng nút giả.
 */
export function EnrollmentRequestsScreen() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const toast = useToast();
  const { state } = useSession();
  const query = useMemo(() => parseRequestQuery(searchParams), [searchParams]);
  const apiKey = requestQueryToSearch(query);
  const ready = state.kind === "staff";
  const isStaff = state.kind === "staff" && isCourseStaff(state.user);

  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<LoadResult | null>(null);
  const [courses, setCourses] = useState<CourseOption[] | null>(null);
  const [coursesError, setCoursesError] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const busyRef = useRef(false);
  const [rowNotes, setRowNotes] = useState<Record<number, string>>({});
  const [rejecting, setRejecting] = useState<EnrollmentRequest | null>(null);
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState<string | null>(null);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const dialogBody = useRef<HTMLDivElement>(null);

  const requestKey = `${apiKey}#${reloadKey}`;
  const loading = result === null || result.key !== requestKey;
  const page = result?.page ?? null;
  const loadError = !loading && result ? result.error : null;

  useEffect(() => {
    if (!ready) return;
    const controller = new AbortController();
    listRequests(parseRequestQuery(new URLSearchParams(apiKey)), controller.signal)
      .then((data) => setResult({ key: requestKey, page: data, error: null }))
      .catch((err: unknown) => {
        if (controller.signal.aborted) return;
        setResult((prev) => ({ key: requestKey, page: prev?.page ?? null, error: err }));
      });
    return () => controller.abort();
  }, [apiKey, requestKey, ready]);

  useEffect(() => {
    if (!ready) return;
    const controller = new AbortController();
    listFreeCourses(isStaff, controller.signal)
      .then((list) => setCourses(list.map((c) => ({ id: c.id, title: c.title }))))
      .catch(() => {
        if (!controller.signal.aborted) setCoursesError(true);
      });
    return () => controller.abort();
  }, [ready, isStaff]);

  const navigate = useCallback((next: RequestQuery) => router.replace(`${pathname}${requestQueryToSearch(next)}`, { scroll: false }), [router, pathname]);

  // Trang vượt quá số trang hiện có (vừa xử lý hết dòng cuối) → lùi về trang cuối.
  useEffect(() => {
    if (!loading && result?.page && result.page.data.length === 0 && query.page > 1) {
      navigate({ ...query, page: Math.max(1, Math.min(query.page - 1, result.page.meta.last_page || 1)) });
    }
  }, [loading, result, query, navigate]);

  useEffect(() => {
    if (reasonError) dialogBody.current?.querySelector("textarea")?.focus();
  }, [reasonError]);

  const reload = useCallback(() => setReloadKey((n) => n + 1), []);

  if (state.kind !== "staff") return null;
  if (loadError instanceof ApiError && loadError.status === 403) return <ForbiddenView />;

  const rows = page?.data ?? [];
  const total = page?.meta.total ?? 0;
  const pending = query.status === "pending_approval";
  const courseOptions: CourseOption[] = courses ?? [];
  const hasCurrentCourse = query.courseId === null || courseOptions.some((c) => c.id === query.courseId);

  async function decide(r: EnrollmentRequest, action: "approve" | "reject") {
    if (busyRef.current) return;
    busyRef.current = true;
    setBusyId(r.id);
    setRowNotes((prev) => ({ ...prev, [r.id]: "" }));
    setReasonError(null);
    setDialogError(null);
    try {
      if (action === "approve") await approveRequest(r.id);
      else await rejectRequest(r.id, reason);
      setRejecting(null);
      toast.show({ tone: "success", title: action === "approve" ? `Đã duyệt yêu cầu của ${r.student.name}` : `Đã từ chối yêu cầu của ${r.student.name}` });
      reload();
    } catch (err) {
      const failure = classifyDecisionError(err);
      if (failure.stale) {
        setRejecting(null);
        toast.show({ tone: "warning", title: failure.message });
        reload();
      } else if (action === "reject") {
        setReasonError(failure.reasonError);
        setDialogError(failure.reasonError ? null : failure.message);
      } else {
        setRowNotes((prev) => ({ ...prev, [r.id]: failure.message }));
      }
    } finally {
      busyRef.current = false;
      setBusyId(null);
    }
  }

  const columns: Array<Column<EnrollmentRequest>> = [
    {
      key: "student",
      header: "Học sinh",
      cell: (r) => (
        <div className="min-w-0">
          <p className="break-words font-semibold">{r.student.name}</p>
          <p className="break-words text-xs text-ink-soft">
            {r.student.grade_level ? `Lớp ${r.student.grade_level}` : "Chưa rõ lớp"}
            {r.student.email_masked ? ` · ${r.student.email_masked}` : ""}
            {r.student.phone_masked ? ` · ${r.student.phone_masked}` : ""}
          </p>
          <p className="mt-0.5 line-clamp-2 text-xs text-ink-soft md:hidden">{r.course.title}</p>
          {r.requested_at ? <p className="num mt-0.5 text-xs text-ink-soft md:hidden">Gửi lúc {formatDateTime(r.requested_at)}</p> : null}
          {rowNotes[r.id] ? (
            <p role="alert" className="mt-1 text-xs font-semibold text-danger">
              {rowNotes[r.id]}
            </p>
          ) : null}
        </div>
      ),
    },
    { key: "course", header: "Khóa học", hideBelow: "md", cell: (r) => <span className="line-clamp-2">{r.course.title}</span> },
    { key: "time", header: "Gửi lúc", hideBelow: "md", cell: (r) => <span className="num whitespace-nowrap">{r.requested_at ? formatDateTime(r.requested_at) : "—"}</span> },
    {
      key: "act",
      header: pending ? <span className="sr-only">Thao tác</span> : "Kết quả",
      align: pending ? "right" : "left",
      // Theo trạng thái của CHÍNH dòng (không theo tab): dòng cũ còn trên màn lúc đổi tab không bao giờ có nút duyệt.
      cell: (r) =>
        r.status === "pending_approval" ? (
          <div className="flex flex-col justify-end gap-2 sm:flex-row">
            <Button
              size="sm"
              variant="secondary"
              className="max-sm:h-11"
              disabled={busyId !== null || loading}
              aria-label={`Từ chối yêu cầu của ${r.student.name}`}
              onClick={() => {
                setReason("");
                setReasonError(null);
                setDialogError(null);
                setRejecting(r);
              }}
            >
              Từ chối
            </Button>
            <Button
              size="sm"
              className="max-sm:h-11"
              loading={busyId === r.id && rejecting === null}
              loadingText="Đang duyệt…"
              disabled={busyId !== null || loading}
              aria-label={`Duyệt yêu cầu của ${r.student.name}`}
              onClick={() => void decide(r, "approve")}
            >
              Duyệt
            </Button>
          </div>
        ) : r.status === "active" ? (
          <div className="flex flex-col gap-1">
            <Badge size="sm" tone="success">
              Đã duyệt
            </Badge>
            {r.approved_at ? <span className="num text-xs text-ink-soft">{formatDateTime(r.approved_at)}</span> : null}
          </div>
        ) : (
          <div className="flex flex-col gap-1">
            <Badge size="sm" tone="danger">
              Đã từ chối
            </Badge>
            {r.rejection_reason ? <span className="break-words text-xs text-ink-soft">Lý do: {r.rejection_reason}</span> : null}
          </div>
        ),
    },
  ];

  const tabHref = (s: RequestStatus) => `${pathname}${requestQueryToSearch({ ...query, status: s, page: 1 })}`;
  const hasFilter = query.courseId !== null;

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-title font-extrabold tracking-heading text-ink">Duyệt đăng ký khóa miễn phí</h1>
        <p className="mt-1 max-w-3xl text-sm text-ink-soft">
          {isStaff ? "Yêu cầu của học sinh đăng ký các khóa miễn phí, cũ nhất trước." : "Yêu cầu đăng ký các khóa miễn phí do bạn phụ trách, cũ nhất trước."} Học sinh đã xác thực email sẽ nhận email kết quả (kèm lý do nếu bị từ chối).
        </p>
      </div>

      <form role="search" aria-label="Lọc yêu cầu" onSubmit={(e) => e.preventDefault()} className="grid gap-3 rounded-card border border-line bg-surface p-3 sm:grid-cols-[minmax(14rem,2fr)_8rem] sm:items-end">
        <Field label="Khóa học">
          <Select
            size="sm"
            className="max-sm:h-11"
            name="course_id"
            value={query.courseId ?? ""}
            onChange={(e) => navigate({ ...query, courseId: e.target.value ? Number(e.target.value) : null, page: 1 })}
          >
            <option value="">{isStaff ? "Tất cả khóa miễn phí" : "Tất cả khóa tôi phụ trách"}</option>
            {!hasCurrentCourse && query.courseId !== null ? <option value={query.courseId}>{`Khóa #${query.courseId}`}</option> : null}
            {courseOptions.map((c) => (
              <option key={c.id} value={c.id}>
                {c.title}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Số dòng/trang">
          <Select size="sm" className="max-sm:h-11" name="per_page" value={query.perPage} onChange={(e) => navigate({ ...query, perPage: Number(e.target.value) === 50 ? 50 : 25, page: 1 })}>
            {PER_PAGE_OPTIONS.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </Select>
        </Field>
      </form>
      {coursesError ? <p className="text-xs text-ink-soft">Không tải được danh sách khóa để lọc. Bạn vẫn xem và xử lý được các yêu cầu.</p> : null}

      <LinkTabs
        label="Trạng thái yêu cầu"
        items={STATUS_ORDER.map((s) => ({
          href: tabHref(s),
          label: STATUS_LABELS[s],
          current: query.status === s,
          ...(query.status === s && page && !loadError ? { count: total } : {}),
        }))}
      />

      {loadError ? (
        <Alert
          tone="danger"
          title="Không tải được danh sách yêu cầu"
          action={
            <Button size="sm" variant="secondary" leadingIcon={<IconRotateCcw size={16} />} onClick={reload}>
              Thử lại
            </Button>
          }
        >
          {listErrorMessage(loadError)}
        </Alert>
      ) : (
        <div aria-busy={loading}>
          <DataTable
            caption="Yêu cầu đăng ký khóa miễn phí"
            columns={columns}
            rows={rows}
            rowKey={(r) => r.id}
            density="compact"
            {...(loading && !page ? { loadingRows: 5 } : {})}
            empty={
              <EmptyState
                size="inline"
                icon={<IconInbox size={24} />}
                title={pending ? (hasFilter ? "Khóa này không có yêu cầu nào đang chờ duyệt" : "Hiện không có yêu cầu nào đang chờ duyệt") : "Chưa có yêu cầu nào"}
                headingLevel="h2"
              />
            }
          />
        </div>
      )}

      <Pagination currentPage={page?.meta.current_page ?? query.page} lastPage={page?.meta.last_page ?? 1} hrefFor={(n) => `${pathname}${requestQueryToSearch({ ...query, page: n })}`} />

      <Dialog
        open={rejecting !== null}
        onClose={() => setRejecting(null)}
        dismissible={busyId === null}
        title={`Từ chối yêu cầu của ${rejecting?.student.name ?? ""}`}
        description={rejecting ? `Khóa: ${rejecting.course.title}. Học sinh nhận email kèm lý do (nếu có) và có thể đăng ký lại.` : undefined}
        size="sm"
        footer={
          <>
            <Button variant="secondary" className="max-sm:h-11" disabled={busyId !== null} onClick={() => setRejecting(null)}>
              Huỷ
            </Button>
            <Button variant="danger" className="max-sm:h-11" loading={busyId !== null && rejecting !== null} loadingText="Đang từ chối…" onClick={() => rejecting && void decide(rejecting, "reject")}>
              Xác nhận từ chối
            </Button>
          </>
        }
      >
        <div ref={dialogBody} className="flex flex-col gap-3">
          {dialogError ? (
            <Alert tone="danger" role="alert">
              {dialogError}
            </Alert>
          ) : null}
          <Field label="Lý do (không bắt buộc)" hint="Chỉ nhập văn bản thuần (không dùng ký tự < >)." aside={<span className="num text-ink-soft">{`${reason.length}/${REASON_MAX}`}</span>} {...(reasonError ? { error: reasonError } : {})}>
            <Textarea rows={3} maxLength={REASON_MAX} value={reason} onChange={(e) => setReason(e.target.value)} className="text-sm" />
          </Field>
        </div>
      </Dialog>
    </div>
  );
}
