import katex from "katex";
import { splitMath } from "@vitaminvui/ui/v2";

/**
 * Tách văn bản quiz (văn bản thuần có `$...$` / `$$...$$`) thành các phần: chữ (render bằng React, tự escape) và công thức
 * (HTML do KaTeX sinh). api-contract §4: `trust:false` (không `\href`, `\url`, `\includegraphics`, `\htmlClass`...),
 * `maxExpand` chặn macro đệ quy, `maxSize` chặn kích thước vô lý, `throwOnError:false` (lỗi cú pháp hiện nguyên văn TeX màu danger).
 */
export type MathPart = { kind: "text"; value: string } | { kind: "math"; html: string; display: boolean };

export const KATEX_OPTIONS = {
  trust: false,
  strict: "warn",
  maxSize: 10,
  maxExpand: 1000,
  throwOnError: false,
  errorColor: "var(--color-danger)",
} as const;

/** Công thức dài bất thường không được đưa vào KaTeX (chi phí render) — hiện nguyên văn như chữ. */
const MAX_TEX_LENGTH = 2_000;

export function renderTex(tex: string, display: boolean): string | null {
  if (tex.length > MAX_TEX_LENGTH) return null;
  try {
    return katex.renderToString(tex, { ...KATEX_OPTIONS, displayMode: display });
  } catch {
    return null; // vẫn có thể ném với đầu vào đặc biệt (vd. vượt maxExpand ở một số đường): rơi về chữ thường
  }
}

export function toMathParts(content: string): MathPart[] {
  return splitMath(content).map((seg): MathPart => {
    if (seg.kind === "text") return { kind: "text", value: seg.value };
    const html = renderTex(seg.value, seg.display);
    if (html === null) return { kind: "text", value: seg.display ? `$$${seg.value}$$` : `$${seg.value}$` };
    return { kind: "math", html, display: seg.display };
  });
}
