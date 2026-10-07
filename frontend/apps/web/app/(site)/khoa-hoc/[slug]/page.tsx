import type { Metadata } from "next";
import { headers } from "next/headers";
import { notFound } from "next/navigation";
import {
  Badge,
  Breadcrumb,
  CourseCover,
  IconClock,
  IconListChecks,
  IconPlayCircle,
  IconUsers,
  formatCount,
  formatDurationLong,
} from "@vitaminvui/ui/v2";
import { Collapsible } from "@/components/catalog/Collapsible";
import { CourseAction, PriceLine } from "@/components/catalog/CourseAction";
import { CourseCtaProvider } from "@/components/catalog/CourseCtaProvider";
import { CourseDescription } from "@/components/catalog/CourseDescription";
import { CourseImage } from "@/components/catalog/CourseImage";
import { CourseOutline } from "@/components/catalog/CourseOutline";
import { TeacherList } from "@/components/catalog/TeacherList";
import { JsonLd } from "@/components/seo/JsonLd";
import { fetchCourse, fetchPublicConfig } from "@/lib/catalog/api";
import { courseJsonLd, truncateDescription } from "@/lib/catalog/seo";
import { routes } from "@/lib/routes";

/** ADR-004 §2.7: render động + nonce; dữ liệu cache ở Data Cache (revalidate 60, tag `catalog`). */
export const dynamic = "force-dynamic";

export async function generateMetadata({ params }: PageProps<"/khoa-hoc/[slug]">): Promise<Metadata> {
  let course;
  try {
    course = await fetchCourse((await params).slug);
  } catch {
    return { title: "Khóa học" }; // API bận: layout đã hiện "Hệ thống đang bận"
  }
  if (!course) return { title: "Không tìm thấy khóa học" };
  const description = truncateDescription(
    course.short_description || `Khóa học Toán lớp ${course.grade_level}: ${course.title} trên VitaminVui.`,
  );
  const url = `/khoa-hoc/${course.slug}`;
  return {
    title: course.title,
    description,
    alternates: { canonical: url },
    openGraph: {
      title: course.title,
      description,
      type: "website",
      url,
      locale: "vi_VN",
      ...(course.thumbnail_url ? { images: [{ url: course.thumbnail_url }] } : {}),
    },
  };
}

/**
 * Chi tiết khóa học theo màn của designer (`(v2-preview)/v2/khoa-hoc/[slug]`) với dữ liệu thật. Nội dung công khai render ở
 * server; hành động chính (`CourseAction`) phụ thuộc người xem nên chạy phía client sau `/auth/me` + `viewer-state`.
 */
export default async function CourseDetailPage({ params }: PageProps<"/khoa-hoc/[slug]">) {
  const [course, config] = await Promise.all([fetchCourse((await params).slug), fetchPublicConfig()]);
  if (!course) notFound();

  const nonce = (await headers()).get("x-nonce") ?? undefined;
  const free = course.is_free || course.price === 0;
  const paid = config.paid_checkout_enabled;
  const subjectSlug = course.subjects[0]?.slug;
  const image = course.thumbnail_url ? <CourseImage url={course.thumbnail_url} sizes="(min-width: 1024px) 360px, 100vw" /> : undefined;

  const facts = [
    { icon: <IconPlayCircle size={18} />, text: `${course.lessons_count} bài học · ${formatDurationLong(course.total_duration_seconds)}` },
    ...(course.has_preview ? [{ icon: <IconListChecks size={18} />, text: "Có bài học thử miễn phí" }] : []),
    {
      icon: <IconUsers size={18} />,
      text: course.enrollments_count > 0 ? `${formatCount(course.enrollments_count)} học sinh đã đăng ký` : "Chưa có học sinh đăng ký",
    },
  ];

  return (
    <CourseCtaProvider course={{ id: course.id, slug: course.slug, isFree: free, paidCheckoutEnabled: paid }}>
      <JsonLd data={courseJsonLd(course, paid)} nonce={nonce} />
      <div className="mx-auto max-w-6xl px-4 pb-32 pt-6 sm:px-6 lg:pb-16">
        <Breadcrumb
          items={[
            { label: "Trang chủ", href: routes.home },
            { label: "Khóa học", href: routes.catalog },
            { label: `Lớp ${course.grade_level}`, href: routes.grade(course.grade_level) },
            { label: course.title },
          ]}
        />

        <div className="mt-4 grid gap-8 lg:grid-cols-[1fr_360px]">
          <div className="flex min-w-0 flex-col gap-8">
            <header className="flex flex-col gap-4">
              <div className="overflow-hidden rounded-card border border-line lg:hidden">
                <CourseCover title={course.title} gradeLevel={course.grade_level} subjectSlug={subjectSlug} size="hero" image={image} />
              </div>
              <div className="flex flex-wrap gap-2">
                <Badge tone="primary">Lớp {course.grade_level}</Badge>
                {course.subjects.map((s) => (
                  <Badge key={s.id}>{s.name}</Badge>
                ))}
                {free ? <Badge tone="free">Miễn phí</Badge> : null}
              </div>
              <h1 className="text-title font-extrabold tracking-heading text-ink md:text-title-lg">{course.title}</h1>
              {course.short_description ? <p className="max-w-2xl text-lg leading-relaxed text-ink-soft">{course.short_description}</p> : null}
              <ul className="flex flex-col gap-2 text-base text-ink sm:flex-row sm:flex-wrap sm:gap-x-6">
                {facts.map((f) => (
                  <li key={f.text} className="flex items-center gap-2">
                    <span className="text-primary">{f.icon}</span>
                    <span className="num">{f.text}</span>
                  </li>
                ))}
              </ul>
              {/* Mobile: giá + hành động ngay dưới tiêu đề; thanh dính đáy lặp lại khi cuộn. */}
              <div className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 lg:hidden">
                <PriceLine price={course.price} isFree={free} />
                <CourseAction />
              </div>
            </header>

            {course.description ? (
              <section aria-labelledby="course-desc">
                <h2 id="course-desc" className="text-heading font-extrabold tracking-heading text-ink">
                  Giới thiệu khóa học
                </h2>
                <div className="mt-3">
                  <Collapsible>
                    <CourseDescription html={course.description} />
                  </Collapsible>
                </div>
              </section>
            ) : null}

            <section aria-labelledby="course-outline">
              <h2 id="course-outline" className="scroll-mt-24 text-heading font-extrabold tracking-heading text-ink">
                Nội dung khóa học
              </h2>
              <p className="num mt-1 text-sm text-ink-soft">
                {course.outline.length} chương · {course.lessons_count} bài · {formatDurationLong(course.total_duration_seconds)}
              </p>
              <div className="mt-4">
                <CourseOutline chapters={course.outline} courseId={course.id} />
              </div>
            </section>

            {course.teachers.length > 0 ? (
              <section aria-labelledby="course-teachers">
                <h2 id="course-teachers" className="text-heading font-extrabold tracking-heading text-ink">
                  Giáo viên
                </h2>
                <div className="mt-4">
                  <TeacherList teachers={course.teachers} />
                </div>
              </section>
            ) : null}
          </div>

          {/* Desktop: thẻ dính bên phải. */}
          <aside aria-label="Đăng ký khóa học" className="hidden lg:block">
            <div className="sticky top-24 flex flex-col gap-4 overflow-hidden rounded-sheet border border-line bg-surface">
              <CourseCover title={course.title} gradeLevel={course.grade_level} subjectSlug={subjectSlug} image={image} />
              <div className="flex flex-col gap-4 px-5 pb-5">
                <PriceLine price={course.price} isFree={free} />
                <div id="course-cta">
                  <CourseAction />
                </div>
                <p className="flex items-center gap-2 border-t border-line pt-4 text-sm text-ink-soft">
                  <IconClock size={16} />
                  Học trên điện thoại hoặc máy tính, mỗi lúc một thiết bị.
                </p>
              </div>
            </div>
          </aside>
        </div>
      </div>

      {/* Mobile: thanh hành động dính đáy (US-003 §2.1 mục 3). */}
      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3 shadow-overlay lg:hidden">
        <div className="mx-auto flex max-w-xl items-center gap-4">
          <PriceLine price={course.price} isFree={free} size="md" />
          <div className="flex-1">
            <CourseAction compact />
          </div>
        </div>
      </div>
    </CourseCtaProvider>
  );
}
