"use client";

import { Badge, EmptyState, IconChevronDown, IconLayers, IconLock, IconPlayCircle, formatClock, formatDurationLong } from "@vitaminvui/ui/v2";
import type { ChapterOutline } from "@/lib/catalog/schemas";
import { useCourseCta } from "./CourseCtaProvider";

/**
 * Nội dung khóa học công khai (design §12.2, mockup `CourseOutlinePublic`). Chương là `<details>` gốc: bàn phím/trình
 * đọc màn hình dùng sẵn. Mọi người xem được tên bài + thời lượng (US-003 BR1).
 * - Chưa sở hữu: bài có khoá là hàng tĩnh + biểu tượng ổ khoá, câu giải thích nằm phía trên danh sách (không bật
 *   thông báo khi bấm).
 * - Bài học thử: nhãn "Học thử". TODO(FW4): bấm để phát video (`GET /preview/lessons/{lesson}/playback`) — cần hls.js, chưa cài.
 * - Đã sở hữu: hàng tĩnh cho tới khi có trang học (FW4) — không để liên kết chết.
 */
export function CourseOutline({ chapters }: { chapters: ChapterOutline[]; courseId?: number }) {
  const { owned } = useCourseCta();

  if (chapters.length === 0 || chapters.every((c) => c.lessons.length === 0)) {
    return (
      <EmptyState
        size="inline"
        icon={<IconLayers size={28} />}
        title="Nội dung khóa học đang được cập nhật"
        description="Giáo viên đang soạn bài. Quay lại sau nhé."
        headingLevel="h3"
      />
    );
  }

  return (
    <div className="flex flex-col gap-3">
      {!owned ? (
        <p className="flex items-start gap-2 text-sm text-ink-soft">
          <IconLock size={16} className="mt-0.5 shrink-0" />
          Bài có biểu tượng khoá mở khi bạn sở hữu khóa học. Bài “Học thử” là bài miễn phí.
        </p>
      ) : null}
      {chapters.map((chapter, index) => {
        const total = chapter.lessons.reduce((sum, l) => sum + (l.duration_seconds ?? 0), 0);
        return (
          <details key={chapter.id} open={index === 0} className="group rounded-card border border-line bg-surface">
            <summary className="focus-ring flex min-h-14 cursor-pointer list-none items-center gap-3 rounded-card px-4 py-3 [&::-webkit-details-marker]:hidden">
              <span className="flex-1">
                <span className="block text-base font-semibold text-ink">{chapter.title}</span>
                <span className="num block text-sm text-ink-soft">
                  {chapter.lessons.length} bài{total > 0 ? ` · ${formatDurationLong(total)}` : ""}
                </span>
              </span>
              <IconChevronDown className="text-ink-soft transition-transform duration-150 group-open:rotate-180" />
            </summary>
            <ul className="border-t border-line px-1 py-1">
              {chapter.lessons.map((lesson) => {
                const duration = lesson.duration_seconds !== null && lesson.duration_seconds > 0 ? formatClock(lesson.duration_seconds) : null;
                const time = <span className="num w-12 text-right text-sm text-ink-soft">{duration}</span>;
                return (
                  <li key={lesson.id}>
                    {owned ? (
                      // TODO(FW4): bài là liên kết `/hoc/${courseId}/bai/${lesson.id}` khi trang học có thật.
                      <div className="flex min-h-12 items-center gap-3 px-3 py-2">
                        <IconPlayCircle className="text-ink-soft" />
                        <span className="flex-1 text-base text-ink">{lesson.title}</span>
                        {time}
                      </div>
                    ) : lesson.is_preview ? (
                      <div className="flex min-h-12 items-center gap-3 px-3 py-2">
                        <IconPlayCircle className="text-primary" />
                        <span className="flex-1 text-base text-ink">{lesson.title}</span>
                        <Badge tone="primary" size="sm">
                          Học thử
                        </Badge>
                        {time}
                      </div>
                    ) : (
                      <div className="flex min-h-12 items-center gap-3 px-3 py-2">
                        <IconLock className="text-ink-soft" title="Cần sở hữu khóa học" />
                        <span className="flex-1 text-base text-ink-soft">{lesson.title}</span>
                        {time}
                      </div>
                    )}
                  </li>
                );
              })}
            </ul>
          </details>
        );
      })}
    </div>
  );
}
