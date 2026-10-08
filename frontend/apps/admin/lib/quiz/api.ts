import { authFetch } from "@/lib/api";
import { unwrapOne } from "@/lib/courses/api";
import type { QuestionPayload, QuizDetail, QuizItem, QuizPayload, QuizQuestion } from "./types";

const base = (courseId: number) => `/api/v1/admin/courses/${courseId}/quizzes`;
const JSON_HEADERS = { "Content-Type": "application/json" };
const json = (method: string, body: unknown) => ({ method, headers: JSON_HEADERS, body: JSON.stringify(body) });

export async function listQuizzes(courseId: number, signal?: AbortSignal): Promise<QuizItem[]> {
  const res = await authFetch<{ data: QuizItem[] }>(base(courseId), { signal });
  return res.data;
}

/** Chi tiết quiz kèm `questions` (có đáp án đúng; chỉ admin-api). */
export async function getQuiz(courseId: number, quizId: number, signal?: AbortSignal): Promise<QuizDetail> {
  const quiz = unwrapOne(await authFetch<QuizDetail | { data: QuizDetail }>(`${base(courseId)}/${quizId}`, { signal }));
  return { ...quiz, questions: quiz.questions ?? [] };
}

export async function createQuiz(courseId: number, payload: QuizPayload): Promise<QuizItem> {
  return unwrapOne(await authFetch<QuizItem | { data: QuizItem }>(base(courseId), json("POST", payload)));
}

export async function updateQuiz(courseId: number, quizId: number, payload: QuizPayload): Promise<QuizItem> {
  return unwrapOne(await authFetch<QuizItem | { data: QuizItem }>(`${base(courseId)}/${quizId}`, json("PUT", payload)));
}

export async function deleteQuiz(courseId: number, quizId: number): Promise<void> {
  await authFetch<void>(`${base(courseId)}/${quizId}`, { method: "DELETE" });
}

export async function createQuestion(courseId: number, quizId: number, payload: QuestionPayload): Promise<QuizQuestion> {
  return unwrapOne(await authFetch<QuizQuestion | { data: QuizQuestion }>(`${base(courseId)}/${quizId}/questions`, json("POST", payload)));
}

/** PUT luôn 200; câu đã có lượt làm trả `id` MỚI (copy-on-write): người gọi PHẢI dùng id trong response. */
export async function updateQuestion(courseId: number, quizId: number, questionId: number, payload: QuestionPayload): Promise<QuizQuestion> {
  return unwrapOne(await authFetch<QuizQuestion | { data: QuizQuestion }>(`${base(courseId)}/${quizId}/questions/${questionId}`, json("PUT", payload)));
}

export async function deleteQuestion(courseId: number, quizId: number, questionId: number): Promise<void> {
  await authFetch<void>(`${base(courseId)}/${quizId}/questions/${questionId}`, { method: "DELETE" });
}

/** `PUT .../questions/order` (SLN7): `question_ids` phải là TOÀN BỘ câu chưa xoá. 200 `{data:[câu theo thứ tự mới]}`; 422 `QUIZ_QUESTIONS_MISMATCH` → tải lại. */
export async function reorderQuestions(courseId: number, quizId: number, questionIds: number[]): Promise<QuizQuestion[]> {
  const res = await authFetch<{ data: QuizQuestion[] }>(`${base(courseId)}/${quizId}/questions/order`, json("PUT", { question_ids: questionIds }));
  return res.data;
}
