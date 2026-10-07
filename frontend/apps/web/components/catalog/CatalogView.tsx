import Link from "next/link";
import {
  Breadcrumb,
  ButtonLink,
  CourseCard,
  EmptyState,
  IconBookOpen,
  IconSearch,
  IconX,
  Pagination,
  TextInput,
  cx,
  type BreadcrumbItem,
} from "@vitaminvui/ui/v2";
import type { CourseList, Subject } from "@/lib/catalog/schemas";
import { GRADES, MAX_Q_LENGTH, hasActiveFilters, toPageHref, type CatalogQuery } from "@/lib/catalog/query";
import { routes } from "@/lib/routes";
import { CatalogFilters, SortSelect } from "./CatalogFilters";
import { CourseImage } from "./CourseImage";

export interface CatalogViewProps {
  basePath: string;
  query: CatalogQuery;
  fixedGrade: number | null;
  subjects: Subject[];
  courses: CourseList;
  title: string;
  intro: string;
  breadcrumb: BreadcrumbItem[];
  /** `paid_checkout_enabled` của `/config/public`. */
  paidCheckoutEnabled: boolean;
}

const CHIP = "focus-ring inline-flex h-11 items-center whitespace-nowrap rounded-full border px-4 text-base font-semibold";
const REMOVABLE = "focus-ring inline-flex min-h-11 items-center gap-1 rounded-full bg-primary-soft px-3 text-sm font-semibold text-primary hover:bg-primary hover:text-on-primary";

/**
 * Giao diện chung của `/khoa-hoc` và `/lop-{grade}` (Server Component), theo màn danh mục của designer
 * (`(v2-preview)/v2/khoa-hoc`) với dữ liệu thật. Tìm kiếm là form GET (chạy cả khi chưa tải JS); lớp là liên kết;
 * chuyên đề + sắp xếp là Client Component đẩy lên URL.
 */
export function CatalogView({ basePath, query, fixedGrade, subjects, courses, title, intro, breadcrumb, paidCheckoutEnabled }: CatalogViewProps) {
  const omitGrade = fixedGrade !== null;
  const filtered = hasActiveFilters(query, omitGrade);
  const { meta, data } = courses;
  const href = (next: Partial<CatalogQuery>) => toPageHref(basePath, { ...query, ...next, page: 1 }, omitGrade);
  const resetHref = href({ grade: omitGrade ? query.grade : null, subjectIds: [], teacherId: null, q: "" });
  // Tên giáo viên lấy từ `teachers[]` của kết quả (api-contract: GET /courses không có endpoint riêng cho tên).
  const teacherName =
    query.teacherId === null ? null : (data.flatMap((c) => c.teachers).find((t) => t.id === query.teacherId)?.name ?? null);
  const subjectName = (id: number) => subjects.find((s) => s.id === id)?.name ?? `Chuyên đề ${id}`;

  return (
    <div className="mx-auto w-full max-w-6xl px-4 pb-14 pt-6 sm:px-6">
      <Breadcrumb items={breadcrumb} />
      <h1 className="mt-3 text-title font-extrabold tracking-heading text-ink md:text-title-lg">{title}</h1>
      <p className="mt-2 max-w-2xl text-base text-ink-soft">{intro}</p>
      {!paidCheckoutEnabled ? (
        <p className="mt-3 max-w-2xl text-sm text-ink-soft">
          Thanh toán trực tuyến đang tạm đóng. Bạn vẫn xem được bài học thử và đăng ký các khóa miễn phí.
        </p>
      ) : null}

      {/* Tìm kiếm: form GET giữ nguyên bộ lọc đang chọn, chạy được cả khi chưa tải JS. */}
      <form action={basePath} role="search" className="mt-5 flex gap-2">
        {!omitGrade && query.grade !== null ? <input type="hidden" name="grade" value={query.grade} /> : null}
        {query.subjectIds.map((id) => (
          <input key={id} type="hidden" name="subject_ids" value={id} />
        ))}
        {query.teacherId !== null ? <input type="hidden" name="teacher_id" value={query.teacherId} /> : null}
        {query.sort !== "newest" ? <input type="hidden" name="sort" value={query.sort} /> : null}
        <label htmlFor="catalog-q" className="sr-only">
          Tìm trong danh mục khóa học
        </label>
        <TextInput
          id="catalog-q"
          name="q"
          type="search"
          defaultValue={query.q}
          maxLength={MAX_Q_LENGTH}
          placeholder="Tìm theo tên khóa, ví dụ: tứ giác nội tiếp"
          leadingIcon={<IconSearch size={18} />}
          className="md:max-w-xl"
        />
        <button type="submit" className="focus-ring h-11 shrink-0 rounded-control bg-primary px-4 font-semibold text-on-primary hover:bg-primary-hover">
          Tìm
        </button>
      </form>

      {/* Chọn lớp: chip cuộn ngang trên mobile. Ở /lop-{n} lớp cố định theo đường dẫn nên chỉ có liên kết đổi lớp. */}
      {omitGrade ? (
        <p className="mt-4">
          <Link href={routes.catalog} className="focus-ring inline-flex min-h-11 items-center rounded px-1 font-semibold text-primary underline underline-offset-4">
            Xem lớp khác
          </Link>
        </p>
      ) : (
        <nav aria-label="Chọn lớp" className="-mx-4 mt-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
          <ul className="flex gap-2 pb-1">
            <li>
              <Link
                href={href({ grade: null })}
                aria-current={query.grade === null ? "page" : undefined}
                className={cx(CHIP, query.grade === null ? "border-primary bg-primary text-on-primary" : "border-line-strong bg-surface text-ink hover:border-primary")}
              >
                Tất cả lớp
              </Link>
            </li>
            {GRADES.map((g) => (
              <li key={g}>
                <Link
                  href={href({ grade: g })}
                  aria-current={query.grade === g ? "page" : undefined}
                  className={cx(CHIP, query.grade === g ? "border-primary bg-primary text-on-primary" : "border-line-strong bg-surface text-ink hover:border-primary")}
                >
                  Lớp {g}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      )}

      <div className="mt-6 grid gap-8 lg:grid-cols-[220px_1fr]">
        <aside aria-label="Lọc theo chuyên đề" className="hidden lg:block">
          <div className="sticky top-24">
            <CatalogFilters variant="sidebar" basePath={basePath} query={query} subjects={subjects} omitGrade={omitGrade} />
          </div>
        </aside>

        <section aria-labelledby="result-title" className="min-w-0">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 id="result-title" className="text-base font-semibold text-ink" aria-live="polite">
              {meta.total} khóa học
            </h2>
            <div className="flex items-center gap-2">
              <div className="lg:hidden">
                <CatalogFilters variant="sheet" basePath={basePath} query={query} subjects={subjects} omitGrade={omitGrade} />
              </div>
              <div className="hidden lg:block">
                <SortSelect basePath={basePath} query={query} omitGrade={omitGrade} />
              </div>
            </div>
          </div>

          {filtered && data.length > 0 ? (
            <ul aria-label="Bộ lọc đang chọn" className="mt-3 flex flex-wrap gap-2">
              {query.subjectIds.map((id) => (
                <li key={id}>
                  <Link href={href({ subjectIds: query.subjectIds.filter((x) => x !== id) })} className={REMOVABLE}>
                    <span className="sr-only">Bỏ lọc </span>
                    {subjectName(id)}
                    <IconX size={14} />
                  </Link>
                </li>
              ))}
              {query.teacherId !== null ? (
                <li>
                  <Link href={href({ teacherId: null })} className={REMOVABLE}>
                    <span className="sr-only">Bỏ lọc </span>
                    Giáo viên: {teacherName ?? "đã chọn"}
                    <IconX size={14} />
                  </Link>
                </li>
              ) : null}
              {query.q ? (
                <li>
                  <Link href={href({ q: "" })} className={REMOVABLE}>
                    <span className="sr-only">Bỏ từ khóa </span>
                    “{query.q}”
                    <IconX size={14} />
                  </Link>
                </li>
              ) : null}
              <li>
                <Link href={resetHref} className="focus-ring inline-flex min-h-11 items-center rounded px-2 text-sm font-semibold text-ink-soft underline underline-offset-4 hover:text-primary">
                  Xoá bộ lọc
                </Link>
              </li>
            </ul>
          ) : null}

          <div className="mt-4">
            {data.length === 0 && filtered ? (
              <EmptyState
                icon={<IconSearch size={32} />}
                title="Không tìm thấy khóa học phù hợp"
                description="Hãy thử bỏ bớt chuyên đề, chọn lớp khác hoặc tìm bằng từ khóa ngắn hơn."
                action={<ButtonLink href={resetHref}>Xoá bộ lọc</ButtonLink>}
              />
            ) : data.length === 0 ? (
              <EmptyState
                icon={<IconBookOpen size={32} />}
                title="Chưa có khóa học nào"
                description="Các khóa học đang được chuẩn bị. Quay lại sau nhé!"
                action={
                  <ButtonLink href={routes.home} variant="secondary">
                    Về trang chủ
                  </ButtonLink>
                }
              />
            ) : (
              <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {data.map((course, i) => (
                  <li key={course.id} className="flex min-w-0">
                    <CourseCard
                      course={course}
                      href={routes.course(course.slug)}
                      headingLevel="h3"
                      className="w-full"
                      paidCheckoutEnabled={paidCheckoutEnabled}
                      image={course.thumbnail_url ? <CourseImage url={course.thumbnail_url} priority={i < 2} /> : undefined}
                    />
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* 25 khóa/trang (api-contract §1.5). Liên kết thật: SEO + chạy khi chưa có JS. */}
          <Pagination currentPage={meta.current_page} lastPage={meta.last_page} hrefFor={(page) => toPageHref(basePath, { ...query, page }, omitGrade)} className="mt-8" />
        </section>
      </div>
    </div>
  );
}
