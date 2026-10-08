import { describe, expect, it } from "vitest";
import { attemptAnySchema, attemptHistorySchema, attemptInProgressSchema } from "./schemas";

const base = {
  id: 1,
  quiz_id: 2,
  status: "in_progress",
  started_at: "2026-10-07T10:00:00+07:00",
  expires_at: null,
  server_now: "2026-10-07T10:00:00+07:00",
  remaining_seconds: null,
  total_questions: 1,
  questions: [{ id: 5, position: 1, content: "$1+1$", options: [{ id: 1, position: 1, content: "2" }] }],
};

describe("schemas quiz", () => {
  it("answers là object hoặc mảng rỗng (PHP) đều chuẩn hoá thành object", () => {
    expect(attemptInProgressSchema.parse({ ...base, answers: { "5": 1 } }).answers).toEqual({ "5": 1 });
    expect(attemptInProgressSchema.parse({ ...base, answers: [] }).answers).toEqual({});
  });
  it("phân biệt theo status", () => {
    expect(attemptAnySchema.parse({ ...base, answers: {} }).status).toBe("in_progress");
  });
  it("điểm dạng chuỗi decimal của Laravel ('7.50') được đổi thành số; null giữ nguyên", () => {
    const h = attemptHistorySchema.parse({
      data: [
        { id: 1, status: "submitted", started_at: "x", expires_at: null, submitted_at: "y", auto_submitted: false, total_questions: 4, correct_count: 3, score: "7.50" },
        { id: 2, status: "in_progress", started_at: "x", expires_at: null, submitted_at: null, auto_submitted: false, total_questions: 4, correct_count: null, score: null },
      ],
      best_score: "7.50",
      attempts_count: 1,
    });
    expect(h.best_score).toBe(7.5);
    expect(h.data[0]?.score).toBe(7.5);
    expect(h.data[1]?.score).toBeNull();
  });
});
