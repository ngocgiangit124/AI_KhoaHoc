"use client";

import { useMemo } from "react";
import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { Badge, ButtonLink, IconChevronLeft, IconRotateCcw, LinkTabs, LoadingRegion, Skeleton, formatDateTime } from "@vitaminvui/ui/v2";
import { AppLink } from "@/components/shell/AppLink";
import { ResultQuestion, ScoreRing } from "@/components/v2/quiz/ResultView";
import { fetchLearnCourse } from "@/lib/learn/api";
import { fetchAttempt, fetchHistory } from "@/lib/quiz/api";
import { findQuizPlacement, scoreHeadline, type ResultFilter } from "@/lib/quiz/outline";
import type { AttemptResult } from "@/lib/quiz/schemas";
import { routes } from "@/lib/routes";
import { MathText } from "./MathText";
import { QuizNotice } from "./QuizNotice";
import { useLoaded } from "./useLoaded";

interface Data {
  result: AttemptResult | null; // null: lượt đang làm → quay lại trang làm bài
  title: string;
  exitHref: string;
  nextHref: string;
}

interface Arg {
  courseId: number;
  quizId: number;
  attemptId: number | undefined;
}

async function loadResult({ courseId, quizId, attemptId }: Arg): Promise<Data> {
  const outlineP = fetchLearnCourse(courseId);
  let id = attemptId;
  if (id === undefined) {
    const history = await fetchHistory(quizId);
    id = history.data.find((a) => a.status === "submitted")?.id;
  }
  const attempt = id === undefined ? null : await fetchAttempt(id);
  const outline = await outlineP;
  const placement = findQuizPlacement(outline, quizId);
  if (!placement) throw new ApiError(404, { message: "Không tìm thấy bài kiểm tra." });
  if (attempt && attempt.quiz_id !== quizId) throw new ApiError(404, { message: "Không tìm thấy lượt làm bài." });
  return {
    result: attempt?.status === "submitted" ? attempt : null,
    title: placement.quiz.title,
    exitHref: placement.lessonId ? routes.lesson(courseId, placement.lessonId) : routes.learn(courseId),
    nextHref: placement.nextLessonId ? routes.lesson(courseId, placement.nextLessonId) : routes.learn(courseId),
  };
}

function ResultSkeleton() {
  return (
    <LoadingRegion label="Đang tải kết quả…" className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 px-4 py-8">
      <Skeleton className="h-40 w-full" />
      <Skeleton className="h-32 w-full" />
      <Skeleton className="h-32 w-full" />
    </LoadingRegion>
  );
}

function ToQuiz({ href }: { href: string }) {
  const router = useRouter();
  useEffect(() => {
    router.replace(href);
  }, [href, router]);
  return <ResultSkeleton />;
}

/** Kết quả quiz (US-007 BR2/BR5): điểm thang 10, xem lại từng câu kèm đáp án đúng + lời giải, lọc câu sai/bỏ trống theo URL. */
export function QuizResultScreen({ courseId, quizId, attemptId, filter }: { courseId: number; quizId: number; attemptId?: number; filter?: ResultFilter }) {
  const arg = useMemo<Arg>(() => ({ courseId, quizId, attemptId }), [courseId, quizId, attemptId]);
  const [state, retry] = useLoaded(loadResult, arg);

  if (state.status === "loading") return <ResultSkeleton />;
  if (state.status === "failed") return <QuizNotice kind={state.kind} courseSlug={state.courseSlug} backHref={routes.learn(courseId)} onRetry={retry} />;
  const { result: r, title, exitHref, nextHref } = state.data;
  // Chưa có lượt nào đã nộp (hoặc lượt này còn đang làm): về trang làm bài.
  if (!r) return <ToQuiz href={routes.quiz(courseId, quizId)} />;

  const wrong = r.questions.filter((q) => q.selected_option_id !== null && !q.is_correct);
  const skipped = r.questions.filter((q) => q.selected_option_id === null);
  const shown = filter === "sai" ? wrong : filter === "bo-trong" ? skipped : r.questions;
  const tab = (loc?: ResultFilter) => routes.quizResult(courseId, quizId, { attemptId: r.id, filter: loc });

  return (
    <>
      <header className="border-b border-line bg-surface">
        <div className="mx-auto flex h-14 max-w-3xl items-center px-2 sm:px-4">
          <AppLink href={exitHref} className="focus-ring flex min-h-11 items-center gap-1 rounded-control pr-2 font-semibold text-ink hover:text-primary">
            <IconChevronLeft />
            Quay lại bài học
          </AppLink>
        </div>
      </header>
      <main id="noi-dung" className="mx-auto w-full max-w-3xl flex-1 px-4 pb-16 pt-6">
        <section aria-labelledby="ket-qua" className="flex flex-col items-center gap-6 rounded-sheet border border-line bg-surface p-6 text-center sm:flex-row sm:text-left">
          <ScoreRing score={r.score} />
          <div className="flex flex-1 flex-col gap-2">
            <p className="text-sm font-medium text-ink-soft">{title}</p>
            <h1 id="ket-qua" className="text-title font-extrabold tracking-heading text-ink">
              {scoreHeadline(r.score)}
            </h1>
            <p className="num text-base text-ink">
              Đúng {r.correct_count}/{r.total_questions} câu · Sai {wrong.length} · Bỏ trống {r.unanswered_count}
            </p>
            <p className="text-sm text-ink-soft">Nộp lúc {formatDateTime(r.submitted_at)}</p>
            {r.auto_submitted ? (
              <div>
                <Badge tone="warning">Bài đã được tự động nộp khi hết giờ</Badge>
              </div>
            ) : null}
            <div className="mt-2 flex flex-col gap-2 sm:flex-row">
              <ButtonLink href={nextHref}>Học bài tiếp theo</ButtonLink>
              <ButtonLink href={routes.quiz(courseId, quizId)} variant="secondary" leadingIcon={<IconRotateCcw size={16} />}>
                Làm lại
              </ButtonLink>
            </div>
          </div>
        </section>

        <h2 className="mt-10 text-heading font-extrabold tracking-heading text-ink">Xem lại bài làm</h2>
        <LinkTabs
          className="mt-3"
          label="Lọc câu hỏi"
          items={[
            { href: tab(), label: "Tất cả", count: r.questions.length, current: !filter },
            { href: tab("sai"), label: "Câu sai", count: wrong.length, current: filter === "sai" },
            { href: tab("bo-trong"), label: "Bỏ trống", count: skipped.length, current: filter === "bo-trong" },
          ]}
        />
        <div className="mt-5 flex flex-col gap-4">
          {shown.length === 0 ? <p className="rounded-card border border-line bg-surface p-4 text-base text-ink-soft">Không có câu nào trong mục này.</p> : null}
          {shown.map((q) => (
            <ResultQuestion key={q.id} q={q} MathText={MathText} />
          ))}
        </div>
      </main>
    </>
  );
}
