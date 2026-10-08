import { z } from "zod";

/** Hình dạng response của `/learn/quizzes/*` và `/learn/quiz-attempts/*` (api-contract §2.4, T22). */

/** Điểm là DECIMAL ở DB: Laravel có thể serialize thành chuỗi "7.50" — chấp nhận cả hai, ra số. */
const decimal = z.preprocess((v) => (typeof v === "string" && v.trim() !== "" ? Number(v) : v), z.number());

const option = z.object({ id: z.number(), position: z.number(), content: z.string() });

export const attemptInProgressSchema = z.object({
  id: z.number(),
  quiz_id: z.number(),
  status: z.literal("in_progress"),
  started_at: z.string(),
  expires_at: z.string().nullable(),
  server_now: z.string(),
  remaining_seconds: z.number().nullable(),
  total_questions: z.number(),
  /** `{"<question_id>": option_id}` — luôn là object (PHP có thể trả `[]` khi rỗng nên chuẩn hoá ở `normalizeAnswers`). */
  answers: z.union([z.record(z.string(), z.number()), z.array(z.never()).transform(() => ({}) as Record<string, number>)]),
  questions: z.array(z.object({ id: z.number(), position: z.number(), content: z.string(), options: z.array(option) })),
});
export type AttemptInProgress = z.infer<typeof attemptInProgressSchema>;

export const attemptResultSchema = z.object({
  id: z.number(),
  quiz_id: z.number(),
  status: z.literal("submitted"),
  started_at: z.string(),
  submitted_at: z.string(),
  auto_submitted: z.boolean(),
  server_now: z.string(),
  total_questions: z.number(),
  correct_count: z.number(),
  unanswered_count: z.number(),
  score: decimal,
  questions: z.array(
    z.object({
      id: z.number(),
      position: z.number(),
      content: z.string(),
      explanation: z.string().nullable(),
      selected_option_id: z.number().nullable(),
      correct_option_id: z.number(),
      is_correct: z.boolean(),
      options: z.array(option),
    }),
  ),
});
export type AttemptResult = z.infer<typeof attemptResultSchema>;

/** `GET /learn/quiz-attempts/{id}`: đang làm → AttemptInProgress, đã nộp → AttemptResult. */
export const attemptAnySchema = z.discriminatedUnion("status", [attemptInProgressSchema, attemptResultSchema]);
export type AttemptAny = z.infer<typeof attemptAnySchema>;

export const attemptHistorySchema = z.object({
  data: z.array(
    z.object({
      id: z.number(),
      status: z.enum(["in_progress", "submitted"]),
      started_at: z.string(),
      expires_at: z.string().nullable(),
      submitted_at: z.string().nullable(),
      auto_submitted: z.boolean(),
      total_questions: z.number(),
      correct_count: z.number().nullable(),
      score: decimal.nullable(),
    }),
  ),
  best_score: decimal.nullable(),
  attempts_count: z.number(),
});
export type AttemptHistory = z.infer<typeof attemptHistorySchema>;
