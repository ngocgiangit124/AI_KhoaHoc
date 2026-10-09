import Link from "next/link";
import { Alert, ButtonLink, CourseCard, IconArrowRight, IconRotateCcw } from "@vitaminvui/ui/v2";
import { CardCartProvider } from "@/components/catalog/CardCartProvider";
import { CourseCardAction } from "@/components/catalog/CourseCardAction";
import { CourseImage } from "@/components/catalog/CourseImage";
import type { CourseListItem } from "@/lib/catalog/schemas";
import { routes } from "@/lib/routes";

export interface FeaturedCoursesProps {
  /** `null` = API lỗi/quá chậm (hiện thông báo + "Tải lại" tại chỗ); `[]` = chưa có khóa nào. */
  courses: CourseListItem[] | null;
  paidCheckoutEnabled: boolean;
}

/** "Khóa học nổi bật" (US-019 BR3/BR4/BR8): tối đa 4 khóa theo `sort=featured`; rỗng và lỗi tự xử lý tại chỗ. */
export function FeaturedCourses({ courses, paidCheckoutEnabled }: FeaturedCoursesProps) {
  return (
    <section aria-labelledby="featured-title">
      <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 id="featured-title" className="text-heading font-extrabold tracking-heading text-ink md:text-heading-lg">
              Khóa học nổi bật
            </h2>
            <p className="mt-1 text-base text-ink-soft">Do đội ngũ VitaminVui chọn.</p>
          </div>
          <Link
            href={`${routes.catalog}?sort=featured`}
            className="focus-ring inline-flex min-h-11 items-center gap-1 rounded font-semibold text-primary hover:underline"
          >
            Xem tất cả khóa học
            <IconArrowRight size={18} />
          </Link>
        </div>
        <div className="mt-6">
          {courses === null ? (
            <Alert
              tone="danger"
              title="Không tải được khóa học nổi bật"
              action={
                // Điều hướng lại chính trang `/` (render động, lỗi không vào Data Cache) -> gọi lại API.
                <ButtonLink href={routes.home} variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />}>
                  Tải lại
                </ButtonLink>
              }
            >
              Các phần khác của trang vẫn dùng được. Bạn có thể xem toàn bộ khóa học ở trang danh mục.
            </Alert>
          ) : courses.length === 0 ? (
            <p className="rounded-card border border-dashed border-line-strong px-4 py-6 text-base text-ink">
              Khóa học sẽ sớm được cập nhật.{" "}
              <Link href={routes.catalog} className="focus-ring inline-flex min-h-11 items-center rounded font-semibold text-primary underline underline-offset-4">
                Xem danh mục khóa học
              </Link>
            </p>
          ) : (
            <CardCartProvider paidCheckoutEnabled={paidCheckoutEnabled}>
              <ul /* grid-cols-1 + min-w-0 ở <li>: tên giáo viên dài (truncate) không kéo rộng cột, tránh cuộn ngang ở 375px */ className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {courses.map((c) => (
                  <li key={c.id} className="min-w-0">
                    <CourseCard
                      course={c}
                      href={routes.course(c.slug)}
                      paidCheckoutEnabled={paidCheckoutEnabled}
                      action={<CourseCardAction course={{ id: c.id, slug: c.slug, title: c.title, isFree: c.is_free }} />}
                      className="h-full"
                      image={c.thumbnail_url ? <CourseImage url={c.thumbnail_url} sizes="(min-width: 1024px) 280px, (min-width: 640px) 50vw, 100vw" /> : undefined}
                    />
                  </li>
                ))}
              </ul>
            </CardCartProvider>
          )}
        </div>
      </div>
    </section>
  );
}
