/* eslint-disable react-hooks/refs -- false positive: useSortable/useDroppable của dnd-kit trả setNodeRef + listeners, không đọc ref lúc render */
"use client";

import Link from "next/link";
import type { CSSProperties } from "react";
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  pointerWithin,
  useDroppable,
  useSensor,
  useSensors,
  type CollisionDetection,
  type DragEndEvent,
  type UniqueIdentifier,
} from "@dnd-kit/core";
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { Badge, Button, IconButton, IconChevronRight, IconGrip, IconPencil, IconPlus, IconTrash, cx, formatClock } from "@vitaminvui/ui/v2";
import { VideoStatusBadge } from "@/components/v2/CourseStatusBadge";
import { applyDrop, chapterDndId, dropDndId, lessonDndId, parseDndId } from "@/lib/curriculum/order";
import type { Chapter, Lesson } from "@/lib/curriculum/types";
import type { UploadState } from "./useUploadManager";

export interface ChapterTreeProps {
  chapters: Chapter[];
  selectedId: number | null;
  /** Tiến độ tải lên cục bộ theo bài (để hiện % ở nhãn trạng thái). */
  uploads: Readonly<Record<number, UploadState>>;
  /** Đang lưu thứ tự: khoá kéo-thả để không gửi hai yêu cầu chồng nhau. */
  locked: boolean;
  lessonHref: (lessonId: number) => string;
  onSelectLesson: (lessonId: number, e: React.MouseEvent) => void;
  onReorder: (next: Chapter[]) => void;
  onAddChapter: () => void;
  onRenameChapter: (chapter: Chapter) => void;
  onDeleteChapter: (chapter: Chapter) => void;
  onAddLesson: (chapter: Chapter) => void;
}

const kindOf = (id: UniqueIdentifier) => parseDndId(id)?.kind ?? null;

/** Chương chỉ thả lên chương; bài thả lên bài hoặc vùng của chương. Ưu tiên phần tử con nằm dưới con trỏ. */
const collisionDetection: CollisionDetection = (args) => {
  const activeKind = kindOf(args.active.id);
  const wanted = activeKind === "chapter" ? ["chapter"] : ["lesson", "drop"];
  const containers = args.droppableContainers.filter((c) => wanted.includes(kindOf(c.id) ?? ""));
  const scoped = { ...args, droppableContainers: containers };
  const within = pointerWithin(scoped);
  if (within.length > 0) {
    const lesson = within.find((c) => kindOf(c.id) === "lesson");
    return [lesson ?? within[0]!];
  }
  return closestCenter(scoped);
};

const translate = (t: { x: number; y: number } | null): CSSProperties | undefined => (t ? { transform: `translate3d(${Math.round(t.x)}px, ${Math.round(t.y)}px, 0)` } : undefined);

const TOUCH = "relative before:absolute before:-inset-1 before:content-['']";
const GRIP = "focus-ring relative flex size-9 before:absolute before:-inset-1 before:content-['']  shrink-0 cursor-grab touch-none items-center justify-center rounded-control text-ink-soft hover:bg-sunken active:cursor-grabbing disabled:cursor-not-allowed disabled:opacity-50";

function position(chapters: Chapter[], id: UniqueIdentifier | undefined): string {
  const p = parseDndId(id);
  if (!p) return "";
  if (p.kind === "chapter") {
    const i = chapters.findIndex((c) => c.id === p.id);
    return i >= 0 ? `vị trí ${i + 1}/${chapters.length}` : "";
  }
  if (p.kind === "drop") {
    const c = chapters.find((x) => x.id === p.id);
    return c ? `cuối chương «${c.title}»` : "";
  }
  for (const c of chapters) {
    const i = c.lessons.findIndex((l) => l.id === p.id);
    if (i >= 0) return `vị trí ${i + 1}/${c.lessons.length} trong chương «${c.title}»`;
  }
  return "";
}
function labelFor(id: UniqueIdentifier): string {
  return kindOf(id) === "chapter" ? "chương" : "bài học";
}
/** Thông báo cho trình đọc màn hình (tiếng Việt, có vị trí/đích). */
export function makeAnnouncements(chapters: Chapter[]) {
  return {
    onDragStart: ({ active }: { active: { id: UniqueIdentifier } }) => `Đã nhấc ${labelFor(active.id)}, đang ở ${position(chapters, active.id)}.`,
    onDragOver: ({ active, over }: { active: { id: UniqueIdentifier }; over: { id: UniqueIdentifier } | null }) =>
      over ? `${labelFor(active.id)} đang ở trên ${position(chapters, over.id)}.` : undefined,
    onDragEnd: ({ active, over }: { active: { id: UniqueIdentifier }; over: { id: UniqueIdentifier } | null }) =>
      over ? `Đã thả ${labelFor(active.id)} tại ${position(chapters, over.id)}.` : `Đã thả ${labelFor(active.id)} về chỗ cũ.`,
    onDragCancel: ({ active }: { active: { id: UniqueIdentifier } }) => `Đã huỷ kéo ${labelFor(active.id)}.`,
  };
}

export function ChapterTree(props: ChapterTreeProps) {
  const { chapters, locked, onReorder, onAddChapter } = props;
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  function onDragEnd(e: DragEndEvent) {
    if (!e.over || locked) return;
    const next = applyDrop(chapters, e.active.id, e.over.id);
    if (next) onReorder(next);
  }

  return (
    <div className="flex flex-col gap-3">
      <DndContext
        sensors={sensors}
        collisionDetection={collisionDetection}
        onDragEnd={onDragEnd}
        accessibility={{
          announcements: makeAnnouncements(chapters),
          screenReaderInstructions: {
            draggable: "Nhấn phím cách để nhấc, dùng phím mũi tên để di chuyển, nhấn phím cách lần nữa để thả, Esc để huỷ.",
          },
        }}
      >
        <SortableContext items={chapters.map((c) => chapterDndId(c.id))} strategy={verticalListSortingStrategy}>
          {chapters.map((chapter) => (
            <ChapterSection key={chapter.id} chapter={chapter} {...props} />
          ))}
        </SortableContext>
      </DndContext>
      <div>
        <Button variant="secondary" size="sm" className="max-sm:h-11" leadingIcon={<IconPlus size={16} />} onClick={onAddChapter}>
          Thêm chương
        </Button>
      </div>
    </div>
  );
}

function ChapterSection({ chapter, ...props }: { chapter: Chapter } & ChapterTreeProps) {
  const { locked, uploads, selectedId, lessonHref, onSelectLesson, onRenameChapter, onDeleteChapter, onAddLesson } = props;
  const sortable = useSortable({ id: chapterDndId(chapter.id), disabled: locked });
  const drop = useDroppable({ id: dropDndId(chapter.id) });
  const headingId = `ch-${chapter.id}`;

  return (
    <section
      ref={sortable.setNodeRef}
      aria-labelledby={headingId}
      style={{ ...translate(sortable.transform), transition: sortable.transition }}
      className={cx("rounded-card border border-line bg-surface", sortable.isDragging && "relative z-10 opacity-70 shadow-raised")}
    >
      <header className="flex items-center gap-2 border-b border-line px-2 py-2 sm:px-3">
        <button
          type="button"
          ref={sortable.setActivatorNodeRef}
          {...sortable.attributes}
          {...sortable.listeners}
          disabled={locked}
          aria-roledescription="có thể sắp xếp"
          aria-label={`Kéo để đổi thứ tự: ${chapter.title}`}
          className={GRIP}
        >
          <IconGrip size={18} />
        </button>
        <div className="min-w-0 flex-1">
          <h3 id={headingId} className="break-words text-sm font-semibold text-ink">
            {chapter.title}
          </h3>
          <p className="num text-xs text-ink-soft">{chapter.lessons.length} bài</p>
        </div>
        <IconButton size="sm" label={`Sửa tên ${chapter.title}`} icon={<IconPencil size={16} />} className={TOUCH} onClick={() => onRenameChapter(chapter)} />
        <IconButton
          size="sm"
          label={`Xoá ${chapter.title}`}
          icon={<IconTrash size={16} />}
          className={cx(TOUCH, "hover:bg-danger-soft hover:text-danger")}
          onClick={() => onDeleteChapter(chapter)}
        />
        <Button size="sm" variant="ghost" leadingIcon={<IconPlus size={16} />} className="max-sm:hidden sm:inline-flex" onClick={() => onAddLesson(chapter)}>
          Thêm bài
        </Button>
      </header>
      <div ref={drop.setNodeRef} className={cx(drop.isOver && "bg-primary-soft/40")}>
        {chapter.lessons.length === 0 ? (
          <div className="flex flex-col items-start gap-2 px-4 py-4 text-sm text-ink-soft sm:flex-row sm:items-center">
            <span className="flex-1">Chương chưa có bài học. Kéo một bài vào đây hoặc thêm bài mới.</span>
            <Button size="sm" variant="soft" className="max-sm:h-11" leadingIcon={<IconPlus size={16} />} onClick={() => onAddLesson(chapter)}>
              Thêm bài đầu tiên
            </Button>
          </div>
        ) : (
          <SortableContext items={chapter.lessons.map((l) => lessonDndId(l.id))} strategy={verticalListSortingStrategy}>
            <ol>
              {chapter.lessons.map((lesson) => (
                <LessonRow
                  key={lesson.id}
                  lesson={lesson}
                  selected={lesson.id === selectedId}
                  upload={uploads[lesson.id]}
                  locked={locked}
                  href={lessonHref(lesson.id)}
                  onSelect={onSelectLesson}
                />
              ))}
            </ol>
            <div className="border-t border-line p-1 sm:hidden">
              <Button size="sm" variant="ghost" className="max-sm:h-11" leadingIcon={<IconPlus size={16} />} onClick={() => onAddLesson(chapter)}>
                Thêm bài
              </Button>
            </div>
          </SortableContext>
        )}
      </div>
    </section>
  );
}

function LessonRow({
  lesson,
  selected,
  upload,
  locked,
  href,
  onSelect,
}: {
  lesson: Lesson;
  selected: boolean;
  upload: UploadState | undefined;
  locked: boolean;
  href: string;
  onSelect: (lessonId: number, e: React.MouseEvent) => void;
}) {
  const sortable = useSortable({ id: lessonDndId(lesson.id), disabled: locked });
  const percent = upload && (upload.phase === "uploading" || upload.phase === "paused") ? upload.percent : upload?.phase === "starting" ? 0 : undefined;
  const badgeLesson = upload && upload.phase !== "failed" ? { ...lesson, video_source: "upload" as const, video_status: "uploading" as const, upload_percent: percent } : lesson;

  return (
    <li
      ref={sortable.setNodeRef}
      style={{ ...translate(sortable.transform), transition: sortable.transition }}
      className={cx(
        "flex items-center gap-1 border-b border-line bg-surface pl-2 last:border-b-0 sm:pl-3",
        selected && "bg-primary-soft",
        sortable.isDragging && "relative z-10 opacity-70 shadow-raised",
      )}
    >
      <button
        type="button"
        ref={sortable.setActivatorNodeRef}
        {...sortable.attributes}
        {...sortable.listeners}
        disabled={locked}
        aria-roledescription="có thể sắp xếp"
        aria-label={`Kéo để đổi thứ tự: ${lesson.title}`}
        className={GRIP}
      >
        <IconGrip size={16} />
      </button>
      <Link
        href={href}
        scroll={false}
        onClick={(e) => onSelect(lesson.id, e)}
        aria-current={selected ? "true" : undefined}
        className="focus-ring flex min-h-12 min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1 rounded-control py-2 pr-3"
      >
        <span className={cx("min-w-0 flex-1 basis-48 break-words text-sm", selected ? "font-semibold text-primary" : "font-medium text-ink")}>{lesson.title}</span>
        <span className="flex items-center gap-2">
          {lesson.is_preview ? (
            <Badge tone="primary" size="sm">
              Học thử
            </Badge>
          ) : null}
          <VideoStatusBadge lesson={badgeLesson} />
          <span className="num w-12 text-right text-xs text-ink-soft">{lesson.duration_seconds ? formatClock(lesson.duration_seconds) : "—"}</span>
          <IconChevronRight size={16} className="text-ink-soft" />
        </span>
      </Link>
    </li>
  );
}
