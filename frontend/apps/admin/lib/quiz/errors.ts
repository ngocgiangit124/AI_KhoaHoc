import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";

export const QUIZ_FORBIDDEN_MESSAGE = "Bạn không có quyền sửa bài tập của khóa học này.";
export const QUIZ_GONE_MESSAGE = "Mục này không còn tồn tại (có thể vừa bị xoá ở nơi khác).";

export const isForbidden = (err: unknown) => err instanceof ApiError && err.status === 403;
export const isGone = (err: unknown) => err instanceof ApiError && err.status === 404;
export const isQuestionsMismatch = (err: unknown) => err instanceof ApiError && err.status === 422 && err.code === "QUIZ_QUESTIONS_MISMATCH";
export const isValidation = (err: unknown) => err instanceof ApiError && err.status === 422 && err.code !== "QUIZ_QUESTION_LIMIT" && err.code !== "QUIZ_PARENT_INVALID";

/** Thông điệp cho banner/toast của mọi thao tác quiz và câu hỏi. */
export function quizError(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (!(err instanceof ApiError)) return UNKNOWN_ERROR_MESSAGE;
  switch (err.code) {
    case "QUIZ_QUESTION_LIMIT":
      return "Mỗi bài tập tối đa 200 câu hỏi. Hãy tạo bài tập mới cho phần còn lại.";
    case "QUIZ_QUESTIONS_MISMATCH":
      return "Danh sách câu hỏi vừa được thay đổi ở nơi khác (có người thêm, xoá hoặc đổi thứ tự). Đã tải lại danh sách mới, hãy sắp xếp lại.";
    case "QUIZ_PARENT_INVALID":
      return "Chương hoặc bài học đã chọn không còn tồn tại trong khóa học. Hãy chọn lại.";
  }
  if (err.status === 403) return QUIZ_FORBIDDEN_MESSAGE;
  if (err.status === 404) return QUIZ_GONE_MESSAGE;
  if (err.status === 409) return "Dữ liệu vừa được thay đổi ở nơi khác. Hãy tải lại trang rồi thử lại.";
  if (err.status === 429) return `Bạn thao tác quá nhanh. Hãy thử lại sau ${err.retryAfterSeconds ?? 60} giây.`;
  return err.message || UNKNOWN_ERROR_MESSAGE;
}

/** Khoá lỗi của form câu hỏi: `content`, `explanation`, `o0..o3` (nội dung đáp án), `correct` (đáp án đúng). */
export type QuestionErrorKey = "content" | "explanation" | "correct" | "o0" | "o1" | "o2" | "o3";
export type QuestionErrors = Partial<Record<QuestionErrorKey, string>>;

/** Gom lỗi 422 của câu hỏi về ô tương ứng. Trả `unmapped` cho lỗi không thuộc ô nào (hiện ở banner). */
export function questionFieldErrors(err: unknown): { fields: QuestionErrors; unmapped: string | null } {
  const fields: QuestionErrors = {};
  let unmapped: string | null = null;
  if (!(err instanceof ApiError) || err.status !== 422) return { fields, unmapped: null };
  if (!err.errors) return { fields, unmapped: err.code === "QUIZ_OPTIONS_INVALID" ? "Mỗi câu hỏi phải có đúng 4 đáp án và đúng 1 đáp án đúng." : null };
  for (const [key, msgs] of Object.entries(err.errors)) {
    const msg = msgs[0];
    if (!msg) continue;
    const m = /^options\.(\d+)\.(content|is_correct)$/.exec(key);
    if (key === "content" || key === "explanation") fields[key] ??= msg;
    else if (key === "options" || (m && m[2] === "is_correct")) fields.correct ??= msg;
    else if (m && m[1] !== undefined && Number(m[1]) < 4) fields[`o${m[1]}` as QuestionErrorKey] ??= msg;
    else unmapped ??= msg;
  }
  return { fields, unmapped };
}

export type QuizFormErrors = { title?: string; parent?: string; time?: string };

/** Lỗi 422 của form thông tin quiz → ô tương ứng. `QUIZ_PARENT_INVALID` hiện dưới ô "Gắn với". */
export function quizFieldErrors(err: unknown): { fields: QuizFormErrors; unmapped: string | null } {
  const fields: QuizFormErrors = {};
  if (!(err instanceof ApiError) || err.status !== 422) return { fields, unmapped: null };
  if (err.code === "QUIZ_PARENT_INVALID") return { fields: { parent: quizError(err) }, unmapped: null };
  let unmapped: string | null = null;
  for (const [key, msgs] of Object.entries(err.errors ?? {})) {
    const msg = msgs[0];
    if (!msg) continue;
    if (key === "title") fields.title ??= msg;
    else if (key === "chapter_id" || key === "lesson_id") fields.parent ??= msg;
    else if (key === "time_limit_minutes") fields.time ??= msg;
    else unmapped ??= msg;
  }
  if (!Object.keys(fields).length && !unmapped && err.code === "QUIZ_QUESTION_LIMIT") unmapped = quizError(err);
  return { fields, unmapped };
}
