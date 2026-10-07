import type { Chapter, Lesson } from "./types";

export const chapterDndId = (id: number) => `chapter:${id}`;
export const lessonDndId = (id: number) => `lesson:${id}`;
/** Vùng thả của một chương (để thả bài vào chương rỗng hoặc xuống cuối chương). */
export const dropDndId = (id: number) => `drop:${id}`;

export type ParsedId = { kind: "chapter" | "lesson" | "drop"; id: number };

export function parseDndId(raw: unknown): ParsedId | null {
  if (typeof raw !== "string") return null;
  const m = /^(chapter|lesson|drop):(\d+)$/.exec(raw);
  return m ? { kind: m[1] as ParsedId["kind"], id: Number(m[2]) } : null;
}

function arrayMove<T>(list: readonly T[], from: number, to: number): T[] {
  const next = list.slice();
  const [item] = next.splice(from, 1);
  if (item === undefined) return next;
  next.splice(to, 0, item);
  return next;
}

/** Đánh lại `position`/`chapter_id` theo vị trí mới (khớp phản hồi của API: 1..n). */
function renumber(chapters: Chapter[]): Chapter[] {
  return chapters.map((c, ci) => ({
    ...c,
    position: ci + 1,
    lessons: c.lessons.map((l, li) => ({ ...l, chapter_id: c.id, position: li + 1 })),
  }));
}

export function moveChapter(chapters: Chapter[], chapterId: number, toIndex: number): Chapter[] | null {
  const from = chapters.findIndex((c) => c.id === chapterId);
  if (from < 0 || toIndex < 0 || toIndex >= chapters.length || from === toIndex) return null;
  return renumber(arrayMove(chapters, from, toIndex));
}

/** Chuyển bài tới chương `toChapterId` ở vị trí `toIndex` (có thể khác chương). `toIndex` tính sau khi đã gỡ bài khỏi chương cũ. */
export function moveLesson(chapters: Chapter[], lessonId: number, toChapterId: number, toIndex: number): Chapter[] | null {
  const src = chapters.find((c) => c.lessons.some((l) => l.id === lessonId));
  const dst = chapters.find((c) => c.id === toChapterId);
  if (!src || !dst) return null;
  const lesson = src.lessons.find((l) => l.id === lessonId) as Lesson;
  const from = src.lessons.indexOf(lesson);
  const without = src.lessons.filter((l) => l.id !== lessonId);
  const clamped = Math.max(0, Math.min(toIndex, (src.id === dst.id ? without : dst.lessons).length));
  if (src.id === dst.id && clamped === from) return null;
  const next = chapters.map((c) => {
    if (c.id === src.id && c.id === dst.id) return { ...c, lessons: [...without.slice(0, clamped), lesson, ...without.slice(clamped)] };
    if (c.id === src.id) return { ...c, lessons: without };
    if (c.id === dst.id) return { ...c, lessons: [...c.lessons.slice(0, clamped), lesson, ...c.lessons.slice(clamped)] };
    return c;
  });
  return renumber(next);
}

/** Kết quả kéo-thả: `active` thả lên `over`. Trả `null` nếu không có gì đổi. */
export function applyDrop(chapters: Chapter[], activeRaw: unknown, overRaw: unknown): Chapter[] | null {
  const active = parseDndId(activeRaw);
  const over = parseDndId(overRaw);
  if (!active || !over) return null;
  if (active.kind === "chapter") {
    const overChapterId =
      over.kind === "lesson" ? chapters.find((c) => c.lessons.some((l) => l.id === over.id))?.id : over.id;
    if (overChapterId === undefined) return null;
    const to = chapters.findIndex((c) => c.id === overChapterId);
    return moveChapter(chapters, active.id, to);
  }
  if (active.kind === "lesson") {
    if (over.kind === "lesson") {
      if (over.id === active.id) return null;
      const dst = chapters.find((c) => c.lessons.some((l) => l.id === over.id));
      if (!dst) return null;
      const overIdx = dst.lessons.findIndex((l) => l.id === over.id);
      return moveLesson(chapters, active.id, dst.id, overIdx);
    }
    const dst = chapters.find((c) => c.id === over.id);
    if (!dst) return null;
    // Thả vào vùng chương: về cuối chương (cùng chương: trừ bài đang kéo).
    const own = dst.lessons.some((l) => l.id === active.id);
    return moveLesson(chapters, active.id, dst.id, own ? dst.lessons.length - 1 : dst.lessons.length);
  }
  return null;
}

/** Nút Lên/Xuống (bàn phím): đổi chỗ với bài kề; ở rìa thì sang cuối chương trước/đầu chương sau. */
export function shiftLesson(chapters: Chapter[], lessonId: number, dir: -1 | 1): Chapter[] | null {
  const ci = chapters.findIndex((c) => c.lessons.some((l) => l.id === lessonId));
  if (ci < 0) return null;
  const chapter = chapters[ci]!;
  const li = chapter.lessons.findIndex((l) => l.id === lessonId);
  const target = li + dir;
  if (target >= 0 && target < chapter.lessons.length) return moveLesson(chapters, lessonId, chapter.id, target);
  const next = chapters[ci + dir];
  if (!next) return null;
  return moveLesson(chapters, lessonId, next.id, dir === -1 ? next.lessons.length : 0);
}

export function canShiftLesson(chapters: Chapter[], lessonId: number, dir: -1 | 1): boolean {
  return shiftLesson(chapters, lessonId, dir) !== null;
}

export function toOrderPayload(chapters: Chapter[]): Array<{ chapter_id: number; lesson_ids: number[] }> {
  return chapters.map((c) => ({ chapter_id: c.id, lesson_ids: c.lessons.map((l) => l.id) }));
}

export function findLesson(chapters: Chapter[], lessonId: number | null): Lesson | null {
  if (lessonId === null) return null;
  for (const c of chapters) {
    const l = c.lessons.find((x) => x.id === lessonId);
    if (l) return l;
  }
  return null;
}

export function countLessons(chapters: Chapter[]): number {
  return chapters.reduce((n, c) => n + c.lessons.length, 0);
}
