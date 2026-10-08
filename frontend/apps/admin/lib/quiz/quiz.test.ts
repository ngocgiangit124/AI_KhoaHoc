import { describe, expect, it } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { quizError, quizFieldErrors, questionFieldErrors } from "./errors";
import { draftFromQuestion, draftToPayload, emptyDraft, hasOddDollar, insertSnippet, parentFromValue, sameDraft, textProblem, validateDraft } from "./logic";
import { toMathParts } from "./math";
import type { QuizQuestion } from "./types";

const err422 = (errors: Record<string, string[]>, code?: string) => new ApiError(422, { message: "x", code, errors });

describe("textProblem (đúng luật QuizText của server)", () => {
  it("cho phép < > đứng riêng và công thức", () => {
    expect(textProblem("Nếu x > 2 và y < 3 thì $a \\lt b$")).toBeUndefined();
    expect(textProblem("a<  b")).toBeUndefined();
  });
  it("từ chối dạng thẻ HTML", () => {
    for (const s of ["<b>x</b>", "a</p>", "<!-- x -->", "<?php"]) expect(textProblem(s)).toMatch(/thẻ HTML/);
  });
  it("từ chối ký tự điều khiển, bidi, độ rộng 0; cho phép xuống dòng và tab", () => {
    expect(textProblem("a\u0000b")).toBeTruthy();
    expect(textProblem("a‮b")).toBeTruthy();
    expect(textProblem("a​b")).toBeTruthy();
    expect(textProblem("dòng 1\ndòng 2\tcột")).toBeUndefined();
  });
});

describe("validateDraft", () => {
  it("câu rỗng: báo content, 4 đáp án, đáp án đúng", () => {
    const e = validateDraft(emptyDraft());
    expect(Object.keys(e).sort()).toEqual(["content", "correct", "o0", "o1", "o2", "o3"]);
  });
  it("hợp lệ → rỗng; lời giải có HTML bị báo", () => {
    const ok = { content: "Q $x$", explanation: "", options: ["a", "b", "c", "d"], correct: 1 };
    expect(validateDraft(ok)).toEqual({});
    expect(validateDraft({ ...ok, explanation: "<i>x" }).explanation).toBeTruthy();
  });
  it("đo độ dài giới hạn", () => {
    const ok = { content: "x".repeat(5001), explanation: "", options: ["a", "b", "c", "d"], correct: 0 };
    expect(validateDraft(ok).content).toMatch(/5000/);
  });
});

describe("draft <-> payload", () => {
  const q: QuizQuestion = {
    id: 9,
    quiz_id: 1,
    content: "Q",
    explanation: null,
    position: 3,
    options: [
      { id: 4, content: "d", is_correct: false, position: 4 },
      { id: 1, content: "a", is_correct: false, position: 1 },
      { id: 2, content: "b", is_correct: true, position: 2 },
      { id: 3, content: "c", is_correct: false, position: 3 },
    ],
  };
  it("sắp đáp án theo position, đáp án đúng đúng chỗ", () => {
    const d = draftFromQuestion(q);
    expect(d.options).toEqual(["a", "b", "c", "d"]);
    expect(d.correct).toBe(1);
    expect(draftToPayload({ ...d, explanation: "  " })).toEqual({
      content: "Q",
      explanation: null,
      options: [
        { content: "a", is_correct: false },
        { content: "b", is_correct: true },
        { content: "c", is_correct: false },
        { content: "d", is_correct: false },
      ],
    });
    expect(sameDraft(d, { ...d })).toBe(true);
    expect(sameDraft(d, { ...d, correct: 2 })).toBe(false);
  });
});

describe("tiện ích soạn", () => {
  it("insertSnippet đặt con trỏ tại |", () => {
    expect(insertSnippet("ab", 1, 1, "$|$")).toEqual({ value: "a$$b", caret: 2 });
    expect(insertSnippet("abc", 0, 3, "\\pi|")).toEqual({ value: "\\pi", caret: 3 });
  });
  it("hasOddDollar bỏ qua \\$", () => {
    expect(hasOddDollar("giá \\$5 và $x$")).toBe(false);
    expect(hasOddDollar("$x")).toBe(true);
  });
  it("parentFromValue", () => {
    expect(parentFromValue("chapter:12")).toEqual({ chapter_id: 12 });
    expect(parentFromValue("lesson:7")).toEqual({ lesson_id: 7 });
    expect(parentFromValue("")).toBeNull();
    expect(parentFromValue("lesson:0")).toBeNull();
  });
});

describe("ánh xạ lỗi 422", () => {
  it("câu hỏi: options.N.content về ô oN, options về correct, field lạ vào unmapped", () => {
    const { fields, unmapped } = questionFieldErrors(err422({ content: ["a"], "options.2.content": ["b"], options: ["c"], foo: ["d"] }));
    expect(fields).toEqual({ content: "a", o2: "b", correct: "c" });
    expect(unmapped).toBe("d");
  });
  it("quiz: chapter_id/lesson_id → parent; QUIZ_PARENT_INVALID → parent", () => {
    expect(quizFieldErrors(err422({ title: ["t"], lesson_id: ["l"], time_limit_minutes: ["m"] })).fields).toEqual({ title: "t", parent: "l", time: "m" });
    expect(quizFieldErrors(new ApiError(422, { message: "x", code: "QUIZ_PARENT_INVALID" })).fields.parent).toMatch(/không còn tồn tại/);
  });
  it("thông điệp 403/404/409/limit", () => {
    expect(quizError(new ApiError(403, { message: "x" }))).toMatch(/không có quyền/);
    expect(quizError(new ApiError(404, { message: "x" }))).toMatch(/không còn tồn tại/);
    expect(quizError(new ApiError(409, { message: "x" }))).toMatch(/tải lại/);
    expect(quizError(new ApiError(422, { message: "x", code: "QUIZ_QUESTION_LIMIT" }))).toMatch(/200/);
  });
});

describe("KaTeX cùng cấu hình web", () => {
  it("render công thức, lỗi cú pháp không ném", () => {
    const parts = toMathParts("Cho $x^2$ và $$\\frac{1}{2}$$");
    expect(parts.filter((p) => p.kind === "math")).toHaveLength(2);
    const bad = toMathParts("$\\badmacro{$");
    expect(JSON.stringify(bad)).toContain("katex");
  });
  it("trust:false — \\href không thành liên kết", () => {
    const p = toMathParts("$\\href{https://x.test}{a}$")[0];
    expect(p && p.kind === "math" ? p.html : "").not.toContain("<a ");
  });
  it("công thức quá dài hiện như chữ", () => {
    const p = toMathParts(`$${"x".repeat(2001)}$`)[0];
    expect(p?.kind).toBe("text");
  });
});

import { moveItem, parseQuestionDndId, questionDndId, renumberQuestions } from "./order";
import { isQuestionsMismatch } from "./errors";

describe("sắp xếp câu", () => {
  it("moveItem: đổi chỗ, ngoài khoảng/cùng chỗ → null", () => {
    expect(moveItem([1, 2, 3, 4], 0, 2)).toEqual([2, 3, 1, 4]);
    expect(moveItem([1, 2, 3, 4], 3, 0)).toEqual([4, 1, 2, 3]);
    expect(moveItem([1, 2], 1, 1)).toBeNull();
    expect(moveItem([1, 2], 0, 2)).toBeNull();
    expect(moveItem([1, 2], -1, 0)).toBeNull();
  });
  it("renumber + dnd id", () => {
    const qs = [{ id: 5, position: 9 }, { id: 6, position: 2 }] as never[];
    expect(renumberQuestions(qs).map((q) => (q as { position: number }).position)).toEqual([1, 2]);
    expect(parseQuestionDndId(questionDndId(12))).toBe(12);
    expect(parseQuestionDndId("lesson:1")).toBeNull();
  });
  it("QUIZ_QUESTIONS_MISMATCH có thông điệp riêng", () => {
    const e = new ApiError(422, { message: "x", code: "QUIZ_QUESTIONS_MISMATCH" });
    expect(isQuestionsMismatch(e)).toBe(true);
    expect(quizError(e)).toMatch(/Đã tải lại danh sách mới/);
  });
});
