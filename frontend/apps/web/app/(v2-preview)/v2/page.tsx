import Link from "next/link";
import {
  Alert,
  ButtonLink,
  CourseCard,
  IconArrowRight,
  IconFlag,
  IconListChecks,
  IconPlayCircle,
  IconRotateCcw,
  IconUsers,
} from "@vitaminvui/ui/v2";
import { CourseGridSkeleton } from "@/components/v2/catalog/CatalogSkeleton";
import { FounderPoster } from "@/components/v2/home/FounderPoster";
import { LessonPeek } from "@/components/v2/home/LessonPeek";
import { TeacherSection } from "@/components/v2/home/TeacherSection";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { catalogCourses, publicConfig } from "@/lib/mock/v2/catalog";
import { founderSample, founderSampleLong } from "@/lib/mock/v2/founder";
import { homeTeachers } from "@/lib/mock/v2/teachers";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const GRADES: Array<{ grade: number; note: string }> = [
  { grade: 6, note: "Số học, phân số" },
  { grade: 7, note: "Đại số, tam giác" },
  { grade: 8, note: "Đa thức, Ta-lét" },
  { grade: 9, note: "Ôn thi vào 10" },
  { grade: 10, note: "Hàm số, vectơ" },
  { grade: 11, note: "Lượng giác, dãy số" },
  { grade: 12, note: "Ôn thi THPT" },
];

// Câu chữ trung tính theo US-019 Q9 (mặc định của BA): không số liệu, không "học thử miễn phí".
const STEPS = [
  {
    icon: <IconPlayCircle size={24} />,
    title: "Xem video từng bài",
    body: "Mỗi bài là một video giải ví dụ từng bước. Xem đủ 90% thời lượng là bài tự đánh dấu hoàn thành.",
  },
  {
    icon: <IconListChecks size={24} />,
    title: "Làm trắc nghiệm ngay sau bài",
    body: "Biết ngay câu nào đúng, câu nào sai và đọc lời giải trước khi học bài tiếp theo.",
  },
  {
    icon: <IconFlag size={24} />,
    title: "Học tiếp đúng chỗ",
    body: "Lần sau mở khóa học, bạn được đưa thẳng tới bài đang học dở, không phải tìm lại.",
  },
];

type FeaturedState = "mac-dinh" | "it" | "rong" | "loi" | "dang-tai";
type TeacherState = "nhieu" | "mot" | "an";
type FounderState = "mac-dinh" | "khong-nut" | "dai" | "an";

/**
 * Trang chủ (US-019). Khối "Người sáng lập" (nội dung tĩnh, PO 2026-10-06) ngay sau khóa nổi bật, ẩn khi chưa có nội dung.
 * Khu vực giáo viên theo US-020, tự ẩn khi không có ai.
 */
export default async function HomePreview({ searchParams }: PageProps<"/v2">) {
  const sp = await searchParams;
  const featuredState = (one(sp.khoa) ?? "mac-dinh") as FeaturedState;
  const teacherState = (one(sp.gv) ?? "nhieu") as TeacherState;
  const founderState = (one(sp.nsl) ?? "mac-dinh") as FounderState;

  // Giả lập GET /courses?sort=featured, lấy 4 khóa đầu (BR3).
  const all = [catalogCourses[2], catalogCourses[0], catalogCourses[3], catalogCourses[8]].filter((c) => c !== undefined);
  const featured = featuredState === "it" ? all.slice(0, 2) : featuredState === "rong" ? [] : all;
  const teachers = teacherState === "an" ? [] : teacherState === "mot" ? homeTeachers.slice(0, 1) : homeTeachers;
  // TODO(dev): bản thật là hằng số tĩnh trong frontend (không API); chưa có nội dung từ PO → `null` để khối ẩn.
  const founder =
    founderState === "an"
      ? null
      : founderState === "khong-nut"
        ? { ...founderSample, action: undefined }
        : founderState === "dai"
          ? founderSampleLong
          : founderSample;

  const href = (k: string, g: string, n: string = founderState) => {
    const p = new URLSearchParams();
    if (k !== "mac-dinh") p.set("khoa", k);
    if (g !== "nhieu") p.set("gv", g);
    if (n !== "mac-dinh") p.set("nsl", n);
    const s = p.toString();
    return s ? `${routes.home}?${s}` : routes.home;
  };

  return (
    <StudentShell
      current="home"
      loggedIn={false}
      preview={
        <PreviewBar
          groups={[
            {
              label: "Khóa nổi bật",
              variants: [
                { label: "4 khóa", href: href("mac-dinh", teacherState), current: featuredState === "mac-dinh" },
                { label: "2 khóa", href: href("it", teacherState), current: featuredState === "it" },
                { label: "Chưa có", href: href("rong", teacherState), current: featuredState === "rong" },
                { label: "Lỗi", href: href("loi", teacherState), current: featuredState === "loi" },
                { label: "Đang tải", href: href("dang-tai", teacherState), current: featuredState === "dang-tai" },
              ],
            },
            {
              label: "Giáo viên",
              variants: [
                { label: "6 người", href: href(featuredState, "nhieu"), current: teacherState === "nhieu" },
                { label: "1 người", href: href(featuredState, "mot"), current: teacherState === "mot" },
                { label: "Ẩn (chưa có ai)", href: href(featuredState, "an"), current: teacherState === "an" },
              ],
            },
            {
              label: "Người sáng lập",
              variants: [
                { label: "Có nút", href: href(featuredState, teacherState, "mac-dinh"), current: founderState === "mac-dinh" },
                { label: "Không nút", href: href(featuredState, teacherState, "khong-nut"), current: founderState === "khong-nut" },
                { label: "Câu dài", href: href(featuredState, teacherState, "dai"), current: founderState === "dai" },
                { label: "Ẩn (chưa có nội dung)", href: href(featuredState, teacherState, "an"), current: founderState === "an" },
              ],
            },
          ]}
        />
      }
    >
      {/* Hero: điểm nhấn duy nhất — trang vở ô ly có lề đỏ. Không có nút "Học thử" gắn cứng (US-019 BR6/Q8). */}
      <section aria-labelledby="hero-title" className="bg-oly relative overflow-hidden border-b border-line bg-paper">
        <span aria-hidden="true" className="absolute inset-y-0 left-6 hidden w-0.5 bg-margin sm:block lg:left-12" />
        <div className="mx-auto grid max-w-6xl items-center gap-10 px-4 py-10 sm:px-10 md:py-16 lg:grid-cols-[1.1fr_1fr] lg:px-16">
          <div className="flex flex-col gap-5">
            <h1 id="hero-title" className="text-display font-extrabold tracking-heading text-ink md:text-display-lg">
              Học Toán lớp 6–12 theo từng bài, hiểu tới đâu chắc tới đó.
            </h1>
            <p className="max-w-xl text-lg leading-relaxed text-ink-soft">
              Video bài giảng theo từng bài, trắc nghiệm ngay sau mỗi bài, tiến độ được lưu để học tiếp đúng chỗ.
            </p>
            <div className="flex flex-col gap-3 sm:flex-row">
              <ButtonLink href={routes.catalog} size="lg" trailingIcon={<IconArrowRight size={18} />}>
                Xem khóa học
              </ButtonLink>
              <ButtonLink href="#grade-title" size="lg" variant="ghost">
                Chọn lớp của bạn
              </ButtonLink>
            </div>
          </div>
          <LessonPeek />
        </div>
      </section>

      <section aria-labelledby="grade-title" className="mx-auto max-w-6xl scroll-mt-20 px-4 py-12 sm:px-6">
        <h2 id="grade-title" className="scroll-mt-24 text-heading font-extrabold tracking-heading text-ink md:text-heading-lg">
          Bạn đang học lớp mấy?
        </h2>
        <p className="mt-1 text-base text-ink-soft">Chọn lớp để xem các khóa học của lớp đó.</p>
        <ul className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
          {GRADES.map((g) => (
            <li key={g.grade}>
              <Link
                href={routes.catalogQuery({ grade: g.grade })}
                className="focus-ring group flex h-full flex-col gap-1 rounded-card border border-line bg-surface p-4 transition-colors duration-150 hover:border-primary"
              >
                <span className="text-sm font-medium text-ink-soft">Lớp</span>
                <span className="num text-title-lg font-extrabold leading-none text-ink group-hover:text-primary">{g.grade}</span>
                <span className="mt-1 text-sm text-ink-soft">{g.note}</span>
              </Link>
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="featured-title">
        <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 id="featured-title" className="text-heading font-extrabold tracking-heading text-ink md:text-heading-lg">
                Khóa học nổi bật
              </h2>
              <p className="mt-1 text-base text-ink-soft">Do đội ngũ VitaminVui chọn.</p>
            </div>
            <Link href={routes.catalogQuery({ sort: "featured" })} className="focus-ring inline-flex min-h-11 items-center gap-1 rounded font-semibold text-primary hover:underline">
              Xem tất cả khóa học
              <IconArrowRight size={18} />
            </Link>
          </div>
          <div className="mt-6">
            {featuredState === "dang-tai" ? (
              <CourseGridSkeleton count={4} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" />
            ) : featuredState === "loi" ? (
              <Alert
                tone="danger"
                title="Không tải được khóa học nổi bật"
                action={
                  <ButtonLink href={href("mac-dinh", teacherState)} variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />}>
                    Tải lại
                  </ButtonLink>
                }
              >
                Các phần khác của trang vẫn dùng được. Bạn có thể xem toàn bộ khóa học ở trang danh mục.
              </Alert>
            ) : featured.length === 0 ? (
              <p className="rounded-card border border-dashed border-line-strong px-4 py-6 text-base text-ink">
                Khóa học sẽ sớm được cập nhật.{" "}
                <Link href={routes.catalog} className="focus-ring rounded font-semibold text-primary underline underline-offset-4">
                  Xem danh mục khóa học
                </Link>
              </p>
            ) : (
              <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {featured.map((c) => (
                  <li key={c.id}>
                    <CourseCard course={c} href={routes.course(c.slug)} paidCheckoutEnabled={publicConfig.paid_checkout_enabled} className="h-full" />
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </section>

      {founder ? <FounderPoster {...founder} /> : null}

      <TeacherSection teachers={teachers} />

      <section aria-labelledby="steps-title" className={`mx-auto max-w-6xl px-4 pb-12 sm:px-6 ${teachers.length === 0 ? "pt-12" : "pt-4"}`}>
        <h2 id="steps-title" className="text-heading font-extrabold tracking-heading text-ink md:text-heading-lg">
          Một buổi học trên VitaminVui
        </h2>
        <ol className="mt-6 grid gap-6 md:grid-cols-3">
          {STEPS.map((s, i) => (
            <li key={s.title} className="flex gap-4">
              <span className="num flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-primary text-base font-extrabold text-primary">
                {i + 1}
              </span>
              <div className="flex flex-col gap-1">
                <h3 className="flex items-center gap-2 text-lg font-semibold text-ink">
                  <span className="text-primary">{s.icon}</span>
                  {s.title}
                </h3>
                <p className="text-base text-ink-soft">{s.body}</p>
              </div>
            </li>
          ))}
        </ol>
      </section>

      {/* Q10: ẩn ý "phụ huynh nhận email xác nhận" khi cờ FEATURE_PARENT_CONSENT_ENFORCED còn tắt. */}
      <section aria-labelledby="parent-title" className="mx-auto max-w-6xl px-4 pb-14 sm:px-6">
        <div className="rounded-sheet border border-line bg-surface p-6 sm:p-8">
          <h2 id="parent-title" className="text-heading font-extrabold tracking-heading text-ink">
            Dành cho phụ huynh
          </h2>
          <ul className="mt-5 grid gap-5 md:grid-cols-2">
            <li className="flex gap-3">
              <IconUsers className="mt-0.5 text-primary" />
              <div>
                <p className="font-semibold text-ink">Một tài khoản, một thiết bị</p>
                <p className="text-base text-ink-soft">Mỗi lúc chỉ đăng nhập được trên một thiết bị, tránh dùng chung tài khoản.</p>
              </div>
            </li>
            <li className="flex gap-3">
              <IconListChecks className="mt-0.5 text-primary" />
              <div>
                <p className="font-semibold text-ink">Tiến độ rõ ràng</p>
                <p className="text-base text-ink-soft">Xem được bài đã học và điểm trắc nghiệm cao nhất của từng khóa.</p>
              </div>
            </li>
          </ul>
        </div>
      </section>
    </StudentShell>
  );
}
