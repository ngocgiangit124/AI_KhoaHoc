import { describe, expect, it } from "vitest";
import { sanitizeCourseDescription } from "./sanitize";

describe("sanitizeCourseDescription", () => {
  it("giữ thẻ trong allowlist", () => {
    const html = "<h2>Tiêu đề</h2><p>Nội dung <strong>đậm</strong> <em>nghiêng</em></p><ul><li>a</li></ul>";
    expect(sanitizeCourseDescription(html)).toBe(html);
  });

  it("loại script, handler sự kiện, iframe, style, img", () => {
    const out = sanitizeCourseDescription(
      '<p onclick="x()">a</p><script>alert(1)</script><img src=x onerror=alert(1)><iframe src="//e"></iframe><p style="color:red">b</p>',
    );
    expect(out).not.toMatch(/script|onclick|onerror|iframe|<img|style/i);
    expect(out).toContain("a");
    expect(out).toContain("b");
  });

  it("chặn javascript:/data: ở href, giữ https và gắn rel an toàn", () => {
    const bad = sanitizeCourseDescription('<a href="javascript:alert(1)">x</a><a href="data:text/html,<b>">y</a>');
    expect(bad).not.toMatch(/javascript:|data:/i);

    const ok = sanitizeCourseDescription('<a href="https://example.com/a">link</a>');
    expect(ok).toContain('href="https://example.com/a"');
    expect(ok).toContain('target="_blank"');
    expect(ok).toContain("noopener");
    expect(ok).toContain("noreferrer");
  });

  it("chuỗi rỗng -> rỗng", () => {
    expect(sanitizeCourseDescription("")).toBe("");
  });
});
