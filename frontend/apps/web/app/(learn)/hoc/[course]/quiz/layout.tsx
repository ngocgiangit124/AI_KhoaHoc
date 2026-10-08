import type { ReactNode } from "react";
import "katex/dist/katex.min.css";
import "@/components/quiz/quiz.css";

/** Chỉ màn quiz tải CSS + font KaTeX (tự host trong `_next/static/media`, CSP `font-src 'self'`). */
export default function QuizLayout({ children }: { children: ReactNode }) {
  return children;
}
