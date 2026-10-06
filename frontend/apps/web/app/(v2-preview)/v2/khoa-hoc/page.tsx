import Link from "next/link";
import {
  Alert,
  Breadcrumb,
  ButtonLink,
  CourseCard,
  EmptyState,
  IconBookOpen,
  IconRotateCcw,
  IconSearch,
  IconX,
  Pagination,
  TextInput,
  cx,
} from "@vitaminvui/ui/v2";
import { CatalogFilters, SORT_LABELS, SortSelect, type CatalogQuery, type SortKey } from "@/components/v2/catalog/CatalogFilters";
import { CourseGridSkeleton } from "@/components/v2/catalog/CatalogSkeleton";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { catalogCourses, publicConfig, subjects } from "@/lib/mock/v2/catalog";
import { many, one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const GRADE_INTRO: Record<number, string> = {
  9: "Các khóa bám sát chương trình Toán 9 và luyện đề thi vào lớp 10: phương trình, căn thức, hình học đường tròn.",
  12: "Ôn tập Toán 12 theo cấu trúc đề tốt nghiệp THPT: đạo hàm, khảo sát hàm số, xác suất – thống kê.",
};

function normalize(text: string) {
  return text.normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/đ/g, "d").toLowerCase();
}

export default async function CatalogPreview({ searchParams }: PageProps<"/v2/khoa-hoc">) {
  const sp = await searchParams;
  const state = one(sp["trang-thai"]);
  const gradeRaw = Number(one(sp.grade));
  const grade = publicConfig.grades.includes(gradeRaw) ? gradeRaw : undefined;
  const subjectIds = many(sp.subject_ids).map(Number).filter((id) => subjects.some((s) => s.id === id));
  const sortRaw = one(sp.sort) as SortKey | undefined;
  const sort: SortKey = sortRaw && sortRaw in SORT_LABELS ? sortRaw : "newest";
  const q = (one(sp.q) ?? "").slice(0, 100);
  const query: CatalogQuery = { grade, subject_ids: subjectIds, q: q || undefined, sort };

  // Giả lập GET /courses: subject_ids là OR, q bỏ dấu + mọi từ phải khớp (T10).
  const words = normalize(q).split(/\s+/).filter(Boolean).slice(0, 8);
  let results = catalogCourses.filter(
    (c) =>
      (grade === undefined || c.grade_level === grade) &&
      (subjectIds.length === 0 || c.subjects.some((s) => subjectIds.includes(s.id))) &&
      words.every((w) => normalize(`${c.title} ${c.short_description ?? ""}`).includes(w)),
  );
  if (sort === "popular") results = [...results].sort((a, b) => b.enrollments_count - a.enrollments_count);
  else if (sort === "newest") results = [...results].sort((a, b) => b.published_at.localeCompare(a.published_at));
  if (state === "rong") results = [];

  const hasFilter = grade !== undefined || subjectIds.length > 0 || q !== "";
  const title = grade ? `Khóa học Toán lớp ${grade}` : "Khóa học Toán lớp 6–12";
  const base = { grade, subject_ids: subjectIds, q: q || undefined, sort: sort === "newest" ? undefined : sort };

  const variants = [
    { label: "Mặc định", href: routes.catalog, current: !state && !hasFilter },
    { label: "Lớp 9", href: routes.catalogQuery({ grade: 9 }), current: !state && grade === 9 && subjectIds.length === 0 },
    { label: "Lọc không khớp", href: routes.catalogQuery({ grade: 7, subject_ids: [3] }), current: grade === 7 && subjectIds.includes(3) },
    { label: "Đang tải", href: routes.catalogQuery({ "trang-thai": "dang-tai" }), current: state === "dang-tai" },
    { label: "Chưa có khóa", href: routes.catalogQuery({ "trang-thai": "rong" }), current: state === "rong" },
    { label: "Lỗi tải", href: routes.catalogQuery({ "trang-thai": "loi" }), current: state === "loi" },
  ];

  return (
    <StudentShell current="catalog" loggedIn={false} searchDefault={q} preview={<PreviewBar variants={variants} />}>
      <div className="mx-auto max-w-6xl px-4 pb-14 pt-6 sm:px-6">
        <Breadcrumb items={grade ? [{ label: "Trang chủ", href: routes.home }, { label: "Khóa học", href: routes.catalog }, { label: `Lớp ${grade}` }] : [{ label: "Trang chủ", href: routes.home }, { label: "Khóa học" }]} />
        <h1 className="mt-3 text-title font-extrabold tracking-heading text-ink md:text-title-lg">{title}</h1>
        {grade && GRADE_INTRO[grade] ? <p className="mt-2 max-w-2xl text-base text-ink-soft">{GRADE_INTRO[grade]}</p> : null}
        {!publicConfig.paid_checkout_enabled ? (
          <p className="mt-3 max-w-2xl text-sm text-ink-soft">
            Thanh toán trực tuyến đang tạm đóng. Bạn vẫn xem được bài học thử và đăng ký các khóa miễn phí.
          </p>
        ) : null}

        {/* Tìm kiếm: form GET giữ nguyên bộ lọc đang chọn, chạy được cả khi chưa tải JS. */}
        <form action={routes.catalog} role="search" className="mt-5 flex gap-2">
          {grade ? <input type="hidden" name="grade" value={grade} /> : null}
          {subjectIds.map((id) => (
            <input key={id} type="hidden" name="subject_ids" value={id} />
          ))}
          {sort !== "newest" ? <input type="hidden" name="sort" value={sort} /> : null}
          <label htmlFor="catalog-q" className="sr-only">
            Tìm khóa học
          </label>
          <TextInput id="catalog-q" name="q" type="search" defaultValue={q} maxLength={100} placeholder="Tìm theo tên khóa, ví dụ: tứ giác nội tiếp" leadingIcon={<IconSearch size={18} />} className="md:max-w-xl" />
          <button type="submit" className="focus-ring h-11 shrink-0 rounded-control bg-primary px-4 font-semibold text-on-primary hover:bg-primary-hover">
            Tìm
          </button>
        </form>

        {/* Chọn lớp: chip cuộn ngang trên mobile, đổi là điều hướng ngay. */}
        <nav aria-label="Chọn lớp" className="-mx-4 mt-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
          <ul className="flex gap-2 pb-1">
            <li>
              <Link
                href={routes.catalogQuery({ ...base, grade: undefined })}
                aria-current={grade === undefined ? "page" : undefined}
                className={cx(
                  "focus-ring inline-flex h-11 items-center whitespace-nowrap rounded-full border px-4 text-base font-semibold",
                  grade === undefined ? "border-primary bg-primary text-on-primary" : "border-line-strong bg-surface text-ink hover:border-primary",
                )}
              >
                Tất cả lớp
              </Link>
            </li>
            {publicConfig.grades.map((g) => (
              <li key={g}>
                <Link
                  href={routes.catalogQuery({ ...base, grade: g })}
                  aria-current={grade === g ? "page" : undefined}
                  className={cx(
                    "focus-ring inline-flex h-11 items-center whitespace-nowrap rounded-full border px-4 text-base font-semibold",
                    grade === g ? "border-primary bg-primary text-on-primary" : "border-line-strong bg-surface text-ink hover:border-primary",
                  )}
                >
                  Lớp {g}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        <div className="mt-6 grid gap-8 lg:grid-cols-[220px_1fr]">
          <aside aria-label="Lọc theo chuyên đề" className="hidden lg:block">
            <div className="sticky top-24">
              <CatalogFilters subjects={subjects} query={query} variant="sidebar" />
            </div>
          </aside>

          <section aria-labelledby="result-title" className="min-w-0">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 id="result-title" className="text-base font-semibold text-ink" aria-live="polite">
                {state === "dang-tai" || state === "loi" ? "Khóa học" : `${results.length} khóa học`}
              </h2>
              <div className="flex items-center gap-2">
                <div className="lg:hidden">
                  <CatalogFilters subjects={subjects} query={query} variant="sheet" />
                </div>
                <div className="hidden lg:block">
                  <SortSelect query={query} />
                </div>
              </div>
            </div>

            {hasFilter ? (
              <ul aria-label="Bộ lọc đang chọn" className="mt-3 flex flex-wrap gap-2">
                {subjectIds.map((id) => (
                  <li key={id}>
                    <Link
                      href={routes.catalogQuery({ ...base, subject_ids: subjectIds.filter((x) => x !== id) })}
                      className="focus-ring inline-flex min-h-9 items-center gap-1 rounded-full bg-primary-soft px-3 text-sm font-semibold text-primary hover:bg-primary hover:text-on-primary"
                      aria-label={`Bỏ lọc ${subjects.find((s) => s.id === id)?.name}`}
                    >
                      {subjects.find((s) => s.id === id)?.name}
                      <IconX size={14} />
                    </Link>
                  </li>
                ))}
                {q ? (
                  <li>
                    <Link
                      href={routes.catalogQuery({ ...base, q: undefined })}
                      className="focus-ring inline-flex min-h-9 items-center gap-1 rounded-full bg-primary-soft px-3 text-sm font-semibold text-primary hover:bg-primary hover:text-on-primary"
                      aria-label={`Bỏ từ khóa ${q}`}
                    >
                      “{q}”
                      <IconX size={14} />
                    </Link>
                  </li>
                ) : null}
                <li>
                  <Link href={routes.catalog} className="focus-ring inline-flex min-h-9 items-center rounded px-2 text-sm font-semibold text-ink-soft underline underline-offset-4 hover:text-primary">
                    Xoá bộ lọc
                  </Link>
                </li>
              </ul>
            ) : null}

            <div className="mt-4">
              {state === "dang-tai" ? (
                <CourseGridSkeleton />
              ) : state === "loi" ? (
                <Alert
                  tone="danger"
                  title="Không tải được danh sách khóa học"
                  action={
                    <ButtonLink href={routes.catalog} variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />}>
                      Tải lại
                    </ButtonLink>
                  }
                >
                  Kiểm tra kết nối mạng rồi thử lại. Nếu vẫn lỗi, hãy quay lại sau ít phút.
                </Alert>
              ) : results.length === 0 && hasFilter ? (
                <EmptyState
                  icon={<IconSearch size={32} />}
                  title="Không tìm thấy khóa học phù hợp"
                  description="Hãy thử bỏ bớt chuyên đề, chọn lớp khác hoặc tìm bằng từ khóa ngắn hơn."
                  action={<ButtonLink href={routes.catalog}>Xoá bộ lọc</ButtonLink>}
                />
              ) : results.length === 0 ? (
                <EmptyState icon={<IconBookOpen size={32} />} title="Chưa có khóa học nào" description="Các khóa học đang được chuẩn bị. Quay lại sau nhé!" action={<ButtonLink href={routes.home} variant="secondary">Về trang chủ</ButtonLink>} />
              ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                  {results.map((c) => (
                    <CourseCard key={c.id} course={c} href={routes.course(c.slug)} headingLevel="h3" paidCheckoutEnabled={publicConfig.paid_checkout_enabled} />
                  ))}
                </div>
              )}
            </div>

            {/* 25 khóa/trang (api-contract §1.5); ẩn khi chỉ có 1 trang như dữ liệu mẫu này. */}
            <Pagination currentPage={1} lastPage={1} hrefFor={(p) => routes.catalogQuery({ ...base, page: p })} className="mt-8" />
          </section>
        </div>
      </div>
    </StudentShell>
  );
}
