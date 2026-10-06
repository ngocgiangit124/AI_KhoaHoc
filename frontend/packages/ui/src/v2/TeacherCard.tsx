import type { ReactNode } from "react";
import { cx } from "./cx";
import { initials } from "./Avatar";
import { IconArrowRight } from "./icons";
import { UiLink } from "./Link";

/**
 * Một giáo viên ở khu vực trang chủ (US-020 BR1). Tên trường tạm theo điều phối viên, chờ Architect
 * chốt api-contract: `GET /home/teachers` → `{data: HomeTeacher[]}` (tối đa 6).
 * `avatar_url`/`bio` là null khi giáo viên chưa đồng ý công khai (BR5) — khi đó API trang chủ không trả người này.
 */
export interface HomeTeacher {
  id: number;
  name: string;
  avatar_url: string | null;
  headline: string | null;
  grade_levels: number[];
  published_courses_count: number;
  bio: string | null;
}

/** [9, 10, 11, 12] → "Lớp 9–12"; [6, 9] → "Lớp 6, 9". */
export function formatGrades(grades: number[]): string {
  const g = [...new Set(grades)].sort((a, b) => a - b);
  if (g.length === 0) return "";
  const parts: string[] = [];
  let start = g[0] as number;
  let prev = start;
  for (const n of [...g.slice(1), Number.NaN]) {
    if (n === prev + 1) {
      prev = n;
      continue;
    }
    parts.push(prev - start >= 2 ? `${start}–${prev}` : start === prev ? `${start}` : `${start}, ${prev}`);
    start = n;
    prev = n;
  }
  return `Lớp ${parts.join(", ")}`;
}

export interface TeacherCardProps {
  teacher: HomeTeacher;
  /** Đích "Xem N khóa học" (mặc định Q6: danh mục lọc `teacher_id`). Không truyền → chỉ hiện "N khóa học". */
  coursesHref?: string;
  /** Ảnh thật: app truyền `next/image` (`fill`, alt "Ảnh thầy/cô {họ tên}"). Không có/ảnh lỗi → chữ cái đầu (AC17). */
  image?: ReactNode;
  headingLevel?: "h3" | "h4";
  className?: string;
}

/**
 * Thẻ giáo viên: ảnh vuông + họ tên (tối đa 2 dòng) + chuyên môn; dòng "Lớp · N khóa đang bán";
 * giới thiệu cắt 3 dòng (văn bản thuần, giữ xuống dòng); liên kết "Xem N khóa học".
 */
export function TeacherCard({ teacher, coursesHref, image, headingLevel = "h3", className }: TeacherCardProps) {
  const Heading = headingLevel;
  const grades = formatGrades(teacher.grade_levels);
  const n = teacher.published_courses_count;
  return (
    <article className={cx("flex h-full flex-col gap-3 rounded-card border border-line bg-surface p-4", className)}>
      <div className="flex items-start gap-4">
        <div className="relative size-20 shrink-0 overflow-hidden rounded-card border border-line sm:size-24">
          {image ?? (
            <div aria-hidden="true" className="bg-oly flex size-full items-center justify-center bg-primary-soft">
              <span className="text-heading-lg font-extrabold text-primary">{initials(teacher.name)}</span>
            </div>
          )}
        </div>
        <div className="flex min-w-0 flex-col gap-1">
          <Heading className="line-clamp-2 text-lg font-semibold leading-snug text-ink">{teacher.name}</Heading>
          {teacher.headline ? <p className="line-clamp-2 text-sm font-medium text-primary">{teacher.headline}</p> : null}
          <p className="num text-sm text-ink-soft">
            {grades}
            {grades ? " · " : ""}
            {n} khóa đang bán
          </p>
        </div>
      </div>
      {teacher.bio ? <p className="line-clamp-3 whitespace-pre-line text-base leading-relaxed text-ink">{teacher.bio}</p> : null}
      {coursesHref ? (
        <UiLink href={coursesHref} className="focus-ring mt-auto inline-flex min-h-11 w-fit items-center gap-1 rounded font-semibold text-primary hover:underline">
          Xem {n} khóa học
          <span className="sr-only"> của {teacher.name}</span>
          <IconArrowRight size={18} />
        </UiLink>
      ) : null}
    </article>
  );
}
