import { createElement, Fragment, type ReactNode } from "react";
import { cx } from "./cx";
import { texToMathml } from "./math/texToMathml";

export interface MathTextProps {
  /**
   * Văn bản thuần từ API (câu hỏi, đáp án, lời giải) có thể chứa `$...$` (công thức trong dòng)
   * và `$$...$$` (công thức riêng dòng). Xuống dòng `\n` được giữ.
   */
  content: string;
  className?: string;
  /** Thẻ bao ngoài. Đáp án trắc nghiệm dùng `span`. */
  as?: "div" | "span" | "p";
}

type Segment = { kind: "text"; value: string } | { kind: "math"; value: string; display: boolean };

/** Tách `$$...$$` và `$...$`; `\$` là dấu đô la thường. */
export function splitMath(content: string): Segment[] {
  const out: Segment[] = [];
  const re = /\$\$([\s\S]+?)\$\$|\$((?:\\\$|[^$])+?)\$/g;
  let last = 0;
  let m: RegExpExecArray | null;
  while ((m = re.exec(content))) {
    if (m.index > last) out.push({ kind: "text", value: content.slice(last, m.index) });
    if (m[1] !== undefined) out.push({ kind: "math", value: m[1], display: true });
    else out.push({ kind: "math", value: m[2] ?? "", display: false });
    last = m.index + m[0].length;
  }
  if (last < content.length) out.push({ kind: "text", value: content.slice(last) });
  return out;
}

function renderText(value: string): ReactNode {
  const lines = value.replace(/\\\$/g, "$").split("\n");
  return lines.map((line, i) => (
    <Fragment key={i}>
      {i > 0 ? <br /> : null}
      {line}
    </Fragment>
  ));
}

/**
 * Văn bản có công thức Toán. Bản xem trước render bằng MathML gốc của trình duyệt; bản thật thay phần
 * `math` bằng KaTeX (`trust:false`, `throwOnError:false`) — nơi dùng không đổi.
 * Công thức riêng dòng nằm trong khung cuộn ngang được (tabIndex=0) để không làm vỡ bố cục trên 375px.
 */
export function MathText({ content, className, as = "div" }: MathTextProps) {
  const segments = splitMath(content);
  return createElement(
    as,
    { className: cx("math-text", className) },
    segments.map((seg, i) => {
      if (seg.kind === "text") return <Fragment key={i}>{renderText(seg.value)}</Fragment>;
      const math = createElement("math", { display: seg.display ? "block" : "inline" }, ...texToMathml(seg.value));
      if (!seg.display) return <Fragment key={i}>{math}</Fragment>;
      return (
        <span key={i} role="group" aria-label="Công thức" tabIndex={0} className="focus-ring my-2 block overflow-x-auto rounded-control py-1">
          {math}
        </span>
      );
    }),
  );
}
