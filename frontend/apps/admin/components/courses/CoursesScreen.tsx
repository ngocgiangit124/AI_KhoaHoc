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
  IconBookOpen,
  IconPencil,
  IconPlus,
  IconRotateCcw,
  IconSearch,
  Pagination,
  Select,
  TextInput,
  formatCount,
  formatPrice,
  type Column,
} from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { listCourses } from "@/lib/courses/api";
import { courseActionError, isForbidden } from "@/lib/courses/errors";
import { isCourseStaff } from "@/lib/courses/permissions";
import { COURSES_PATH, courseQueryToApi, courseQueryToSearch, hasCourseFilter, parseCourseQuery } from "@/lib/courses/query";
import { COURSE_STATUS_LABELS, GRADE_LEVELS, PER_PAGE_OPTIONS, type CourseListItem, type CoursePage, type CourseQuery, type CourseStatus } from "@/lib/courses/types";
import { useSession } from "@/lib/auth/SessionProvider";
import { CourseThumb } from "./CourseThumb";
import { CourseStatusBadge } from "./StatusBadge";
import { useSubjectOptions, useTeacherOptions } from "./useCourseOptions";

type LoadResult = { key: string; page: CoursePage | null; error: unknown };

const SEARCH_DEBOUNCE_MS = 300;

/**
 * Màn danh sách khóa học quản trị (US-009 §2.1, FA3), hình thức theo bản xem trước `/v2/quan-tri/khoa-hoc`.
 * Bộ lọc/trang trên URL. Giáo viên chỉ thấy khóa được gán (server lọc). Xuất bản/ngừng bán/xoá/thứ tự làm ở trang sửa.
 */
export function CoursesScreen() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { state } = useSession();

  const isStaff = state.kind === "staff" && isCourseStaff(state.user);
  const query = useMemo(() => parseCourseQuery(searchParams), [searchParams]);
  const apiQs = courseQueryToApi(query, { isStaff });

  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<LoadResult | null>(null);
  const [qInput, setQInput] = useState(query.q);
  const committedQ = useRef(query.q);

  const ready = state.kind === "staff";
  const subjects = useSubjectOptions(ready, isStaff);
  const teachers = useTeacherOptions(ready && isStaff);

  const requestKey = `${apiQs}#${reloadKey}`;
  const loading = result === null || result.key !== requestKey;
  const page = result?.page ?? null;
  const loadError = !loading && result ? result.error : null;

  useEffect(() => {
    if (!ready) return;
    const controller = new AbortController();
    listCourses(parseCourseQuery(new URLSearchParams(apiQs)), { isStaff, signal: controller.signal })
      .then((data) => setResult({ key: requestKey, page: data, error: null }))
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;
        setResult((prev) => ({ key: requestKey, page: prev?.page ?? null, error }));
      });
    return () => controller.abort();
  }, [apiQs, requestKey, isStaff, ready]);

  const navigate = useCallback(
    (next: CourseQuery) => router.replace(`${pathname}${courseQueryToSearch(next)}`, { scroll: false }),
    [router, pathname],
  );

  // URL đổi từ bên ngoài (link sidebar, back/forward) → kéo ô nhập theo (cùng cách làm với màn Chuyên đề, review FA2 R1).
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

  // Trang vượt quá số trang hiện có (vừa xoá dòng cuối, hoặc URL cũ) → lùi về trang cuối.
  useEffect(() => {
    if (!loading && result?.page && result.page.data.length === 0 && query.page > 1) {
      navigate({ ...query, page: Math.max(1, Math.min(query.page - 1, result.page.meta.last_page || 1)) });
    }
  }, [loading, result, query, navigate]);

  const reload = useCallback(() => setReloadKey((n) => n + 1), []);

  if (state.kind !== "staff") return null;
  if (loadError && isForbidden(loadError)) return <ForbiddenView />;

  const rows = page?.data ?? [];
  const hasFilter = hasCourseFilter(query);
  const showEmptyFresh = !loading && !loadError && rows.length === 0 && !hasFilter && query.page === 1;
  const total = page?.meta.total ?? 0;
  const lastPage = page?.meta.last_page ?? 1;
  const title = isStaff ? "Khóa học" : "Khóa học của tôi";
  const createHref = `${COURSES_PATH}/tao`;
  const editHref = (id: number) => `${COURSES_PATH}/${id}/sua`;

  const columns: Array<Column<CourseListItem>> = [
    {
      key: "title",
      header: "Khóa học",
      cell: (c) => (
        <div className="flex min-w-48 items-center gap-3 sm:min-w-56">
          <div className="w-20 shrink-0">
            <CourseThumb url={c.thumbnail_url} title={c.title} gradeLevel={c.grade_level} subjectSlug={c.subjects[0]?.slug} />
          </div>
          <div className="min-w-0">
            <Link href={editHref(c.id)} className="focus-ring line-clamp-2 break-words rounded font-semibold text-ink hover:text-primary">
              {c.title}
            </Link>
            <p className="truncate text-xs text-ink-soft">/khoa-hoc/{c.slug}</p>
            {/* Dưới 1536px: chuyên đề + giáo viên gộp vào dòng phụ thay cho 2 cột riêng. */}
            <p className="truncate text-xs text-ink-soft 2xl:hidden">
              {c.subjects.map((s) => s.name).join(", ") || "—"}
              {isStaff ? ` · ${c.teachers.map((t) => t.name).join(", ") || "—"}` : ""}
            </p>
            {/* Dưới 768px: lớp, trạng thái, giá đi vào dòng phụ để bảng không tràn khung (QA FA-V2 BUG-2). */}
            <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-soft md:hidden">
              <span>Lớp {c.grade_level}</span>
              <CourseStatusBadge status={c.status} />
              <span>{formatPrice(c.price)}</span>
            </p>
          </div>
        </div>
      ),
    },
    { key: "grade", header: "Lớp", hideBelow: "md", cell: (c) => <span className="num">{c.grade_level}</span> },
    { key: "subjects", header: "Chuyên đề", hideBelow: "2xl", cell: (c) => c.subjects.map((s) => s.name).join(", ") || "—" },
  ];
  if (isStaff) columns.push({ key: "teachers", header: "Giáo viên", hideBelow: "2xl", cell: (c) => c.teachers.map((t) => t.name).join(", ") || "—" });
  columns.push(
    { key: "price", header: "Giá", align: "right", hideBelow: "md", cell: (c) => (c.price === 0 ? <span className="font-semibold text-success">Miễn phí</span> : formatPrice(c.price)) },
    { key: "students", header: "Học sinh", align: "right", hideBelow: "md", cell: (c) => formatCount(c.enrollments_count) },
    { key: "status", header: "Trạng thái", hideBelow: "md", className: "whitespace-nowrap", cell: (c) => <CourseStatusBadge status={c.status} /> },
    {
      key: "actions",
      header: <span className="sr-only">Thao tác</span>,
      align: "right",
      cell: (c) => (
        <ButtonLink
          href={editHref(c.id)}
          variant="ghost"
          size="sm"
          leadingIcon={<IconPencil size={16} />}
          className="max-sm:h-11"
          aria-label={`Sửa khóa học ${c.title}`}
        >
          Sửa
        </ButtonLink>
      ),
    },
  );

  const subjectOptions = subjects.items;
  const subjectMissing = query.subjectId !== null && !subjects.loading && !subjectOptions.some((s) => s.id === query.subjectId);
  const teacherMissing = query.teacherId !== null && !teachers.loading && !teachers.items.some((t) => t.id === query.teacherId);
  const sel = "max-sm:h-11";

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-title font-extrabold tracking-heading text-ink">{title}</h1>
          <p className="num mt-1 text-sm text-ink-soft" aria-live="polite">
            {loading ? "Đang tải…" : `Tổng ${formatCount(total)} khóa học`}
          </p>
        </div>
        <ButtonLink href={createHref} className="max-sm:h-11" leadingIcon={<IconPlus size={18} />}>
          Tạo khóa học
        </ButtonLink>
      </div>

      {showEmptyFresh ? (
        <EmptyState
          icon={<IconBookOpen size={32} />}
          title={isStaff ? "Chưa có khóa học nào" : "Bạn chưa phụ trách khóa học nào"}
          description={isStaff ? "Tạo khóa học đầu tiên, thêm chương và bài rồi xuất bản." : "Khóa học bạn tạo hoặc được Admin gán sẽ hiện ở đây."}
          action={
            <ButtonLink href={createHref} leadingIcon={<IconPlus size={18} />}>
              Tạo khóa học
            </ButtonLink>
          }
        />
      ) : (
        <>
          {/* Bộ lọc: áp dụng ngay khi đổi (giá trị nằm trên URL); Enter/“Lọc” áp dụng ô tìm kiếm tức thì. Mật độ quản trị: ô cao 36px (44px ở mobile). */}
          <form
            role="search"
            aria-label="Tìm và lọc khóa học"
            onSubmit={(e) => {
              e.preventDefault();
              const q = qInput.trim();
              committedQ.current = q;
              if (q !== query.q) navigate({ ...query, q, page: 1 });
            }}
            className="grid gap-3 rounded-card border border-line bg-surface p-3 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-[minmax(14rem,2fr)_repeat(4,minmax(9.5rem,1fr))_auto]"
          >
            <label className="sr-only" htmlFor="f-q">
              Tìm theo tên
            </label>
            <TextInput
              id="f-q"
              size="sm"
              className={sel}
              type="search"
              name="q"
              value={qInput}
              maxLength={100}
              placeholder="Tìm theo tên khóa học"
              autoComplete="off"
              leadingIcon={<IconSearch size={16} />}
              onChange={(e) => setQInput(e.target.value)}
            />
            <label className="sr-only" htmlFor="f-grade">
              Lớp
            </label>
            <Select
              id="f-grade"
              size="sm"
              className={sel}
              name="grade_level"
              value={query.gradeLevel ?? ""}
              onChange={(e) => navigate({ ...query, gradeLevel: e.target.value ? Number(e.target.value) : null, page: 1 })}
            >
              <option value="">Tất cả lớp</option>
              {GRADE_LEVELS.map((g) => (
                <option key={g} value={g}>
                  Lớp {g}
                </option>
              ))}
            </Select>
            <label className="sr-only" htmlFor="f-status">
              Trạng thái
            </label>
            <Select
              id="f-status"
              size="sm"
              className={sel}
              name="status"
              value={query.status}
              onChange={(e) => navigate({ ...query, status: (e.target.value as CourseStatus | "") || "", page: 1 })}
            >
              <option value="">Mọi trạng thái</option>
              {(Object.keys(COURSE_STATUS_LABELS) as CourseStatus[]).map((s) => (
                <option key={s} value={s}>
                  {COURSE_STATUS_LABELS[s]}
                </option>
              ))}
            </Select>
            <label className="sr-only" htmlFor="f-subject">
              Chuyên đề
            </label>
            <Select
              id="f-subject"
              size="sm"
              className={sel}
              name="subject_id"
              value={query.subjectId ?? ""}
              onChange={(e) => navigate({ ...query, subjectId: e.target.value ? Number(e.target.value) : null, page: 1 })}
            >
              <option value="">Mọi chuyên đề</option>
              {subjectOptions.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
              {subjectMissing ? <option value={query.subjectId ?? ""}>Chuyên đề #{query.subjectId}</option> : null}
            </Select>
            {isStaff ? (
              <>
                <label className="sr-only" htmlFor="f-teacher">
                  Giáo viên
                </label>
                <Select
                  id="f-teacher"
                  size="sm"
                  className={sel}
                  name="teacher_id"
                  value={query.teacherId ?? ""}
                  onChange={(e) => navigate({ ...query, teacherId: e.target.value ? Number(e.target.value) : null, page: 1 })}
                >
                  <option value="">Mọi giáo viên</option>
                  {teachers.items.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.name}
                    </option>
                  ))}
                  {teacherMissing ? <option value={query.teacherId ?? ""}>Giáo viên #{query.teacherId}</option> : null}
                </Select>
              </>
            ) : (
              <span className="hidden lg:block" />
            )}
            <button type="submit" className="focus-ring h-9 rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover max-sm:h-11">
              Lọc
            </button>
          </form>

          {loadError ? (
            <Alert
              tone="danger"
              title="Không tải được danh sách khóa học"
              action={
                <Button size="sm" variant="secondary" leadingIcon={<IconRotateCcw size={16} />} onClick={reload}>
                  Thử lại
                </Button>
              }
            >
              {courseActionError(loadError)}
            </Alert>
          ) : (
            <div aria-busy={loading} className="flex flex-col gap-2">
              <DataTable
                caption="Danh sách khóa học"
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
                      title="Không có khóa học phù hợp với bộ lọc."
                      headingLevel="h2"
                      action={
                        <ButtonLink href={COURSES_PATH} size="sm" variant="secondary">
                          Xoá bộ lọc
                        </ButtonLink>
                      }
                    />
                  ) : (
                    <EmptyState size="inline" icon={<IconBookOpen size={24} />} title="Chưa có khóa học nào" headingLevel="h2" />
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
                  hrefFor={(n) => `${pathname}${courseQueryToSearch({ ...query, page: n })}`}
                />
              ) : null}
            </div>
          )}
        </>
      )}
    </div>
  );
}
