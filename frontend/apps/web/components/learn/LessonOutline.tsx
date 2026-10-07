import {
  IconCheckCircle,
  IconChevronDown,
  IconCircle,
  IconCircleHalf,
  IconListChecks,
  IconPlayCircle,
  cx,
  formatClock,
} from "@vitaminvui/ui/v2";
import { AppLink } from "@/components/shell/AppLink";
import { effectiveStatus } from "@/lib/learn/progress";
import type { LearnCourse, LessonStatus, QuizSummary } from "@/lib/learn/schemas";
import { routes } from "@/lib/routes";

const STATUS: Record<LessonStatus, { icon: React.ReactNode; label: string }> = {
  completed: { icon: <IconCheckCircle className="text-success" />, label: "Đã hoàn thành" },
  in_progress: { icon: <IconCircleHalf className="text-primary" />, label: "Đang học" },
  not_started: { icon: <IconCircle className="text-line-strong" />, label: "Chưa học" },
};

/** Bài trắc nghiệm: màn làm quiz là FW5 (chưa có route) nên chỉ hiện thông tin, không dẫn tới trang chưa dựng (US-019). */
function QuizRow({ quiz, className }: { quiz: QuizSummary; className: string }) {
  return (
    <div className={cx("flex min-h-11 items-center gap-3 py-2 text-sm", className)}>
      <IconListChecks size={18} className="text-accent-ink" />
      <span className="flex-1 font-medium text-ink">{quiz.title}</span>
      <span className="num text-ink-soft">{quiz.question_count} câu</span>
    </div>
  );
}

/**
 * Mục lục khi đang học (design-system-v2 §12.3): chương là `<details>` (mở sẵn chương chứa bài hiện tại), icon trạng thái kèm chữ ẩn,
 * bài hiện tại có nền `primary-soft` + vạch trái + `aria-current`. `done`: bài vừa hoàn thành trong phiên này (đổi icon ngay).
 */
export function LessonOutline({ data, currentLessonId, done }: { data: LearnCourse; currentLessonId: number; done: ReadonlySet<number> }) {
  return (
    <div className="flex flex-col gap-2">
      {data.chapters.map((ch) => {
        const doneCount = ch.lessons.filter((l) => effectiveStatus(l.status, l.id, done) === "completed").length;
        const hasCurrent = ch.lessons.some((l) => l.id === currentLessonId);
        return (
          <details key={ch.id} open={hasCurrent} className="group rounded-card border border-line bg-surface">
            <summary className="focus-ring flex min-h-14 cursor-pointer list-none items-center gap-3 rounded-card px-3 py-2 [&::-webkit-details-marker]:hidden">
              <span className="flex-1">
                <span className="block text-base font-semibold text-ink">{ch.title}</span>
                <span className="num block text-sm text-ink-soft">
                  Đã xong {doneCount}/{ch.lessons.length} bài
                </span>
              </span>
              <IconChevronDown className="text-ink-soft transition-transform duration-150 group-open:rotate-180" />
            </summary>
            <ul className="border-t border-line py-1">
              {ch.lessons.map((l) => {
                const current = l.id === currentLessonId;
                const status = effectiveStatus(l.status, l.id, done);
                return (
                  <li key={l.id}>
                    <AppLink
                      href={routes.lesson(data.course.id, l.id)}
                      aria-current={current ? "page" : undefined}
                      className={cx(
                        "focus-ring relative flex min-h-12 items-start gap-3 px-3 py-2.5",
                        current ? "bg-primary-soft" : "hover:bg-sunken",
                      )}
                    >
                      {current ? <span aria-hidden="true" className="absolute inset-y-1 left-0 w-1 rounded-r-full bg-primary" /> : null}
                      <span className="mt-0.5">{current ? <IconPlayCircle className="text-primary" /> : STATUS[status].icon}</span>
                      <span className="flex flex-1 flex-col">
                        <span className={cx("text-base", current ? "font-semibold text-primary" : "text-ink")}>{l.title}</span>
                        {!l.video_ready ? <span className="text-sm text-warning">Video đang xử lý</span> : null}
                        <span className="sr-only">{current ? "Đang xem" : STATUS[status].label}</span>
                      </span>
                      <span className="num pt-0.5 text-sm text-ink-soft">{formatClock(l.duration_seconds ?? 0)}</span>
                    </AppLink>
                    {l.quizzes.map((q) => (
                      <QuizRow key={q.id} quiz={q} className="pl-10 pr-3" />
                    ))}
                  </li>
                );
              })}
              {ch.quizzes.map((q) => (
                <li key={q.id}>
                  <QuizRow quiz={q} className="px-3 font-semibold" />
                </li>
              ))}
            </ul>
          </details>
        );
      })}
    </div>
  );
}
