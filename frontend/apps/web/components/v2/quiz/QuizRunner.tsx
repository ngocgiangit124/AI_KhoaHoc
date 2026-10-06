"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useState } from "react";
import {
  Alert,
  Button,
  ConfirmDialog,
  Countdown,
  Dialog,
  IconCheck,
  IconLayoutGrid,
  IconWifiOff,
  IconX,
  MathText,
  ProgressBar,
  Spinner,
  cx,
} from "@vitaminvui/ui/v2";
import type { AttemptInProgress } from "@/lib/mock/v2/types";

const LETTERS = ["A", "B", "C", "D"];

export interface QuizRunnerProps {
  attempt: AttemptInProgress;
  title: string;
  exitHref: string;
  resultHref: string;
  /** Giả lập mất mạng (banner cảnh báo). */
  offline?: boolean;
}

type SaveState = { id: number; phase: "saving" | "saved" } | null;

/** Bảng số câu: đã trả lời tô `primary`, chưa trả lời viền; bấm để nhảy tới câu. */
function QuestionGrid({ attempt, answers, onJump }: { attempt: AttemptInProgress; answers: Record<string, number>; onJump?: () => void }) {
  return (
    <ol className="grid grid-cols-5 gap-2">
      {attempt.questions.map((q) => {
        const done = answers[String(q.id)] !== undefined;
        return (
          <li key={q.id}>
            <a
              href={`#cau-${q.position}`}
              onClick={onJump}
              aria-label={`Câu ${q.position}${done ? ", đã trả lời" : ", chưa trả lời"}`}
              className={cx(
                "focus-ring num flex size-11 items-center justify-center rounded-control text-base font-semibold",
                done ? "bg-primary text-on-primary" : "border border-line-strong bg-surface text-ink hover:border-primary",
              )}
            >
              {q.position}
            </a>
          </li>
        );
      })}
    </ol>
  );
}

/**
 * Làm bài trắc nghiệm (US-007, T22).
 * - Đồng hồ đếm theo `remaining_seconds` của server; hết giờ → khoá form, tự nộp, sang trang kết quả.
 * - Chọn đáp án là tự lưu (PUT .../answers/{question}); "Đang lưu…" → "Đã lưu" cạnh câu vừa chọn.
 * - Nộp khi còn câu trống → hộp xác nhận. Đang nộp → khoá toàn bộ form.
 * TODO(dev): nối PUT answers (409 QUIZ_ATTEMPT_EXPIRED → sang kết quả), POST submit; hàng đợi đáp án khi mất mạng.
 */
export function QuizRunner({ attempt, title, exitHref, resultHref, offline = false }: QuizRunnerProps) {
  const router = useRouter();
  const [answers, setAnswers] = useState<Record<string, number>>(attempt.answers);
  const [save, setSave] = useState<SaveState>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [gridOpen, setGridOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [expired, setExpired] = useState(false);

  const answered = Object.keys(answers).length;
  const unanswered = attempt.total_questions - answered;
  const locked = submitting || expired;

  function choose(questionId: number, optionId: number) {
    if (locked) return;
    setAnswers((a) => ({ ...a, [String(questionId)]: optionId }));
    setSave({ id: questionId, phase: "saving" });
    setTimeout(() => setSave((s) => (s?.id === questionId ? { id: questionId, phase: "saved" } : s)), 500);
  }

  function submit() {
    setConfirmOpen(false);
    setSubmitting(true);
    setTimeout(() => router.push(resultHref), 900);
  }

  const onExpire = useCallback(() => {
    setExpired(true);
    setTimeout(() => router.push(`${resultHref}?tu-nop=1`), 1800);
  }, [router, resultHref]);

  return (
    <>
      <header className="sticky top-0 z-30 border-b border-line bg-surface">
        <div className="mx-auto flex h-16 max-w-6xl items-center gap-2 px-2 sm:px-4">
          <Link href={exitHref} aria-label="Thoát, quay lại bài học (bài làm đã được lưu)" className="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-control text-ink hover:bg-sunken">
            <IconX />
          </Link>
          <div className="min-w-0 flex-1">
            <p className="truncate text-base font-semibold text-ink">{title}</p>
            <p className="num text-sm text-ink-soft">
              Đã trả lời {answered}/{attempt.total_questions}
            </p>
          </div>
          {attempt.remaining_seconds !== null ? <Countdown remainingSeconds={attempt.remaining_seconds} onExpire={onExpire} /> : null}
          <Button className="hidden md:inline-flex" onClick={() => (unanswered > 0 ? setConfirmOpen(true) : submit())} loading={submitting} loadingText="Đang nộp…" disabled={expired}>
            Nộp bài
          </Button>
        </div>
        <ProgressBar value={(answered / attempt.total_questions) * 100} label="Số câu đã trả lời" hideLabel size="sm" className="[&_[role=progressbar]]:rounded-none" />
      </header>

      <main id="noi-dung" className="mx-auto w-full max-w-6xl flex-1 px-4 pb-28 pt-6 md:pb-12 lg:grid lg:grid-cols-[1fr_280px] lg:gap-10">
        <div className="flex min-w-0 flex-col gap-5">
          {offline ? (
            <Alert tone="warning" title="Mất kết nối mạng">
              <span className="flex items-center gap-2">
                <IconWifiOff size={16} />
                Các câu trả lời sẽ được lưu khi có mạng trở lại. Đừng đóng trang này.
              </span>
            </Alert>
          ) : null}
          <p className="text-sm text-ink-soft">Mỗi câu chọn 1 đáp án. Bài làm được lưu tự động — bạn có thể thoát và quay lại làm tiếp trước khi hết giờ.</p>

          {attempt.questions.map((q) => {
            const selected = answers[String(q.id)];
            return (
              <fieldset key={q.id} id={`cau-${q.position}`} disabled={locked} className="scroll-mt-28 rounded-card border border-line bg-surface p-4 sm:p-5">
                <legend className="sr-only">Câu {q.position}</legend>
                <div className="flex items-center justify-between gap-3">
                  <p aria-hidden="true" className="num text-sm font-extrabold text-primary">
                    Câu {q.position}
                  </p>
                  <p aria-live="polite" className="flex min-h-5 items-center gap-1 text-sm text-ink-soft">
                    {save?.id === q.id && save.phase === "saving" ? (
                      <>
                        <Spinner className="size-3.5" label={null} />
                        Đang lưu…
                      </>
                    ) : save?.id === q.id && save.phase === "saved" ? (
                      <>
                        <IconCheck size={14} className="text-success motion-safe:animate-tick" />
                        Đã lưu
                      </>
                    ) : null}
                  </p>
                </div>
                <MathText content={q.content} className="mt-2 text-question text-ink" />
                <div className="mt-4 flex flex-col gap-2">
                  {q.options.map((o, i) => (
                    <label
                      key={o.id}
                      className={cx(
                        "group flex min-h-14 cursor-pointer items-center gap-3 rounded-control border px-3 py-2.5 transition-colors duration-150",
                        "has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-focus",
                        selected === o.id ? "border-primary bg-primary-soft" : "border-line-strong bg-surface hover:border-primary",
                        locked && "cursor-not-allowed opacity-80",
                      )}
                    >
                      <input type="radio" name={`q-${q.id}`} value={o.id} checked={selected === o.id} onChange={() => choose(q.id, o.id)} className="sr-only" />
                      <span
                        aria-hidden="true"
                        className={cx(
                          "flex size-8 shrink-0 items-center justify-center rounded-full border-2 text-sm font-extrabold",
                          selected === o.id ? "border-primary bg-primary text-on-primary" : "border-line-strong text-ink-soft",
                        )}
                      >
                        {LETTERS[i]}
                      </span>
                      <span className="sr-only">Đáp án {LETTERS[i]}: </span>
                      <MathText as="span" content={o.content} className="flex-1 text-base text-ink sm:text-lg" />
                    </label>
                  ))}
                </div>
              </fieldset>
            );
          })}
        </div>

        <aside aria-label="Bảng câu hỏi" className="hidden lg:block">
          <div className="sticky top-28 flex flex-col gap-4 rounded-card border border-line bg-surface p-4">
            <h2 className="text-base font-semibold text-ink">Bảng câu hỏi</h2>
            <QuestionGrid attempt={attempt} answers={answers} />
            <p className="flex items-center gap-3 text-sm text-ink-soft">
              <span className="inline-flex items-center gap-1.5">
                <span className="size-3 rounded bg-primary" aria-hidden="true" /> Đã trả lời
              </span>
              <span className="inline-flex items-center gap-1.5">
                <span className="size-3 rounded border border-line-strong" aria-hidden="true" /> Chưa
              </span>
            </p>
          </div>
        </aside>
      </main>

      {/* Mobile: thanh dính đáy. */}
      <div className="fixed inset-x-0 bottom-0 z-30 flex gap-3 border-t border-line bg-surface px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3 md:hidden">
        <Button variant="secondary" leadingIcon={<IconLayoutGrid size={18} />} onClick={() => setGridOpen(true)} aria-haspopup="dialog">
          <span className="num">
            {answered}/{attempt.total_questions}
          </span>
        </Button>
        <Button block onClick={() => (unanswered > 0 ? setConfirmOpen(true) : submit())} loading={submitting} loadingText="Đang nộp bài…" disabled={expired}>
          Nộp bài
        </Button>
      </div>

      <Dialog open={gridOpen} onClose={() => setGridOpen(false)} title="Bảng câu hỏi" description={`Đã trả lời ${answered}/${attempt.total_questions} câu.`} sheetOnMobile>
        <QuestionGrid attempt={attempt} answers={answers} onJump={() => setGridOpen(false)} />
      </Dialog>

      <ConfirmDialog
        open={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        onConfirm={submit}
        title={`Bạn còn ${unanswered} câu chưa trả lời`}
        description="Câu bỏ trống được tính là sai. Bạn vẫn muốn nộp bài?"
        cancelLabel="Làm tiếp"
        confirmLabel="Vẫn nộp bài"
        loading={submitting}
        loadingText="Đang nộp…"
      />

      <Dialog open={expired} onClose={() => undefined} dismissible={false} title="Đã hết giờ làm bài" description="Hệ thống đang tự nộp bài của bạn với các câu đã trả lời.">
        <div className="flex items-center gap-3 text-base text-ink">
          <Spinner className="size-5" label={null} />
          Đang chuyển tới trang kết quả…
        </div>
      </Dialog>
    </>
  );
}
