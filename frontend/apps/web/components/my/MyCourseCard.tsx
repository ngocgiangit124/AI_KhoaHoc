import { Badge, ButtonLink, CourseCover, IconInfo, ProgressBar, formatScore } from "@vitaminvui/ui/v2";
import { CourseImage } from "@/components/catalog/CourseImage";
import { AppLink } from "@/components/shell/AppLink";
import { courseStatus, ctaLabel, progressText } from "@/lib/my/format";
import type { MyCourseItem } from "@/lib/my/schemas";
import { routes } from "@/lib/routes";

/** Thẻ một khóa đang học (GET /me/courses): tiến độ bằng số + thanh, trạng thái bằng chữ, nút học tiếp. */
export function MyCourseCard({ item }: { item: MyCourseItem }) {
  const { course, progress } = item;
  const status = courseStatus(progress, item.enrollment.last_accessed_at);
  return (
    <article className="flex flex-col overflow-hidden rounded-card border border-line bg-surface sm:flex-row">
      <div className="sm:w-56 sm:shrink-0">
        <CourseCover
          title={course.title}
          gradeLevel={course.grade_level}
          subjectSlug={course.slug}
          className="h-full"
          image={course.thumbnail_url ? <CourseImage url={course.thumbnail_url} sizes="(min-width: 640px) 224px, 100vw" /> : undefined}
        />
      </div>
      <div className="flex flex-1 flex-col gap-3 p-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={status.tone} size="sm">
            {status.label}
          </Badge>
          {item.best_quiz_score !== null ? <span className="num text-sm text-ink-soft">Điểm trắc nghiệm cao nhất: {formatScore(item.best_quiz_score)}/10</span> : null}
        </div>
        <h3 className="text-lg font-semibold leading-snug text-ink">
          <AppLink href={routes.myCourse(course.id)} className="focus-ring inline-flex min-h-11 items-center rounded hover:text-primary">
            {course.title}
          </AppLink>
        </h3>
        <ProgressBar
          value={progress.percent}
          label="Tiến độ"
          valueText={progressText(progress)}
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
              {ctaLabel(progress, item.enrollment.last_accessed_at)}
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
