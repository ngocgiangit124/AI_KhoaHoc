import type { Metadata } from "next";
import { headers } from "next/headers";
import Link from "next/link";
import { ButtonLink, IconArrowRight, IconFlag, IconListChecks, IconPlayCircle, IconUsers } from "@vitaminvui/ui/v2";
import { FeaturedCourses } from "@/components/home/FeaturedCourses";
import { HomeTeachers } from "@/components/home/HomeTeachers";
import { JsonLd } from "@/components/seo/JsonLd";
import { FounderPoster } from "@/components/v2/home/FounderPoster";
import { LessonPeek } from "@/components/v2/home/LessonPeek";
import { absoluteUrl } from "@/lib/catalog/seo";
import { fetchFeaturedCourses, fetchHomeTeachers, fetchPaidCheckoutEnabled } from "@/lib/home/api";
import { founderPoster } from "@/lib/home/founder";
import { settle } from "@/lib/home/settle";
import { routes } from "@/lib/routes";

/**
 * Trang chủ thật (US-019 FW8 + US-020 FW9). Bố cục/câu chữ theo bản xem trước `/v2` (design-system-v2 §12.6–12.7).
 *
 * ADR-004 §2.7: CSP nonce -> render động (không ISR/PPR); dữ liệu cache ở Data Cache của `publicFetchServer`
 * (`revalidate: 60`; tag `catalog` cho khóa nổi bật, `teachers` cho giáo viên). Mỗi khu vực tự bắt lỗi (`settle`):
 * khóa nổi bật lỗi -> thông báo + "Tải lại" tại chỗ; giáo viên lỗi/rỗng -> không render gì; poster là hằng số tĩnh.
 */
export const dynamic = "force-dynamic";

const TITLE = "VitaminVui — Học Toán lớp 6–12 theo từng bài";
const DESCRIPTION =
  "Học Toán trực tuyến lớp 6 đến lớp 12 trên VitaminVui: video bài giảng theo từng bài, trắc nghiệm ngay sau mỗi bài, tiến độ được lưu để học tiếp đúng chỗ.";

export const metadata: Metadata = {
  title: { absolute: TITLE },
  description: DESCRIPTION,
  alternates: { canonical: "/" },
  openGraph: { title: TITLE, description: DESCRIPTION, type: "website", locale: "vi_VN", siteName: "VitaminVui", url: "/" },
};

const GRADES: Array<{ grade: number; note: string }> = [
  { grade: 6, note: "Số học, phân số" },
  { grade: 7, note: "Đại số, tam giác" },
  { grade: 8, note: "Đa thức, Ta-lét" },
  { grade: 9, note: "Ôn thi vào 10" },
  { grade: 10, note: "Hàm số, vectơ" },
  { grade: 11, note: "Lượng giác, dãy số" },
  { grade: 12, note: "Ôn thi THPT" },
];

// Câu chữ trung tính theo US-019 Q9/Q10 (không số liệu, không "học thử miễn phí", không ý email phụ huynh).
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

export default async function HomePage() {
  const nonce = (await headers()).get("x-nonce") ?? undefined;

  // Ba nguồn độc lập, gọi song song; lỗi/quá chậm của nguồn nào chỉ ảnh hưởng khu vực đó.
  const [featured, teachers, paid] = await Promise.all([
    settle(fetchFeaturedCourses()),
    settle(fetchHomeTeachers()),
    settle(fetchPaidCheckoutEnabled()),
  ]);
  const teacherRows = teachers.ok ? teachers.value : null;
  const hasTeachers = (teacherRows?.length ?? 0) > 0;

  return (
    <>
      <JsonLd
        nonce={nonce}
        data={{
          "@context": "https://schema.org",
          "@type": "Organization",
          name: "VitaminVui",
          url: absoluteUrl("/"),
          description: DESCRIPTION,
        }}
      />

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
                href={`${routes.catalog}?grade=${g.grade}`}
                className="focus-ring group flex h-full min-h-11 flex-col gap-1 rounded-card border border-line bg-surface p-4 transition-colors duration-150 hover:border-primary"
              >
                <span className="text-base font-medium text-ink-soft">Lớp</span>
                <span className="num text-title-lg font-extrabold leading-none text-ink group-hover:text-primary">{g.grade}</span>
                <span className="mt-1 text-base text-ink-soft">{g.note}</span>
              </Link>
            </li>
          ))}
        </ul>
      </section>

      <FeaturedCourses courses={featured.ok ? featured.value : null} paidCheckoutEnabled={paid.ok ? paid.value : false} />

      {/* Poster (US-019 BR10): hằng số tĩnh, không API -> không phụ thuộc lỗi của khóa nổi bật. null/thiếu mục -> không render. */}
      {founderPoster ? <FounderPoster {...founderPoster} /> : null}

      {/* Khu giáo viên (US-020): rỗng/lỗi -> không render gì. */}
      <HomeTeachers rows={teacherRows} />

      <section aria-labelledby="steps-title" className={`mx-auto max-w-6xl px-4 pb-12 sm:px-6 ${hasTeachers ? "pt-4" : "pt-12"}`}>
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
    </>
  );
}
