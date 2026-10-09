import { describe, expect, it } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { classifyPasswordError, mfaErrorMessage } from "@/lib/auth/errors";
import { classifyCouponFormError } from "@/lib/coupons/errors";
import { classifyCourseFormError, courseActionError } from "@/lib/courses/errors";
import { lessonFieldErrors } from "@/lib/curriculum/errors";
import { classifyDecisionError } from "@/lib/enrollment-requests/errors";
import { questionFieldErrors, quizFieldErrors } from "@/lib/quiz/errors";
import { classifyCreateError } from "@/lib/staff/errors";
import { classifySubjectFormError } from "@/lib/subjects/errors";
import { classifyProfileError } from "@/lib/teacher-profiles/errors";

/** QA FW3-1: mỗi bộ phân loại lỗi 422 của admin nhận (a) payload chuẩn, (b) payload lẫn chuỗi/số/object — không `undefined`, không ký tự đơn lẻ. */
const MSG = "Trường này không hợp lệ ạ";
const mk = (errors: Record<string, unknown>, code?: string) => new ApiError(422, { message: "Dữ liệu không hợp lệ", errors, ...(code ? { code } : {}) } as never);
const KEYS = ["name", "code", "email", "role", "title", "reason", "manual_order", "teacher_ids", "current_password", "password", "password_confirmation", "slug", "grade_level", "value", "starts_at"];
const std = () => mk(Object.fromEntries(KEYS.map((k) => [k, [MSG]])));
const weird = () => mk({ ...Object.fromEntries(KEYS.map((k) => [k, [MSG]])), limit: 2, resets_at: "2026-10-10T00:00:00+07:00", preview: { a: 1 }, note: "chuỗi lẻ", "questions.0.body": [MSG] });
const bad = (v: unknown) => {
  const s = JSON.stringify(v) ?? "undefined";
  expect(s).not.toContain("undefined");
  expect(s).not.toMatch(/"(?:[^"\\]|\\.)"[,}\]]/ ); // không có chuỗi 1 ký tự rời rạc
};

const cases: Array<[string, (e: ApiError) => unknown]> = [
  ["auth password", (e) => classifyPasswordError(e)],
  ["auth mfa", (e) => mfaErrorMessage(e)],
  ["coupon form", (e) => classifyCouponFormError(e)],
  ["course form", (e) => classifyCourseFormError(e, { hadFile: false })],
  ["course action (ManualOrderField)", (e) => courseActionError(e)],
  ["curriculum lesson", (e) => lessonFieldErrors(e)],
  ["enrollment decision", (e) => classifyDecisionError(e)],
  ["quiz question", (e) => questionFieldErrors(e)],
  ["quiz form", (e) => quizFieldErrors(e)],
  ["staff create", (e) => classifyCreateError(e)],
  ["subject form", (e) => classifySubjectFormError(e)],
  ["teacher profile", (e) => classifyProfileError(e)],
];

describe("QA FW3-1 admin 422", () => {
  for (const [name, fn] of cases) {
    it(`${name}: payload chuẩn có thông báo, không undefined`, () => {
      const r = fn(std());
      bad(r);
      console.log("STD", name, JSON.stringify(r).slice(0, 160));
    });
    it(`${name}: payload lẫn kiểu không ném lỗi, không undefined`, () => {
      const r = fn(weird());
      bad(r);
      console.log("MIX", name, JSON.stringify(r).slice(0, 160));
    });
    it(`${name}: errors rỗng/thiếu không ném lỗi`, () => {
      expect(() => fn(new ApiError(422, { message: "Lỗi" } as never))).not.toThrow();
      expect(() => fn(mk({}))).not.toThrow();
    });
  }
});
