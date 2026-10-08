import { authFetch } from "@/lib/api";
import {
  attemptAnySchema,
  attemptHistorySchema,
  attemptInProgressSchema,
  attemptResultSchema,
  type AttemptAny,
  type AttemptHistory,
  type AttemptInProgress,
  type AttemptResult,
} from "./schemas";

/** Chỉ gọi từ trình duyệt (cookie phiên host-only của API, cùng lý do với `lib/learn/api.ts`). */

/** Bắt đầu hoặc tiếp tục lượt đang làm (201 mới / 200 resume — cùng shape). */
export async function startAttempt(quizId: number): Promise<AttemptInProgress> {
  return attemptInProgressSchema.parse(await authFetch<unknown>(`/api/v1/learn/quizzes/${quizId}/attempts`, { method: "POST" }));
}

export async function fetchAttempt(attemptId: number): Promise<AttemptAny> {
  return attemptAnySchema.parse(await authFetch<unknown>(`/api/v1/learn/quiz-attempts/${attemptId}`));
}

export async function fetchHistory(quizId: number): Promise<AttemptHistory> {
  return attemptHistorySchema.parse(await authFetch<unknown>(`/api/v1/learn/quizzes/${quizId}/attempts`));
}

/** PUT idempotent (204). `keepalive` cho lúc rời trang: body nhỏ nên nằm dưới giới hạn 64 KB của fetch keepalive. */
export async function putAnswer(attemptId: number, questionId: number, optionId: number, opts: { keepalive?: boolean } = {}): Promise<void> {
  await authFetch<void>(`/api/v1/learn/quiz-attempts/${attemptId}/answers/${questionId}`, {
    method: "PUT",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ option_id: optionId }),
    keepalive: opts.keepalive,
  });
}

/** Luôn 200, idempotent: nộp lại lượt đã nộp trả đúng kết quả đã chốt. */
export async function submitAttempt(attemptId: number): Promise<AttemptResult> {
  return attemptResultSchema.parse(await authFetch<unknown>(`/api/v1/learn/quiz-attempts/${attemptId}/submit`, { method: "POST" }));
}
