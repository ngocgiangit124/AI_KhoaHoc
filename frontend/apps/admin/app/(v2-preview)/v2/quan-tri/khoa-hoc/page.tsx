import Link from "next/link";
import {
  Alert,
  ButtonLink,
  CourseCover,
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
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { CourseStatusBadge } from "@/components/v2/CourseStatusBadge";
import { ADMIN_COURSES, STAFF, STATUS_LABEL, SUBJECTS, TEACHERS, type AdminCourse, type CourseStatus } from "@/lib/mock/v2/data";

export const dynamic = "force-dynamic";

function one(v: string | string[] | undefined) {
  return Array.isArray(v) ? v[0] : v;
}

/** Danh sách khóa học quản trị (US-009 §2.1, GET /admin/courses). GV chỉ thấy khóa mình phụ trách. */
export default async function AdminCoursesPreview({ searchParams }: PageProps<"/v2/quan-tri/khoa-hoc">) {
  const sp = await searchParams;
  const role = roleFrom(sp["vai-tro"]);
  const user = STAFF[role];
  const state = one(sp["trang-thai"]);
  const q = one(sp.q) ?? "";
  const status = one(sp.status) as CourseStatus | undefined;
  const grade = Number(one(sp.grade_level)) || undefined;
  const subjectId = Number(one(sp.subject_id)) || undefined;
  const teacherId = Number(one(sp.teacher_id)) || undefined;
  const isStaff = role !== "giao_vien";
  const roleQ = role === "admin" ? "" : `vai-tro=${role}`;

  let rows = ADMIN_COURSES.filter((c) => isStaff || c.teachers.some((t) => t.id === user.id));
  rows = rows.filter(
    (c) =>
      (!q || c.title.toLowerCase().includes(q.toLowerCase())) &&
      (!status || c.status === status) &&
      (!grade || c.grade_level === grade) &&
      (!subjectId || c.subjects.some((s) => s.id === subjectId)) &&
      (!teacherId || c.teachers.some((t) => t.id === teacherId)),
  );
  if (state === "rong") rows = [];
  const editHref = (id: number) => `/v2/quan-tri/khoa-hoc/${id}/sua${roleQ ? `?${roleQ}` : ""}`;

  const columns: Array<Column<AdminCourse>> = [
    {
      key: "title",
      header: "Khóa học",
      cell: (c) => (
        <div className="flex min-w-56 items-center gap-3">
          <div className="w-20 shrink-0">
            <CourseCover title={c.title} gradeLevel={c.grade_level} subjectSlug={c.subjects[0]?.slug} size="thumb" />
          </div>
          <div className="min-w-0">
            <Link href={editHref(c.id)} className="focus-ring line-clamp-2 rounded font-semibold text-ink hover:text-primary">
              {c.title}
            </Link>
            <p className="truncate text-xs text-ink-soft">/khoa-hoc/{c.slug}</p>
            {/* Màn < 1536px: chuyên đề + giáo viên gộp vào dòng phụ thay cho 2 cột riêng. */}
            <p className="truncate text-xs text-ink-soft 2xl:hidden">
              {c.subjects.map((s) => s.name).join(", ")}
              {isStaff ? ` · ${c.teachers.map((t) => t.name).join(", ")}` : ""}
            </p>
          </div>
        </div>
      ),
    },
    { key: "grade", header: "Lớp", cell: (c) => <span className="num">{c.grade_level}</span> },
    { key: "subjects", header: "Chuyên đề", hideBelow: "2xl", cell: (c) => c.subjects.map((s) => s.name).join(", ") },
    ...(isStaff ? [{ key: "teachers", header: "Giáo viên", hideBelow: "2xl" as const, cell: (c: AdminCourse) => c.teachers.map((t) => t.name).join(", ") }] : []),
    { key: "price", header: "Giá", align: "right", hideBelow: "md", cell: (c) => (c.price === 0 ? <span className="font-semibold text-success">Miễn phí</span> : formatPrice(c.price)) },
    { key: "students", header: "Học sinh", align: "right", hideBelow: "md", cell: (c) => formatCount(c.enrollments_count) },
    { key: "status", header: "Trạng thái", cell: (c) => <CourseStatusBadge status={c.status} /> },
    {
      key: "actions",
      header: <span className="sr-only">Thao tác</span>,
      align: "right",
      cell: (c) => (
        <ButtonLink href={editHref(c.id)} variant="ghost" size="sm" leadingIcon={<IconPencil size={16} />} aria-label={`Sửa ${c.title}`}>
          Sửa
        </ButtonLink>
      ),
    },
  ];

  return (
    <AdminPreviewShell
      role={role}
      current="courses"
      basePath="/v2/quan-tri/khoa-hoc"
      state={state}
      states={[
        { label: "Có dữ liệu" },
        { key: "dang-tai", label: "Đang tải" },
        { key: "rong", label: "Rỗng" },
        { key: "loi", label: "Lỗi" },
      ]}
    >
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-title font-extrabold tracking-heading text-ink">{isStaff ? "Khóa học" : "Khóa học của tôi"}</h1>
          <p className="num mt-1 text-sm text-ink-soft">{state === "dang-tai" ? "Đang tải…" : `${rows.length} khóa học`}</p>
        </div>
        <ButtonLink href="/v2/quan-tri/khoa-hoc/tao" leadingIcon={<IconPlus size={18} />}>
          Tạo khóa học
        </ButtonLink>
      </div>

      {/* Bộ lọc: form GET, giá trị nằm trên URL. Mật độ quản trị: ô cao 36px, chữ 14px. */}
      <form className="mt-5 grid gap-3 rounded-card border border-line bg-surface p-3 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-[minmax(14rem,2fr)_repeat(4,minmax(9.5rem,1fr))_auto]" role="search" aria-label="Lọc khóa học">
        {roleQ ? <input type="hidden" name="vai-tro" value={role} /> : null}
        <label className="sr-only" htmlFor="f-q">
          Tìm theo tên
        </label>
        <TextInput id="f-q" name="q" size="sm" defaultValue={q} placeholder="Tìm theo tên khóa học" leadingIcon={<IconSearch size={16} />} />
        <label className="sr-only" htmlFor="f-grade">
          Lớp
        </label>
        <Select id="f-grade" name="grade_level" size="sm" defaultValue={grade ?? ""}>
          <option value="">Tất cả lớp</option>
          {[6, 7, 8, 9, 10, 11, 12].map((g) => (
            <option key={g} value={g}>
              Lớp {g}
            </option>
          ))}
        </Select>
        <label className="sr-only" htmlFor="f-status">
          Trạng thái
        </label>
        <Select id="f-status" name="status" size="sm" defaultValue={status ?? ""}>
          <option value="">Mọi trạng thái</option>
          {(Object.keys(STATUS_LABEL) as CourseStatus[]).map((s) => (
            <option key={s} value={s}>
              {STATUS_LABEL[s]}
            </option>
          ))}
        </Select>
        <label className="sr-only" htmlFor="f-subject">
          Chuyên đề
        </label>
        <Select id="f-subject" name="subject_id" size="sm" defaultValue={subjectId ?? ""}>
          <option value="">Mọi chuyên đề</option>
          {SUBJECTS.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </Select>
        {isStaff ? (
          <>
            <label className="sr-only" htmlFor="f-teacher">
              Giáo viên
            </label>
            <Select id="f-teacher" name="teacher_id" size="sm" defaultValue={teacherId ?? ""}>
              <option value="">Mọi giáo viên</option>
              {TEACHERS.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </Select>
          </>
        ) : (
          <span className="hidden lg:block" />
        )}
        <button type="submit" className="focus-ring h-9 rounded-control bg-primary px-4 text-sm font-semibold text-on-primary hover:bg-primary-hover">
          Lọc
        </button>
      </form>

      <div className="mt-4">
        {state === "loi" ? (
          <Alert
            tone="danger"
            title="Không tải được danh sách khóa học"
            action={
              <ButtonLink href="/v2/quan-tri/khoa-hoc" variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />}>
                Tải lại
              </ButtonLink>
            }
          >
            Mã lỗi để báo kỹ thuật: 8f2c1a7e.
          </Alert>
        ) : (
          <DataTable
            caption="Danh sách khóa học"
            columns={columns}
            rows={rows}
            rowKey={(c) => c.id}
            loadingRows={state === "dang-tai" ? 6 : undefined}
            density="compact"
            empty={
              q || status || grade || subjectId || teacherId ? (
                <EmptyState size="inline" icon={<IconSearch size={24} />} title="Không có khóa học khớp bộ lọc" action={<ButtonLink href={`/v2/quan-tri/khoa-hoc${roleQ ? `?${roleQ}` : ""}`} size="sm" variant="secondary">Xoá bộ lọc</ButtonLink>} headingLevel="h2" />
              ) : (
                <EmptyState
                  size="inline"
                  icon={<IconBookOpen size={24} />}
                  title={isStaff ? "Chưa có khóa học nào" : "Bạn chưa phụ trách khóa học nào"}
                  description="Tạo khóa học đầu tiên, thêm chương và bài rồi gửi Admin xuất bản."
                  action={<ButtonLink href="/v2/quan-tri/khoa-hoc/tao" size="sm" leadingIcon={<IconPlus size={16} />}>Tạo khóa học</ButtonLink>}
                  headingLevel="h2"
                />
              )
            }
          />
        )}
      </div>
      <Pagination className="mt-6" currentPage={1} lastPage={1} hrefFor={(p) => `/v2/quan-tri/khoa-hoc?page=${p}`} />
    </AdminPreviewShell>
  );
}
