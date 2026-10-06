import Link from "next/link";
import { EmptyState, IconChevronDown, IconLock, IconPlayCircle, IconLayers, formatClock, formatDurationLong } from "@vitaminvui/ui/v2";
import type { CourseDetail } from "@/lib/mock/v2/types";
import { routes } from "@/lib/v2/routes";
import { PreviewLessonButton } from "./PreviewLessonButton";

/**
 * Nội dung khóa học (công khai). Chương là `<details>` gốc: bàn phím/trình đọc màn hình dùng sẵn, không cần JS.
 * - Bài học thử: mở video trong hộp thoại.
 * - Chưa sở hữu: bài có khoá, không bấm được (không hiện thông báo bật lên giữa danh sách).
 * - Đã sở hữu: mọi bài là liên kết sang trang học.
 */
export function CourseOutlinePublic({ course, owned }: { course: CourseDetail; owned: boolean }) {
  if (course.outline.length === 0) {
    return <EmptyState size="inline" icon={<IconLayers size={28} />} title="Nội dung khóa học đang được cập nhật" description="Giáo viên đang soạn bài. Quay lại sau nhé." headingLevel="h3" />;
  }
  return (
    <div className="flex flex-col gap-3">
      {!owned ? (
        <p className="flex items-start gap-2 text-sm text-ink-soft">
          <IconLock size={16} className="mt-0.5" />
          Bài có biểu tượng khoá mở khi bạn sở hữu khóa học. Bài “Học thử” xem được ngay.
        </p>
      ) : null}
      {course.outline.map((ch, i) => {
        const total = ch.lessons.reduce((s, l) => s + l.duration_seconds, 0);
        return (
          <details key={ch.id} open={i === 0} className="group rounded-card border border-line bg-surface">
            <summary className="focus-ring flex min-h-14 cursor-pointer list-none items-center gap-3 rounded-card px-4 py-3 [&::-webkit-details-marker]:hidden">
              <span className="flex-1">
                <span className="block text-base font-semibold text-ink">{ch.title}</span>
                <span className="num block text-sm text-ink-soft">
                  {ch.lessons.length} bài · {formatDurationLong(total)}
                </span>
              </span>
              <IconChevronDown className="text-ink-soft transition-transform duration-150 group-open:rotate-180" />
            </summary>
            <ul className="border-t border-line px-1 py-1">
              {ch.lessons.map((l) => (
                <li key={l.id}>
                  {owned ? (
                    <Link href={routes.lesson(course.id, l.id)} className="focus-ring flex min-h-12 items-center gap-3 rounded-control px-3 py-2 hover:bg-primary-soft">
                      <IconPlayCircle className="text-ink-soft" />
                      <span className="flex-1 text-base text-ink">{l.title}</span>
                      <span className="num w-12 text-right text-sm text-ink-soft">{formatClock(l.duration_seconds)}</span>
                    </Link>
                  ) : l.is_preview ? (
                    <PreviewLessonButton title={l.title} durationSeconds={l.duration_seconds} />
                  ) : (
                    <div className="flex min-h-12 items-center gap-3 px-3 py-2">
                      <IconLock className="text-ink-soft" title="Cần sở hữu khóa học" />
                      <span className="flex-1 text-base text-ink-soft">{l.title}</span>
                      <span className="num w-12 text-right text-sm text-ink-soft">{formatClock(l.duration_seconds)}</span>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </details>
        );
      })}
    </div>
  );
}
