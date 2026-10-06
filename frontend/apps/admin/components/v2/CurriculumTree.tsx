import Link from "next/link";
import { Badge, Button, IconButton, IconChevronRight, IconGrip, IconPencil, IconPlus, IconTrash, cx, formatClock } from "@vitaminvui/ui/v2";
import type { AdminChapter } from "@/lib/mock/v2/data";
import { VideoStatusBadge } from "./CourseStatusBadge";

/**
 * Cây chương/bài (US-009 §2.3, GET /admin/courses/{id}/chapters). Bản thật kéo-thả bằng @dnd-kit
 * (có KeyboardSensor) và gửi PUT .../curriculum/order; 422 CURRICULUM_MISMATCH → tải lại cây + thông báo.
 * Tay cầm ⠿ luôn hiện (không phụ thuộc hover). Bài đang chọn: nền `primary-soft` + aria-current.
 */
export function CurriculumTree({ chapters, selectedId, lessonHref }: { chapters: AdminChapter[]; selectedId?: number; lessonHref: (id: number) => string }) {
  return (
    <div className="flex flex-col gap-3">
      {chapters.map((ch) => (
        <section key={ch.id} aria-labelledby={`ch-${ch.id}`} className="rounded-card border border-line bg-surface">
          <header className="flex items-center gap-2 border-b border-line px-2 py-2 sm:px-3">
            <button type="button" aria-label={`Kéo để đổi thứ tự: ${ch.title}`} className="focus-ring flex size-9 cursor-grab items-center justify-center rounded-control text-ink-soft hover:bg-sunken">
              <IconGrip size={18} />
            </button>
            <div className="min-w-0 flex-1">
              <h3 id={`ch-${ch.id}`} className="truncate text-sm font-semibold text-ink">
                {ch.title}
              </h3>
              <p className="num text-xs text-ink-soft">{ch.lessons.length} bài</p>
            </div>
            <IconButton size="sm" label={`Sửa tên ${ch.title}`} icon={<IconPencil size={16} />} />
            <IconButton size="sm" label={`Xoá ${ch.title}`} icon={<IconTrash size={16} />} className="hover:bg-danger-soft hover:text-danger" />
            <Button size="sm" variant="ghost" leadingIcon={<IconPlus size={16} />} className="hidden sm:inline-flex">
              Thêm bài
            </Button>
          </header>
          {ch.lessons.length === 0 ? (
            <div className="flex flex-col items-start gap-2 px-4 py-4 text-sm text-ink-soft sm:flex-row sm:items-center">
              <span className="flex-1">Chương chưa có bài học.</span>
              <Button size="sm" variant="soft" leadingIcon={<IconPlus size={16} />}>
                Thêm bài đầu tiên
              </Button>
            </div>
          ) : (
            <ol>
              {ch.lessons.map((l) => {
                const selected = l.id === selectedId;
                return (
                  <li key={l.id} className={cx("flex items-center gap-1 border-b border-line pl-2 last:border-b-0 sm:pl-3", selected && "bg-primary-soft")}>
                    <button type="button" aria-label={`Kéo để đổi thứ tự: ${l.title}`} className="focus-ring flex size-9 shrink-0 cursor-grab items-center justify-center rounded-control text-ink-soft hover:bg-sunken">
                      <IconGrip size={16} />
                    </button>
                    <Link
                      href={lessonHref(l.id)}
                      scroll={false}
                      aria-current={selected ? "true" : undefined}
                      className="focus-ring flex min-h-12 min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1 rounded-control py-2 pr-3"
                    >
                      <span className={cx("min-w-0 flex-1 basis-48 text-sm", selected ? "font-semibold text-primary" : "font-medium text-ink")}>{l.title}</span>
                      <span className="flex items-center gap-2">
                        {l.is_preview ? (
                          <Badge tone="primary" size="sm">
                            Học thử
                          </Badge>
                        ) : null}
                        <VideoStatusBadge lesson={l} />
                        <span className="num w-12 text-right text-xs text-ink-soft">{l.duration_seconds ? formatClock(l.duration_seconds) : "—"}</span>
                        <IconChevronRight size={16} className="text-ink-soft" />
                      </span>
                    </Link>
                  </li>
                );
              })}
            </ol>
          )}
        </section>
      ))}
      <div>
        <Button variant="secondary" size="sm" leadingIcon={<IconPlus size={16} />}>
          Thêm chương
        </Button>
      </div>
    </div>
  );
}
