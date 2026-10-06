import { Badge, IconCheck, IconX, MathText, cx, formatScore } from "@vitaminvui/ui/v2";
import type { AttemptResult } from "@/lib/mock/v2/types";

const LETTERS = ["A", "B", "C", "D"];

/** Vòng điểm (SVG): độ dài cung = điểm/10; số điểm luôn hiện bằng chữ. */
export function ScoreRing({ score }: { score: number }) {
  const r = 52;
  const c = 2 * Math.PI * r;
  const good = score >= 5;
  return (
    <div className="relative size-36 shrink-0">
      <svg viewBox="0 0 120 120" className="size-full -rotate-90" aria-hidden="true">
        <circle cx="60" cy="60" r={r} fill="none" strokeWidth="10" className="stroke-sunken" />
        <circle cx="60" cy="60" r={r} fill="none" strokeWidth="10" strokeLinecap="round" strokeDasharray={`${(score / 10) * c} ${c}`} className={good ? "stroke-primary" : "stroke-warning"} />
      </svg>
      <p className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="num text-title-lg font-extrabold leading-none text-ink">{formatScore(score)}</span>
        <span className="text-sm font-medium text-ink-soft">trên 10 điểm</span>
      </p>
    </div>
  );
}

/** Một câu khi xem lại: đáp án đúng luôn được đánh dấu, đáp án đã chọn sai tô đỏ; có lời giải. */
export function ResultQuestion({ q }: { q: AttemptResult["questions"][number] }) {
  const status = q.selected_option_id === null ? "skipped" : q.is_correct ? "correct" : "wrong";
  return (
    <article id={`cau-${q.position}`} className="scroll-mt-24 rounded-card border border-line bg-surface p-4 sm:p-5">
      <div className="flex items-center justify-between gap-3">
        <h3 className="num text-sm font-extrabold text-primary">Câu {q.position}</h3>
        {status === "correct" ? (
          <Badge tone="success" icon={<IconCheck size={14} />}>
            Đúng
          </Badge>
        ) : status === "wrong" ? (
          <Badge tone="danger" icon={<IconX size={14} />}>
            Sai
          </Badge>
        ) : (
          <Badge tone="warning">Chưa trả lời</Badge>
        )}
      </div>
      <MathText content={q.content} className="mt-2 text-question text-ink" />
      <ul className="mt-4 flex flex-col gap-2">
        {q.options.map((o, i) => {
          const isCorrect = o.id === q.correct_option_id;
          const isPicked = o.id === q.selected_option_id;
          return (
            <li
              key={o.id}
              className={cx(
                "flex min-h-14 items-center gap-3 rounded-control border px-3 py-2.5",
                isCorrect ? "border-success bg-success-soft" : isPicked ? "border-danger bg-danger-soft" : "border-line",
              )}
            >
              <span
                aria-hidden="true"
                className={cx(
                  "flex size-8 shrink-0 items-center justify-center rounded-full border-2 text-sm font-extrabold",
                  isCorrect ? "border-success bg-success text-on-status" : isPicked ? "border-danger bg-danger text-on-status" : "border-line-strong text-ink-soft",
                )}
              >
                {isCorrect ? <IconCheck size={16} strokeWidth={3} /> : isPicked ? <IconX size={16} strokeWidth={3} /> : LETTERS[i]}
              </span>
              <MathText as="span" content={o.content} className="flex-1 text-base text-ink sm:text-lg" />
              {isCorrect ? <span className="text-sm font-semibold text-success">Đáp án đúng</span> : null}
              {isPicked && !isCorrect ? <span className="text-sm font-semibold text-danger">Bạn chọn</span> : null}
            </li>
          );
        })}
      </ul>
      {status === "skipped" ? <p className="mt-3 text-sm text-ink-soft">Bạn chưa trả lời câu này.</p> : null}
      {q.explanation ? (
        <div className="mt-4 rounded-control bg-sunken p-3">
          <p className="text-sm font-semibold text-ink">Lời giải</p>
          <MathText content={q.explanation} className="mt-1 text-base leading-relaxed text-ink" />
        </div>
      ) : null}
    </article>
  );
}
