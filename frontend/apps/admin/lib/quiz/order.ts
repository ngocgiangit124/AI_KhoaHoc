import type { QuizQuestion } from "./types";

/** Di chuyển phần tử từ `from` tới `to` (trả mảng mới; cùng chỗ hoặc ngoài khoảng → null). */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] | null {
  if (from === to || from < 0 || to < 0 || from >= list.length || to >= list.length) return null;
  const next = list.slice();
  const [item] = next.splice(from, 1);
  next.splice(to, 0, item as T);
  return next;
}

/** Gán lại `position` 1..n theo thứ tự mới (khớp phản hồi của API sau khi đổi thứ tự). */
export function renumberQuestions(list: QuizQuestion[]): QuizQuestion[] {
  return list.map((q, i) => ({ ...q, position: i + 1 }));
}

export const questionDndId = (id: number) => `question:${id}`;
export function parseQuestionDndId(raw: unknown): number | null {
  if (typeof raw !== "string") return null;
  const m = /^question:(\d+)$/.exec(raw);
  return m ? Number(m[1]) : null;
}
