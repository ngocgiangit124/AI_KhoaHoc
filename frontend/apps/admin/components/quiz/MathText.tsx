import "katex/dist/katex.min.css";
import "./quiz.css";
import { Fragment, createElement, useMemo } from "react";
import { cx } from "@vitaminvui/ui/v2";
import { toMathParts } from "@/lib/quiz/math";

export interface MathTextProps {
  content: string;
  className?: string;
  as?: "div" | "span" | "p";
}

/**
 * Văn bản quiz có công thức, render bằng KaTeX — SAO CHÉP từ `apps/web/components/quiz/MathText.tsx` (FW5) để xem trước
 * giống hệt trang học sinh. Chữ thường đi qua React (tự escape); CHỈ chuỗi HTML do `katex.renderToString` (trust:false) mới vào
 * `dangerouslySetInnerHTML` — file này nằm trong allowlist eslint (S8). KHÔNG truyền HTML nào khác vào đây.
 */
export function MathText({ content, className, as = "div" }: MathTextProps) {
  const parts = useMemo(() => toMathParts(content), [content]);
  return createElement(
    as,
    { className: cx("math-text", className) },
    parts.map((p, i) => {
      if (p.kind === "text") {
        return (
          <Fragment key={i}>
            {p.value.replace(/\\\$/g, "$").split("\n").map((line, j) => (
              <Fragment key={j}>
                {j > 0 ? <br /> : null}
                {line}
              </Fragment>
            ))}
          </Fragment>
        );
      }
      if (!p.display) return <span key={i} dangerouslySetInnerHTML={{ __html: p.html }} />;
      return (
        <span key={i} role="group" aria-label="Công thức" tabIndex={0} className="focus-ring my-2 block overflow-x-auto rounded-control py-1">
          <span dangerouslySetInnerHTML={{ __html: p.html }} />
        </span>
      );
    }),
  );
}
