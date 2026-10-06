import type { ReactNode } from "react";
import { Badge } from "./Badge";
import { CourseCover } from "./CourseCover";
import { cx } from "./cx";
import { formatCount, formatPrice } from "./format";
import { IconUsers } from "./icons";
import { UiLink } from "./Link";

/** Đúng các trường của item `GET /courses` (api-contract §2.1) mà thẻ cần. */
export interface CourseCardData {
  id: number;
  title: string;
  slug: string;
  short_description: string | null;
  grade_level: number;
  price: number;
  is_free: boolean;
  thumbnail_url: string | null;
  enrollments_count: number;
  subjects: Array<{ id: number; name: string; slug: string }>;
  teachers: Array<{ id: number; name: string }>;
}

export interface CourseCardProps {
  course: CourseCardData;
  href: string;
  /** Ảnh `next/image` khi có `thumbnail_url`. */
  image?: ReactNode;
  /** Tiêu đề thẻ là h2 (danh mục) hoặc h3 (trong section có h2). */
  headingLevel?: "h2" | "h3";
  /**
   * `paid_checkout_enabled` (config/public). false + khóa có phí → dưới giá hiện "Sắp mở bán" (US-019 BR4).
   * Mặc định true để không đổi chỗ dùng cũ.
   */
  paidCheckoutEnabled?: boolean;
  className?: string;
}

/**
 * Thẻ khóa học ở danh mục/trang chủ. Cả thẻ bấm được (liên kết ở tiêu đề + lớp phủ), thứ tự đọc:
 * tiêu đề → chuyên đề → mô tả → giáo viên → số học sinh → giá.
 * Hover: viền chuyển `primary`, tiêu đề đổi màu — không phóng to/nhấc thẻ.
 * Không hiện số bài/thời lượng: `GET /courses` không trả 2 trường này (xem Phân tích §1.3).
 */
export function CourseCard({ course, href, image, headingLevel = "h3", paidCheckoutEnabled = true, className }: CourseCardProps) {
  const Heading = headingLevel;
  const subjects = course.subjects.map((s) => s.name).join(" · ");
  const teachers = course.teachers.map((t) => t.name).join(", ");
  return (
    <article
      className={cx(
        "group relative flex flex-col overflow-hidden rounded-card border border-line bg-surface transition-colors duration-150 focus-within:border-primary hover:border-primary",
        className,
      )}
    >
      <CourseCover
        title={course.title}
        gradeLevel={course.grade_level}
        subjectSlug={course.subjects[0]?.slug}
        image={image}
        corner={course.is_free ? <Badge tone="free">Miễn phí</Badge> : null}
      />
      <div className="flex flex-1 flex-col gap-2 p-4">
        {subjects ? <p className="text-sm font-medium text-ink-soft">{subjects}</p> : null}
        <Heading className="text-lg font-semibold leading-snug text-ink group-hover:text-primary">
          <UiLink href={href} className="focus-ring rounded after:absolute after:inset-0 after:content-['']">
            {course.title}
          </UiLink>
        </Heading>
        {course.short_description ? <p className="line-clamp-2 text-sm text-ink-soft">{course.short_description}</p> : null}
        <div className="mt-auto flex flex-col gap-3 pt-2">
          {teachers ? <p className="truncate text-sm text-ink-soft">Giáo viên: {teachers}</p> : null}
          <div className="flex items-end justify-between gap-3 border-t border-line pt-3">
            <span className="flex items-center gap-1.5 text-sm text-ink-soft">
              <IconUsers size={16} />
              {course.enrollments_count > 0 ? `${formatCount(course.enrollments_count)} học sinh` : "Khóa mới"}
            </span>
            <span className="flex flex-col items-end">
              <span className={cx("num text-lg font-extrabold", course.is_free ? "text-success" : "text-ink")}>{formatPrice(course.price)}</span>
              {!course.is_free && !paidCheckoutEnabled ? <span className="text-sm font-semibold text-info">Sắp mở bán</span> : null}
            </span>
          </div>
        </div>
      </div>
    </article>
  );
}
