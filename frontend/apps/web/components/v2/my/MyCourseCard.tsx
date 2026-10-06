import Link from "next/link";
import { Badge, ButtonLink, CourseCover, IconInfo, ProgressBar, formatScore } from "@vitaminvui/ui/v2";
import type { MyCoursesResponse } from "@/lib/mock/v2/types";
import { routes } from "@/lib/v2/routes";

type Item = MyCoursesResponse["data"][number];

/** Thẻ một khóa đang học (GET /me/courses): tiến độ bằng số + thanh, trạng thái bằng chữ, nút học tiếp. */
export function MyCourseCard({ item }: { item: Item }) {
  const { course, progress } = item;
  const started = item.enrollment.last_accessed_at !== null;
  const status = progress.is_completed
    ? { tone: "success" as const, label: "Đã hoàn thành" }
    : started
      ? { tone: "info" as const, label: "Đang học" }
      : { tone: "neutral" as const, label: "Chưa bắt đầu" };
  const cta = progress.is_completed ? "Xem lại" : started ? "Tiếp tục học" : "Bắt đầu học";
  return (
    <article className="flex flex-col overflow-hidden rounded-card border border-line bg-surface sm:flex-row">
      <div className="sm:w-56 sm:shrink-0">
        <CourseCover title={course.title} gradeLevel={course.grade_level} subjectSlug={course.slug} className="h-full" />
      </div>
      <div className="flex flex-1 flex-col gap-3 p-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={status.tone} size="sm">
            {status.label}
          </Badge>
          {item.best_quiz_score !== null ? (
            <span className="num text-sm text-ink-soft">Điểm trắc nghiệm cao nhất: {formatScore(item.best_quiz_score)}</span>
          ) : null}
        </div>
        <h3 className="text-lg font-semibold leading-snug text-ink">
          <Link href={routes.myCourse(course.id)} className="focus-ring rounded hover:text-primary">
            {course.title}
          </Link>
        </h3>
        <ProgressBar
          value={progress.percent}
          label="Tiến độ"
          valueText={`${progress.completed_lessons}/${progress.total_lessons} bài · ${progress.percent}%`}
          hasContent={progress.has_content}
          tone={progress.is_completed ? "success" : "primary"}
        />
        {!course.is_published ? (
          <p className="flex items-start gap-1.5 text-sm text-ink-soft">
            <IconInfo size={16} className="mt-0.5 shrink-0" />
            Khóa đã ngừng bán, bạn vẫn học được như bình thường.
          </p>
        ) : null}
        <div className="mt-auto flex flex-wrap gap-2 pt-1">
          {item.resume_lesson_id ? (
            <ButtonLink href={routes.lesson(course.id, item.resume_lesson_id)} variant={progress.is_completed ? "secondary" : "primary"}>
              {cta}
            </ButtonLink>
          ) : null}
          <ButtonLink href={routes.myCourse(course.id)} variant="ghost">
            Xem tiến độ
          </ButtonLink>
        </div>
      </div>
    </article>
  );
}
