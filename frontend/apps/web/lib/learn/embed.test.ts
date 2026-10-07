import { describe, expect, it } from "vitest";
import { isAllowedEmbedUrl } from "./embed";
import { classifyPlaybackError, isTerminalPlaybackError } from "./errors";
import { ApiError } from "@vitaminvui/api-client";
import { courseRefFromError } from "./errors";

describe("isAllowedEmbedUrl (khớp frame-src của CSP)", () => {
  it("chỉ https tới YouTube nocookie / Vimeo player", () => {
    expect(isAllowedEmbedUrl("https://www.youtube-nocookie.com/embed/abc123")).toBe(true);
    expect(isAllowedEmbedUrl("https://player.vimeo.com/video/123")).toBe(true);
  });
  it("từ chối http, host lạ, javascript:, host giả mạo, chuỗi rác", () => {
    for (const u of [
      "http://www.youtube-nocookie.com/embed/x",
      "https://www.youtube.com/embed/x",
      "https://evil.example/www.youtube-nocookie.com",
      "https://www.youtube-nocookie.com.evil.example/embed/x",
      "javascript:alert(1)",
      "data:text/html,<script>1</script>",
      "không phải url",
      "",
    ]) {
      expect(isAllowedEmbedUrl(u), u).toBe(false);
    }
  });
});

describe("classifyPlaybackError", () => {
  it("401 là phiên (hộp thoại do SessionEndedGate lo), lỗi lạ là unknown", () => {
    expect(classifyPlaybackError(new ApiError(401, { message: "x", code: "SESSION_REPLACED" }))).toBe("session");
    expect(classifyPlaybackError(new Error("boom"))).toBe("unknown");
    expect(isTerminalPlaybackError("session")).toBe(true);
    expect(isTerminalPlaybackError("network")).toBe(false);
  });
});

describe("courseRefFromError (403 kèm errors.course)", () => {
  const forbidden = (errors?: unknown) => new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED", errors: errors as never });
  it("lấy slug hợp lệ; không có/sai kiểu/sai định dạng/không phải 403 → null", () => {
    expect(courseRefFromError(forbidden({ course: { id: 1, slug: "hinh-hoc-9", title: "H9" } }))).toEqual({ slug: "hinh-hoc-9", title: "H9" });
    expect(courseRefFromError(forbidden())).toBeNull();
    expect(courseRefFromError(forbidden({ course: ["x"] }))).toBeNull();
    expect(courseRefFromError(forbidden({ course: { slug: "//evil.example" } }))).toBeNull();
    expect(courseRefFromError(forbidden({ course: { slug: 5 } }))).toBeNull();
    expect(courseRefFromError(new ApiError(404, { message: "x", errors: { course: { slug: "a" } } as never }))).toBeNull();
    expect(courseRefFromError(new Error("x"))).toBeNull();
  });
});
