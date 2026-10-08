/* eslint-disable react-hooks/refs -- false positive: useSortable của dnd-kit trả setNodeRef + listeners, không đọc ref lúc render */
"use client";

import Link from "next/link";
import type { CSSProperties } from "react";
import { DndContext, KeyboardSensor, PointerSensor, closestCenter, useSensor, useSensors, type DragEndEvent, type UniqueIdentifier } from "@dnd-kit/core";
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { Badge, IconChevronLeft, IconGrip, IconPencil, cx } from "@vitaminvui/ui/v2";
import { moveItem, parseQuestionDndId, questionDndId } from "@/lib/quiz/order";
import { LETTERS, type QuizQuestion } from "@/lib/quiz/types";
import { MathText } from "./MathText";

export interface QuestionListProps {
  questions: QuizQuestion[];
  caseHref: (id: number) => string;
  /** Đang lưu thứ tự: khoá kéo-thả và nút để không gửi hai yêu cầu chồng nhau. */
  locked: boolean;
  /** Thứ tự mới (toàn bộ id). */
  onReorder: (ids: number[]) => void;
}

const translate = (t: { x: number; y: number } | null): CSSProperties | undefined => (t ? { transform: `translate3d(${Math.round(t.x)}px, ${Math.round(t.y)}px, 0)` } : undefined);
const BTN = "focus-ring relative inline-flex size-9 shrink-0 items-center justify-center rounded-control text-ink-soft before:absolute before:-inset-1 before:content-[''] hover:bg-sunken aria-disabled:cursor-not-allowed aria-disabled:opacity-40 max-sm:size-11";

/** Nút dùng aria-disabled (không `disabled`) để giữ focus bàn phím khi đang lưu hoặc ở đầu/cuối danh sách (WCAG 2.4.3). */
/** Danh sách câu kéo-thả được (@dnd-kit, như cây chương/bài FA4): chuột/cảm ứng, phím (cách → mũi tên → cách) và nút Lên/Xuống. */
export function QuestionList({ questions, caseHref, locked, onReorder }: QuestionListProps) {
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );
  const ids = questions.map((q) => q.id);
  const indexOf = (id: UniqueIdentifier) => ids.indexOf(parseQuestionDndId(id) ?? -1);

  function move(from: number, to: number) {
    const next = moveItem(ids, from, to);
    if (next && !locked) onReorder(next);
  }
  function onDragEnd(e: DragEndEvent) {
    if (!e.over || locked) return;
    move(indexOf(e.active.id), indexOf(e.over.id));
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragEnd={onDragEnd}
      accessibility={{
        announcements: {
          onDragStart: ({ active }) => `Đã nhấc câu ${indexOf(active.id) + 1}/${ids.length}.`,
          onDragOver: ({ over }) => (over ? `Đang ở trên vị trí ${indexOf(over.id) + 1}/${ids.length}.` : undefined),
          onDragEnd: ({ active, over }) => (over ? `Đã thả câu ${indexOf(active.id) + 1} tại vị trí ${indexOf(over.id) + 1}/${ids.length}.` : "Đã thả câu về chỗ cũ."),
          onDragCancel: () => "Đã huỷ kéo câu.",
        },
        screenReaderInstructions: { draggable: "Nhấn phím cách để nhấc, dùng phím mũi tên để di chuyển, nhấn phím cách lần nữa để thả, Esc để huỷ." },
      }}
    >
      <SortableContext items={questions.map((q) => questionDndId(q.id))} strategy={verticalListSortingStrategy}>
        <ol className="mt-3 flex flex-col gap-3" data-testid="question-list" aria-busy={locked}>
          {questions.map((q, i) => (
            <Row key={q.id} q={q} i={i} total={questions.length} locked={locked} href={caseHref(q.id)} onMove={move} />
          ))}
        </ol>
      </SortableContext>
    </DndContext>
  );
}

function Row({ q, i, total, locked, href, onMove }: { q: QuizQuestion; i: number; total: number; locked: boolean; href: string; onMove: (from: number, to: number) => void }) {
  const s = useSortable({ id: questionDndId(q.id), disabled: locked });
  const right = q.options.find((o) => o.is_correct);
  const letter = right ? (LETTERS[right.position - 1] ?? "?") : "?";
  return (
    <li
      ref={s.setNodeRef}
      style={{ ...translate(s.transform), transition: s.transition }}
      className={cx("rounded-card border bg-surface p-4 hover:border-primary", s.isDragging ? "relative z-10 border-primary shadow-raised" : "border-line")}
    >
      <div className="flex items-start gap-2 sm:gap-3">
        <button
          type="button"
          ref={s.setActivatorNodeRef}
          aria-label={`Kéo để đổi thứ tự: câu ${i + 1}`}
          className={cx(BTN, "mt-0 cursor-grab touch-none active:cursor-grabbing")}
          {...s.attributes}
          {...s.listeners}
        >
          <IconGrip size={18} />
        </button>
        <span className="num mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-sunken text-sm font-extrabold text-ink" aria-hidden="true">
          {i + 1}
        </span>
        <div className="flex min-w-0 flex-1 flex-col gap-2">
          <h2 className="sr-only">Câu {i + 1}</h2>
          <MathText content={q.content} className="text-base text-ink" />
          <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink">
            <span className="font-semibold text-success">Đáp án đúng {letter}:</span>
            {right ? <MathText as="span" content={right.content} /> : null}
            <Badge size="sm" tone="neutral">
              {q.explanation ? "Có lời giải" : "Chưa có lời giải"}
            </Badge>
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap items-center justify-end gap-0.5">
          <button type="button" className={BTN} aria-disabled={locked || i === 0 || undefined} aria-label={`Chuyển câu ${i + 1} lên`} onClick={() => !locked && onMove(i, i - 1)}>
            <IconChevronLeft size={18} className="rotate-90" />
          </button>
          <button type="button" className={BTN} aria-disabled={locked || i === total - 1 || undefined} aria-label={`Chuyển câu ${i + 1} xuống`} onClick={() => !locked && onMove(i, i + 1)}>
            <IconChevronLeft size={18} className="-rotate-90" />
          </button>
          {locked ? (
            <span aria-disabled="true" className="inline-flex h-9 items-center gap-1 rounded-control px-2 text-sm font-semibold text-ink-soft opacity-60 max-sm:h-11">
              <IconPencil size={16} />
              Sửa<span className="sr-only"> câu {i + 1} (đang lưu thứ tự)</span>
            </span>
          ) : (
            <Link href={href} className="focus-ring inline-flex h-9 items-center gap-1 rounded-control px-2 text-sm font-semibold text-primary hover:bg-primary-soft max-sm:h-11">
              <IconPencil size={16} />
              Sửa<span className="sr-only"> câu {i + 1}</span>
            </Link>
          )}
        </div>
      </div>
    </li>
  );
}
