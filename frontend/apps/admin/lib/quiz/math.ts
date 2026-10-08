import katex from "katex";
import { splitMath } from "@vitaminvui/ui/v2";

/**
 * SAO CHÉP từ `apps/web/lib/quiz/math.ts` (FW5) để xem trước của admin ra đúng như học sinh thấy. Giữ NGUYÊN cấu hình KaTeX;
 * sửa một bên phải sửa bên kia (đề xuất: đưa vào packages/ui khi FW5 đã commit). api-contract §4: `trust:false`, `maxExpand`,
 * `maxSize`, `throwOnError:false` (lỗi cú pháp hiện nguyên văn TeX màu danger).
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
    return null;
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
