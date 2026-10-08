import { describe, expect, it } from "vitest";
import { toMathParts as adminParts, KATEX_OPTIONS as adminOptions } from "./math";
// QA FA5: bản sao KaTeX của admin phải ra đúng HTML như trang học sinh (apps/web, FW5) cho cùng nội dung (chỉ đọc, không sửa web).
import { toMathParts as webParts, KATEX_OPTIONS as webOptions } from "../../../web/lib/quiz/math";
import { textProblem, hasOddDollar } from "./logic";

const CASES: Record<string, string> = {
  thường: "Cho $x^2 + y^2 = 1$ và $$\\dfrac{a}{b}$$ xong",
  tiếng_việt: "Số đo góc $\\widehat{BAC} = 35^\\circ$ là: Đường tròn nội tiếp",
  href: "$\\href{javascript:alert(1)}{bấm}$",
  url: "$\\url{https://evil.test}$",
  includegraphics: "$\\includegraphics{https://evil.test/x.png}$",
  htmlClass: "$\\htmlClass{x}{y} \\htmlId{z}{w} \\htmlStyle{color:red}{v} \\htmlData{a=b}{u}$",
  macro_de_quy: "$\\def\\a{\\a\\a}\\a$",
  macro_de_quy_2: "$\\newcommand{\\f}{\\f\\f}\\f$",
  qua_lon: "$\\rule{99999em}{99999em}$",
  qua_dai: `$${"x+".repeat(1100)}x$`,
  do_la_le: "giá 5$ và 7$",
  loi_cu_phap: "$\\frac{1}{$",
  lenh_la: "$\\unknowncommand{x}$",
  html_trong_cong_thuc: "$a \\lt b \\gt c$ và x > 2 và y < 3",
  verb: "$\\verb|<script>alert(1)|$",
  text_html: "$\\text{<img src=x onerror=alert(1)>}$",
};

describe("QA FA5: xem trước admin = trang học sinh", () => {
  it("cùng cấu hình KaTeX", () => {
    expect(adminOptions).toEqual(webOptions);
    expect(adminOptions.trust).toBe(false);
  });
  for (const [name, input] of Object.entries(CASES)) {
    it(`cùng đầu ra: ${name}`, () => {
      expect(adminParts(input)).toEqual(webParts(input));
    });
  }
  it("công thức độc hại không sinh liên kết, ảnh, thuộc tính sự kiện, thẻ script", () => {
    for (const k of ["href", "url", "includegraphics", "htmlClass", "verb", "text_html", "macro_de_quy", "macro_de_quy_2", "qua_lon"]) {
      const html = adminParts(CASES[k]!)
        .map((p) => (p.kind === "math" ? p.html : ""))
        .join("");
      // Phân tích DOM: không có phần tử/thuộc tính nguy hiểm (chuỗi TeX gốc nằm trong <annotation> chỉ là chữ, vô hại).
      const doc = new DOMParser().parseFromString(`<div>${html}</div>`, "text/html");
      expect(doc.querySelectorAll("a, img, script, iframe, object, embed, link, form, input, svg, style, base").length, k).toBe(0);
      for (const el of Array.from(doc.querySelectorAll("*"))) {
        for (const attr of Array.from(el.attributes)) {
          expect(attr.name, `${k} <${el.tagName}>`).not.toMatch(/^(on|href|src|xlink|action|formaction|srcdoc)/i);
        }
      }
    }
  });
  it("công thức > 2000 ký tự hiện nguyên văn như chữ (không render)", () => {
    const parts = adminParts(CASES.qua_dai!);
    expect(parts.every((p) => p.kind === "text")).toBe(true);
    expect(parts.map((p) => (p.kind === "text" ? p.value : "")).join("")).toBe(CASES.qua_dai);
  });
  it("công thức vừa đúng 2000 ký tự vẫn render", () => {
    const tex = "x".repeat(2000);
    expect(adminParts(`$${tex}$`).some((p) => p.kind === "math")).toBe(true);
    expect(adminParts(`$${tex}x$`).some((p) => p.kind === "math")).toBe(false);
  });
});

describe("QA FA5: luật văn bản (so với App\\Rules\\QuizText)", () => {
  it("x > 2 được; <b, </p, <!, <? bị chặn; < sát chữ số hoặc khoảng trắng được", () => {
    expect(textProblem("x > 2")).toBeUndefined();
    expect(textProblem("a<2")).toBeUndefined();
    expect(textProblem("a < b")).toBeUndefined();
    expect(textProblem("a<b")).toBeTruthy();
    expect(textProblem("<b")).toBeTruthy();
    expect(textProblem("5<Z")).toBeTruthy();
  });
  it("ký tự bidi/độ rộng 0 ở mọi biên dải bị chặn, ký tự ngay ngoài dải thì không", () => {
    for (const cp of [0x202a, 0x202e, 0x2066, 0x2069, 0x200b, 0x200f, 0x2060, 0xfeff, 0x0000, 0x001f, 0x007f, 0x009f]) expect(textProblem(`a${String.fromCodePoint(cp)}b`), cp.toString(16)).toBeTruthy();
    for (const cp of [0x2029, 0x202f, 0x2065, 0x206a, 0x2061, 0x00a0, 0x00a1, 0xfeff + 1]) expect(textProblem(`a${String.fromCodePoint(cp)}b`), cp.toString(16)).toBeUndefined();
  });
  it("$ lẻ: cảnh báo; \\$ không tính; $$ chẵn không cảnh báo", () => {
    expect(hasOddDollar("giá 5$")).toBe(true);
    expect(hasOddDollar("giá 5\\$")).toBe(false);
    expect(hasOddDollar("$$x$$")).toBe(false);
    expect(hasOddDollar("$x$ và $")).toBe(true);
  });
});
