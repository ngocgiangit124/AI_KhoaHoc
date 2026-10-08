import { LETTERS, QUIZ_LIMITS, type QuestionPayload, type QuizQuestion } from "./types";
import type { QuestionErrors } from "./errors";

/** Đoạn chèn nhanh: chèn vào ô đang soạn, đặt con trỏ tại `|`. */
export const SNIPPETS: ReadonlyArray<{ label: string; sr: string; text: string }> = [
  { label: "$x$", sr: "Công thức trong dòng", text: "$|$" },
  { label: "$$x$$", sr: "Công thức riêng dòng", text: "\n$$|$$\n" },
  { label: "a/b", sr: "Phân số", text: "\\dfrac{|}{}" },
  { label: "√", sr: "Căn bậc hai", text: "\\sqrt{|}" },
  { label: "x²", sr: "Số mũ", text: "^{|}" },
  { label: "°", sr: "Độ", text: "^\\circ|" },
  { label: "π", sr: "Pi", text: "\\pi|" },
  { label: "≠", sr: "Khác", text: "\\ne |" },
  { label: "≤", sr: "Nhỏ hơn hoặc bằng", text: "\\le |" },
  { label: "≥", sr: "Lớn hơn hoặc bằng", text: "\\ge |" },
  { label: "\\lt", sr: "Dấu nhỏ hơn", text: "\\lt |" },
  { label: "\\gt", sr: "Dấu lớn hơn", text: "\\gt |" },
];

/** Chèn `snippet` vào `value` tại [start, end); trả giá trị mới và vị trí con trỏ. */
export function insertSnippet(value: string, start: number, end: number, snippet: string): { value: string; caret: number } {
  const marker = snippet.indexOf("|");
  const text = snippet.replace("|", "");
  return { value: value.slice(0, start) + text + value.slice(end), caret: start + (marker >= 0 ? marker : text.length) };
}

/** Giống `App\Rules\QuizText` (T21): thẻ HTML (`<` liền chữ, `/`, `!`, `?`), ký tự điều khiển, bidi/độ rộng 0. */
const HTML_LIKE = /<[A-Za-z/!?]/;
const BIDI_ZERO_WIDTH = /[‪-‮⁦-⁩​-‏⁠﻿]/;
const CONTROL = /[\u0000-\u001F\u007F-\u009F]/;

export const HTML_MESSAGE = "Có đoạn giống thẻ HTML (dấu < viết sát chữ). Trong công thức hãy dùng \\lt và \\gt, ví dụ $a \\lt b$.";
export const CONTROL_MESSAGE = "Có ký tự đặc biệt không hợp lệ (ký tự điều khiển hoặc ký tự ẩn). Hãy xoá và gõ lại đoạn này.";

/** Lỗi của một đoạn văn bản quiz theo luật server, hoặc `undefined` nếu hợp lệ. */
export function textProblem(s: string): string | undefined {
  if (HTML_LIKE.test(s)) return HTML_MESSAGE;
  if (BIDI_ZERO_WIDTH.test(s) || CONTROL.test(s.replace(/[\n\r\t]/g, ""))) return CONTROL_MESSAGE;
  return undefined;
}

/** Số dấu `$` (không tính `\$`) lẻ → thiếu dấu đóng công thức. Chỉ là cảnh báo, server không từ chối. */
export function hasOddDollar(s: string): boolean {
  return ((s.replace(/\\\$/g, "").match(/\$/g) ?? []).length) % 2 === 1;
}
export const ODD_DOLLAR_WARNING = "Thiếu một dấu $ để đóng công thức.";

export interface QuestionDraft {
  content: string;
  explanation: string;
  options: string[];
  correct: number | null;
}

export function emptyDraft(): QuestionDraft {
  return { content: "", explanation: "", options: ["", "", "", ""], correct: null };
}

export function draftFromQuestion(q: QuizQuestion): QuestionDraft {
  const options = [...q.options].sort((a, b) => a.position - b.position);
  const ok = options.map((o, i) => (o.is_correct ? i : -1)).filter((i) => i >= 0);
  return { content: q.content, explanation: q.explanation ?? "", options: LETTERS.map((_, i) => options[i]?.content ?? ""), correct: ok.length === 1 ? (ok[0] ?? null) : null };
}

export function sameDraft(a: QuestionDraft, b: QuestionDraft): boolean {
  return a.content === b.content && a.explanation === b.explanation && a.correct === b.correct && a.options.every((o, i) => o === b.options[i]);
}

/** Kiểm tại chỗ như server (T21); rỗng = gửi được. */
export function validateDraft(d: QuestionDraft): QuestionErrors {
  const e: QuestionErrors = {};
  if (!d.content.trim()) e.content = "Vui lòng nhập nội dung câu hỏi.";
  else if (d.content.length > QUIZ_LIMITS.content) e.content = `Nội dung tối đa ${QUIZ_LIMITS.content} ký tự.`;
  else e.content = textProblem(d.content);
  d.options.forEach((o, i) => {
    const k = `o${i}` as "o0";
    if (!o.trim()) e[k] = `Vui lòng nhập nội dung đáp án ${LETTERS[i]}.`;
    else if (o.length > QUIZ_LIMITS.option) e[k] = `Đáp án tối đa ${QUIZ_LIMITS.option} ký tự.`;
    else e[k] = textProblem(o);
  });
  if (d.explanation) e.explanation = d.explanation.length > QUIZ_LIMITS.explanation ? `Lời giải tối đa ${QUIZ_LIMITS.explanation} ký tự.` : textProblem(d.explanation);
  if (d.correct === null) e.correct = "Chọn một đáp án đúng.";
  for (const k of Object.keys(e) as Array<keyof QuestionErrors>) if (e[k] === undefined) delete e[k];
  return e;
}

export function draftToPayload(d: QuestionDraft): QuestionPayload {
  return {
    content: d.content.trim(),
    explanation: d.explanation.trim() ? d.explanation.trim() : null,
    options: d.options.map((o, i) => ({ content: o.trim(), is_correct: d.correct === i })),
  };
}

/** Giờ hiển thị "Đã lưu lúc 20:15" theo múi giờ Việt Nam (cùng kết quả ở server và trình duyệt). */
export function clockVN(d: Date): string {
  return new Intl.DateTimeFormat("vi-VN", { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: "Asia/Ho_Chi_Minh" }).format(d);
}

/** Dạng thân gửi `PUT/POST quiz` từ giá trị ô "Gắn với" (`chapter:12` / `lesson:34`). */
export function parentFromValue(value: string): { chapter_id: number } | { lesson_id: number } | null {
  const m = /^(chapter|lesson):([1-9]\d*)$/.exec(value);
  if (!m) return null;
  return m[1] === "chapter" ? { chapter_id: Number(m[2]) } : { lesson_id: Number(m[2]) };
}
