import { IconCheckCircle, IconChevronDown, IconCircle, IconCircleHalf, cx, formatClock } from "@vitaminvui/ui/v2";
import { AppLink } from "@/components/shell/AppLink";
import type { CourseProgress, MyLessonStatus } from "@/lib/my/schemas";
import { routes } from "@/lib/routes";

const STATUS: Record<MyLessonStatus, { icon: React.ReactNode; label: string }> = {
  completed: { icon: <IconCheckCircle className="text-success" />, label: "Đã hoàn thành" },
  in_progress: { icon: <IconCircleHalf className="text-primary" />, label: "Đang học" },
  not_started: { icon: <IconCircle className="text-line-strong" />, label: "Chưa học" },
};

/** Danh sách chương/bài của trang tiến độ: chỉ xem trạng thái, bấm bài để sang trang học. Mở sẵn chương chứa bài học tiếp. */
export function ProgressOutline({ data }: { data: CourseProgress }) {
  return (
    <div className="flex flex-col gap-2">
      {data.chapters.map((ch) => {
        const hasResume = ch.lessons.some((l) => l.id === data.resume_lesson_id);
        return (
          <details key={ch.id} open={hasResume} className="group rounded-card border border-line bg-surface">
            <summary className="focus-ring flex min-h-14 cursor-pointer list-none items-center gap-3 rounded-card px-3 py-2 [&::-webkit-details-marker]:hidden">
              <span className="flex-1">
                <span className="block text-base font-semibold text-ink">{ch.title}</span>
                <span className="num block text-sm text-ink-soft">
                  Đã xong {ch.completed_lessons}/{ch.total_lessons} bài
                </span>
              </span>
              <IconChevronDown className="text-ink-soft transition-transform duration-150 group-open:rotate-180" />
            </summary>
            <ul className="border-t border-line py-1">
              {ch.lessons.map((l) => (
                <li key={l.id}>
                  <AppLink
                    href={routes.lesson(data.course.id, l.id)}
                    className={cx("focus-ring flex min-h-12 items-start gap-3 px-3 py-2.5 hover:bg-sunken", l.id === data.resume_lesson_id && "bg-primary-soft")}
                  >
                    <span className="mt-0.5">{STATUS[l.status].icon}</span>
                    <span className="flex flex-1 flex-col">
                      <span className="text-base text-ink">{l.title}</span>
                      <span className="text-sm text-ink-soft">
                        {STATUS[l.status].label}
                        {l.id === data.resume_lesson_id ? " · Bài học tiếp theo" : ""}
                      </span>
                    </span>
                    <span className="num pt-0.5 text-sm text-ink-soft">{l.duration_seconds === null ? "" : formatClock(l.duration_seconds)}</span>
                  </AppLink>
                </li>
              ))}
            </ul>
          </details>
        );
      })}
    </div>
  );
}
