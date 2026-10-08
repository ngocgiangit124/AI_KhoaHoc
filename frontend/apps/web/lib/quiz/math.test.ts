import { describe, expect, it, vi } from "vitest";
import { renderTex, toMathParts } from "./math";

describe("toMathParts (KaTeX an toàn)", () => {
  it("tách chữ và công thức, công thức riêng dòng có display", () => {
    const parts = toMathParts("Tính $x^2$ rồi $$\\frac{a}{b}$$ xong");
    expect(parts.map((p) => p.kind)).toEqual(["text", "math", "text", "math", "text"]);
    expect(parts[1]).toMatchObject({ kind: "math", display: false });
    expect(parts[3]).toMatchObject({ kind: "math", display: true });
    expect((parts[1] as { html: string }).html).toContain("katex");
  });

  it("chữ thường giữ nguyên (không bị coi là HTML) — React tự escape khi hiển thị", () => {
    const parts = toMathParts("<script>alert(1)</script> x < 3");
    expect(parts).toEqual([{ kind: "text", value: "<script>alert(1)</script> x < 3" }]);
  });

  it("trust:false: \\href, \\url, \\includegraphics, \\htmlClass không sinh liên kết/ảnh/lớp tuỳ ý", () => {
    const spy = vi.spyOn(console, "warn").mockImplementation(() => undefined);
    for (const tex of ["\\href{javascript:alert(1)}{x}", "\\url{http://evil.test}", "\\includegraphics{http://evil.test/a.png}", "\\htmlClass{evil}{x}", "\\htmlStyle{color:red}{x}"]) {
      const html = renderTex(tex, false) ?? "";
      expect(html).not.toMatch(/<a[\s>]/);
      expect(html).not.toMatch(/<img/);
      expect(html).not.toMatch(/\b(href|src|onerror|onclick)\s*=/i);
      expect(html).not.toMatch(/class="[^"]*evil/);
    }
    spy.mockRestore();
  });

  it("HTML nằm trong công thức bị escape, không thành thẻ", () => {
    const html = renderTex("<img src=x onerror=alert(1)>", false) ?? "";
    const doc = new DOMParser().parseFromString(html, "text/html");
    expect(doc.querySelector("img, script, iframe, a")).toBeNull();
    const attrs = [...doc.body.querySelectorAll("*")].flatMap((el) => el.getAttributeNames());
    expect(attrs.filter((a) => a.startsWith("on"))).toEqual([]);
  });

  it("TeX lỗi không ném: hiện nguyên văn màu danger", () => {
    const html = renderTex("\\frac{1}{", false);
    expect(html).toContain("katex-error");
    expect(html).toContain("var(--color-danger)");
  });

  it("macro đệ quy bị chặn bởi maxExpand, không treo", () => {
    const html = renderTex("\\def\\a{\\a\\a}\\a", false);
    expect(typeof html === "string" || html === null).toBe(true);
  });

  it("công thức quá dài không đưa vào KaTeX, hiện như chữ", () => {
    const long = "x".repeat(2100);
    expect(toMathParts(`$${long}$`)).toEqual([{ kind: "text", value: `$${long}$` }]);
  });

  it("dấu đô la thoát \\$ là chữ thường", () => {
    expect(toMathParts("giá \\$5")).toEqual([{ kind: "text", value: "giá \\$5" }]);
  });
});
