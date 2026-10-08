"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from "react";
import { ApiError } from "@vitaminvui/api-client";
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
  ProgressBar,
  Spinner,
  cx,
} from "@vitaminvui/ui/v2";
import { fetchAttempt, submitAttempt } from "@/lib/quiz/api";
import { useAnswerSaver } from "@/lib/quiz/useAnswerSaver";
import type { AttemptInProgress } from "@/lib/quiz/schemas";
import { onSessionEnded } from "@/lib/learn/sessionPause";
import { routes } from "@/lib/routes";
import { MathText } from "./MathText";

const LETTERS = ["A", "B", "C", "D"];
/** Hết giờ mà nộp lỗi mạng: thử lại bấy nhiêu lần (cách nhau 3 giây) trước khi hiện nút "Thử nộp lại". */
const AUTO_SUBMIT_TRIES = 5;

function subscribeOnline(cb: () => void) {
  window.addEventListener("online", cb);
  window.addEventListener("offline", cb);
  return () => {
    window.removeEventListener("online", cb);
    window.removeEventListener("offline", cb);
  };
}
const getOnline = () => navigator.onLine;
const getOnlineServer = () => true;

export interface QuizRunnerProps {
  attempt: AttemptInProgress;
  courseId: number;
  quizId: number;
  title: string;
  exitHref: string;
}

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
 * Làm bài trắc nghiệm (US-007, T22; design-system-v2 §12.4).
 * - Đồng hồ theo `remaining_seconds` do server tính; quay lại tab thì hỏi lại server để đồng bộ. Hết giờ → khoá, gửi nốt đáp án, tự nộp.
 * - Chọn đáp án là tự lưu (debounce, 1 request/câu, gửi lại khi lỗi mạng); nộp bài luôn gửi nốt đáp án còn lại trước.
 * - Nộp khi còn câu trống → hộp xác nhận; đang nộp → khoá toàn form.
 */
export function QuizRunner({ attempt, courseId, quizId, title, exitHref }: QuizRunnerProps) {
  const router = useRouter();
  const resultHref = routes.quizResult(courseId, quizId, { attemptId: attempt.id });
  const [answers, setAnswers] = useState<Record<string, number>>(attempt.answers);
  const [sessionEnded, setSessionEnded] = useState(false);
  const [saver, snap] = useAnswerSaver(attempt.id, attempt.answers, sessionEnded);
  const online = useSyncExternalStore(subscribeOnline, getOnline, getOnlineServer);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [gridOpen, setGridOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [expired, setExpired] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [remaining, setRemaining] = useState(attempt.remaining_seconds);
  const sessionEndedRef = useRef(false);
  const submittingRef = useRef(false);

  // Câu bị server từ chối (422) không tính là đã trả lời và không tô đáp án: UI luôn khớp với những gì server đã nhận.
  const effective = Object.fromEntries(Object.entries(answers).filter(([qid]) => snap.questions[Number(qid)] !== "rejected"));
  const answered = Object.keys(effective).length;
  const unanswered = attempt.total_questions - answered;
  const sessionLost = sessionEnded || snap.sessionLost;
  const locked = submitting || expired || snap.closed || snap.revoked || sessionLost;
  const [exitConfirm, setExitConfirm] = useState(false);

  useEffect(
    () =>
      onSessionEnded(() => {
        sessionEndedRef.current = true;
        setSessionEnded(true);
      }),
    [],
  );

  // Lượt đã bị đóng ở server (409: đã nộp ở tab khác / hết hạn và được tự nộp) → xem kết quả.
  useEffect(() => {
    if (snap.closed) router.replace(resultHref);
  }, [snap.closed, router, resultHref]);

  // Quay lại tab (máy ngủ, tab nền bị hãm timer): đồng bộ lại đồng hồ với server, hoặc sang kết quả nếu đã tự nộp.
  useEffect(() => {
    async function resync() {
      if (document.visibilityState !== "visible" || sessionEndedRef.current || submittingRef.current) return;
      try {
        const t0 = performance.now();
        const fresh = await fetchAttempt(attempt.id);
        const halfRttSeconds = (performance.now() - t0) / 2000; // bù nửa độ trễ mạng
        if (fresh.status === "submitted") router.replace(resultHref);
        else setRemaining(fresh.remaining_seconds === null ? null : Math.max(0, fresh.remaining_seconds - halfRttSeconds));
      } catch {
        // mất mạng: giữ đồng hồ hiện có
      }
    }
    document.addEventListener("visibilitychange", resync);
    return () => document.removeEventListener("visibilitychange", resync);
  }, [attempt.id, resultHref, router]);

  function choose(questionId: number, optionId: number) {
    if (locked) return;
    setAnswers((a) => ({ ...a, [String(questionId)]: optionId }));
    saver.choose(questionId, optionId);
  }

  const doSubmit = useCallback(
    async (auto: boolean) => {
      if (submittingRef.current) return;
      submittingRef.current = true;
      setConfirmOpen(false);
      setSubmitError(null);
      setSubmitting(true);
      const saved = await saver.flushNow();
      if (!saved && !auto && !sessionEndedRef.current) {
        submittingRef.current = false;
        setSubmitting(false);
        setSubmitError("Chưa lưu được một số câu trả lời. Kiểm tra kết nối mạng rồi bấm Nộp bài lại — bài làm của bạn vẫn còn trên màn hình.");
        return;
      }
      // Hết giờ: nộp dù còn đáp án chưa lưu được (server chấm theo những gì đã lưu). Thử lại khi mạng chập chờn.
      for (let i = 0; i < (auto ? AUTO_SUBMIT_TRIES : 1); i++) {
        try {
          await submitAttempt(attempt.id);
          router.replace(resultHref);
          return;
        } catch (err) {
          if (err instanceof ApiError && (err.status === 401 || err.status === 403 || err.status === 404)) break;
          if (auto && i < AUTO_SUBMIT_TRIES - 1) await new Promise((r) => setTimeout(r, 3000));
        }
      }
      submittingRef.current = false;
      setSubmitting(false);
      setSubmitError("Không nộp được bài. Kiểm tra kết nối mạng rồi thử lại.");
    },
    [attempt.id, resultHref, router, saver],
  );

  const onExpire = useCallback(() => {
    if (sessionEndedRef.current) return;
    setExpired(true);
    void doSubmit(true);
  }, [doSubmit]);

  const saveLabel = (qid: number) => {
    if (snap.lastQuestionId !== qid) return null;
    const st = snap.questions[qid];
    if (sessionLost && (st === "saving" || st === "error")) return <span className="text-warning">Chưa lưu: mất phiên</span>;
    if (st === "saving") {
      return (
        <>
          <Spinner className="size-3.5" label={null} />
          Đang lưu…
        </>
      );
    }
    if (st === "saved") {
      return (
        <>
          <IconCheck size={14} className="text-success motion-safe:animate-tick" />
          Đã lưu
        </>
      );
    }
    if (st === "error") return <span className="text-warning">Chưa lưu được, sẽ thử lại</span>;
    if (st === "rejected") return <span className="text-danger">Không lưu được câu này, hãy chọn lại</span>;
    return null;
  };

  const requestSubmit = () => (unanswered > 0 ? setConfirmOpen(true) : void doSubmit(false));
  const showOffline = !online || snap.offline;

  return (
    <>
      <a
        href="#noi-dung"
        className="sr-only z-50 rounded-control bg-primary px-4 py-2 font-semibold text-on-primary focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      >
        Bỏ qua tới nội dung
      </a>
      <header className="sticky top-0 z-30 border-b border-line bg-surface">
        <div className="mx-auto flex h-16 max-w-6xl items-center gap-2 px-2 sm:px-4">
          <Link
            href={exitHref}
            onClick={(e) => {
              // Điều hướng mềm không kích hoạt beforeunload: còn đáp án chưa lưu được (mất mạng/mất phiên) thì hỏi trước, không mất im lặng.
              // Đang online mà chỉ chờ debounce thì cứ thoát: unmount sẽ gửi nốt.
              if (snap.unsaved > 0 && (snap.offline || sessionLost || !online)) {
                e.preventDefault();
                setExitConfirm(true);
              }
            }}
            aria-label="Thoát, quay lại bài học (bài làm đã được lưu)"
            className="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-control text-ink hover:bg-sunken"
          >
            <IconX />
          </Link>
          <div className="min-w-0 flex-1">
            <p className="truncate text-base font-semibold text-ink">{title}</p>
            <p className="num text-sm text-ink-soft">
              Đã trả lời {answered}/{attempt.total_questions}
            </p>
          </div>
          {remaining !== null ? (
            sessionEnded ? (
              <span className="inline-flex h-11 items-center rounded-control bg-sunken px-3 text-sm font-semibold text-ink-soft">Đồng hồ tạm ẩn</span>
            ) : (
              <Countdown remainingSeconds={remaining} onExpire={onExpire} />
            )
          ) : null}
          <Button className="hidden md:inline-flex" onClick={requestSubmit} loading={submitting} loadingText="Đang nộp…" disabled={expired || locked}>
            Nộp bài
          </Button>
        </div>
        <ProgressBar value={(answered / attempt.total_questions) * 100} label="Số câu đã trả lời" hideLabel size="sm" className="[&_[role=progressbar]]:rounded-none" />
      </header>

      <main id="noi-dung" className="mx-auto w-full max-w-6xl flex-1 px-4 pb-28 pt-6 md:pb-12 lg:grid lg:grid-cols-[1fr_280px] lg:gap-10">
        <div className="flex min-w-0 flex-col gap-5">
          <h1 className="sr-only">{title}</h1>
          {showOffline ? (
            <Alert tone="warning" title="Mất kết nối mạng">
              <span className="flex items-center gap-2">
                <IconWifiOff size={16} className="shrink-0" />
                Các câu trả lời đang được giữ trên máy và sẽ được lưu khi có mạng trở lại. Đừng đóng trang này.
              </span>
            </Alert>
          ) : null}
          {sessionLost ? (
            <Alert tone="warning" title="Mất phiên: đăng nhập lại để tiếp tục">
              Bài làm đã lưu trên máy chủ vẫn còn; đăng nhập lại rồi bấm &ldquo;Làm tiếp&rdquo; để làm tiếp trước khi hết giờ.
              {snap.unsaved > 0 ? ` ${snap.unsaved} câu vừa chọn chưa lưu được và sẽ mất, hãy chọn lại sau khi đăng nhập.` : ""}
            </Alert>
          ) : null}
          {snap.revoked ? (
            <Alert tone="danger" title="Không lưu được bài làm">
              Bạn không còn quyền làm bài này hoặc lượt làm không còn tồn tại.
            </Alert>
          ) : null}
          {submitError ? (
            <Alert tone="danger" role="alert">
              {submitError}
            </Alert>
          ) : null}
          <p className="text-sm text-ink-soft">Mỗi câu chọn 1 đáp án. Bài làm được lưu tự động — bạn có thể thoát và quay lại làm tiếp trước khi hết giờ.</p>

          {attempt.questions.map((q) => {
            const selected = effective[String(q.id)];
            return (
              <fieldset key={q.id} id={`cau-${q.position}`} aria-describedby={`cau-${q.position}-de`} disabled={locked} className="scroll-mt-28 min-w-0 rounded-card border border-line bg-surface p-4 sm:p-5">
                <legend className="sr-only">Câu {q.position}</legend>
                <div className="flex items-center justify-between gap-3">
                  <p aria-hidden="true" className="num text-sm font-extrabold text-primary">
                    Câu {q.position}
                  </p>
                  <p aria-live="polite" className="flex min-h-5 items-center gap-1 text-sm text-ink-soft">
                    {saveLabel(q.id)}
                  </p>
                </div>
                <div id={`cau-${q.position}-de`} className="mt-2">
                  <MathText content={q.content} className="text-question text-ink" />
                </div>
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
                      <MathText as="span" content={o.content} className="min-w-0 flex-1 text-base text-ink sm:text-lg" />
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
            <QuestionGrid attempt={attempt} answers={effective} />
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
        <Button block onClick={requestSubmit} loading={submitting} loadingText="Đang nộp bài…" disabled={expired || locked}>
          Nộp bài
        </Button>
      </div>

      <Dialog open={gridOpen} onClose={() => setGridOpen(false)} title="Bảng câu hỏi" description={`Đã trả lời ${answered}/${attempt.total_questions} câu.`} sheetOnMobile>
        <QuestionGrid attempt={attempt} answers={effective} onJump={() => setGridOpen(false)} />
      </Dialog>

      <ConfirmDialog
        open={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        onConfirm={() => void doSubmit(false)}
        title={`Bạn còn ${unanswered} câu chưa trả lời`}
        description="Câu bỏ trống được tính là sai. Bạn vẫn muốn nộp bài?"
        cancelLabel="Làm tiếp"
        confirmLabel="Vẫn nộp bài"
        loading={submitting}
        loadingText="Đang nộp…"
      />

      <ConfirmDialog
        open={exitConfirm}
        onClose={() => setExitConfirm(false)}
        onConfirm={() => router.push(exitHref)}
        title={`Còn ${snap.unsaved} câu trả lời chưa lưu được`}
        description="Nếu thoát bây giờ, những câu này sẽ mất. Hãy kiểm tra kết nối mạng, hoặc chọn thoát để làm lại sau."
        cancelLabel="Ở lại"
        confirmLabel="Vẫn thoát"
      />

      <Dialog open={expired} onClose={() => undefined} dismissible={false} title="Đã hết giờ làm bài" description="Hệ thống đang tự nộp bài của bạn với các câu đã trả lời.">
        {expired && submitError ? (
          <div className="flex flex-col gap-3">
            <p className="text-base text-danger">{submitError}</p>
            <Button
              onClick={() => {
                submittingRef.current = false;
                void doSubmit(true);
              }}
            >
              Thử nộp lại
            </Button>
          </div>
        ) : (
          <div className="flex items-center gap-3 text-base text-ink">
            <Spinner className="size-5" label={null} />
            Đang chuyển tới trang kết quả…
          </div>
        )}
      </Dialog>
    </>
  );
}
