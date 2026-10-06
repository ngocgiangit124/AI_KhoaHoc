import Link from "next/link";
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
import type { LearnCourse, LessonStatus } from "@/lib/mock/v2/types";
import { routes } from "@/lib/v2/routes";

const STATUS: Record<LessonStatus, { icon: React.ReactNode; label: string }> = {
  completed: { icon: <IconCheckCircle className="text-success" />, label: "Đã hoàn thành" },
  in_progress: { icon: <IconCircleHalf className="text-primary" />, label: "Đang học" },
  not_started: { icon: <IconCircle className="text-line-strong" />, label: "Chưa học" },
};

/**
 * Mục lục khi đang học: chương là `<details>` (mở sẵn chương chứa bài hiện tại), mỗi bài có icon trạng thái
 * kèm chữ ẩn cho trình đọc màn hình. Bài hiện tại: nền `primary-soft` + vạch trái + aria-current.
 * Bài trắc nghiệm nằm ngay dưới bài/chương mà nó gắn vào.
 */
export function LessonOutline({ data, currentLessonId }: { data: LearnCourse; currentLessonId?: number }) {
  return (
    <div className="flex flex-col gap-2">
      {data.chapters.map((ch) => {
        const done = ch.lessons.filter((l) => l.status === "completed").length;
        const hasCurrent = ch.lessons.some((l) => l.id === currentLessonId);
        return (
          <details key={ch.id} open={hasCurrent || (currentLessonId === undefined && ch.position === 1)} className="group rounded-card border border-line bg-surface">
            <summary className="focus-ring flex min-h-14 cursor-pointer list-none items-center gap-3 rounded-card px-3 py-2 [&::-webkit-details-marker]:hidden">
              <span className="flex-1">
                <span className="block text-base font-semibold text-ink">{ch.title}</span>
                <span className="num block text-sm text-ink-soft">
                  Đã xong {done}/{ch.lessons.length} bài
                </span>
              </span>
              <IconChevronDown className="text-ink-soft transition-transform duration-150 group-open:rotate-180" />
            </summary>
            <ul className="border-t border-line py-1">
              {ch.lessons.map((l) => {
                const current = l.id === currentLessonId;
                return (
                  <li key={l.id}>
                    <Link
                      href={routes.lesson(data.course.id, l.id)}
                      aria-current={current ? "page" : undefined}
                      className={cx(
                        "focus-ring relative flex min-h-12 items-start gap-3 px-3 py-2.5",
                        current ? "bg-primary-soft" : "hover:bg-sunken",
                      )}
                    >
                      {current ? <span aria-hidden="true" className="absolute inset-y-1 left-0 w-1 rounded-r-full bg-primary" /> : null}
                      <span className="mt-0.5">{current ? <IconPlayCircle className="text-primary" /> : STATUS[l.status].icon}</span>
                      <span className="flex flex-1 flex-col">
                        <span className={cx("text-base", current ? "font-semibold text-primary" : "text-ink")}>{l.title}</span>
                        {!l.video_ready ? <span className="text-sm text-warning">Video đang xử lý</span> : null}
                        <span className="sr-only">{current ? "Đang xem" : STATUS[l.status].label}</span>
                      </span>
                      <span className="num pt-0.5 text-sm text-ink-soft">{formatClock(l.duration_seconds)}</span>
                    </Link>
                    {l.quizzes.map((q) => (
                      <Link
                        key={q.id}
                        href={routes.quiz(data.course.id, q.id)}
                        className="focus-ring flex min-h-11 items-center gap-3 py-2 pl-10 pr-3 text-sm hover:bg-sunken"
                      >
                        <IconListChecks size={18} className="text-accent-ink" />
                        <span className="flex-1 font-medium text-ink">{q.title}</span>
                        <span className="num text-ink-soft">{q.question_count} câu</span>
                      </Link>
                    ))}
                  </li>
                );
              })}
              {ch.quizzes.map((q) => (
                <li key={q.id}>
                  <Link href={routes.quiz(data.course.id, q.id)} className="focus-ring flex min-h-11 items-center gap-3 px-3 py-2 text-sm hover:bg-sunken">
                    <IconListChecks size={18} className="text-accent-ink" />
                    <span className="flex-1 font-semibold text-ink">{q.title}</span>
                    <span className="num text-ink-soft">{q.question_count} câu</span>
                  </Link>
                </li>
              ))}
            </ul>
          </details>
        );
      })}
    </div>
  );
}
