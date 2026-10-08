import type { LearnCourse, QuizSummary } from "@/lib/learn/schemas";

export interface QuizPlacement {
  quiz: QuizSummary;
  /** Bài học chứa quiz (quiz gắn vào bài); `null` nếu quiz gắn vào chương. */
  lessonId: number | null;
  /** Bài học nên mở sau khi xong quiz (kế tiếp theo thứ tự mục lục); `null` nếu đã hết bài. */
  nextLessonId: number | null;
}

/** Tìm quiz trong mục lục khóa học và bài tiếp theo sau nó. */
export function findQuizPlacement(outline: LearnCourse, quizId: number): QuizPlacement | null {
  const flat: Array<{ lessonId: number; chapterId: number }> = [];
  for (const ch of outline.chapters) for (const l of ch.lessons) flat.push({ lessonId: l.id, chapterId: ch.id });

  for (const ch of outline.chapters) {
    for (const l of ch.lessons) {
      const quiz = l.quizzes.find((q) => q.id === quizId);
      if (quiz) {
        const i = flat.findIndex((f) => f.lessonId === l.id);
        return { quiz, lessonId: l.id, nextLessonId: flat[i + 1]?.lessonId ?? null };
      }
    }
    const quiz = ch.quizzes.find((q) => q.id === quizId);
    if (quiz) {
      // Quiz chương: bài kế tiếp là bài đầu tiên của chương sau (bỏ qua chương trống).
      const ci = outline.chapters.findIndex((c) => c.id === ch.id);
      const after = outline.chapters.slice(ci + 1).flatMap((c) => c.lessons)[0];
      return { quiz, lessonId: null, nextLessonId: after?.id ?? null };
    }
  }
  return null;
}

/** Điểm thang 10 hiển thị lời động viên (design-system-v2 §12.4). */
export function scoreHeadline(score: number): string {
  if (score >= 8) return "Làm tốt lắm!";
  if (score >= 5) return "Khá rồi, xem lại câu sai nhé";
  return "Cùng xem lại bài nhé";
}

export type ResultFilter = "sai" | "bo-trong";

export function parseResultFilter(raw: string | string[] | undefined): ResultFilter | undefined {
  const v = Array.isArray(raw) ? raw[0] : raw;
  return v === "sai" || v === "bo-trong" ? v : undefined;
}

/** `?lan=` là số nguyên dương; sai định dạng bị bỏ qua (dùng lượt đã nộp gần nhất). */
export function parseAttemptId(raw: string | string[] | undefined): number | undefined {
  const v = Array.isArray(raw) ? raw[0] : raw;
  return v !== undefined && /^[1-9]\d{0,9}$/.test(v) ? Number(v) : undefined;
}

export function parseId(raw: string): number | null {
  return /^[1-9]\d{0,9}$/.test(raw) ? Number(raw) : null;
}
