"use client";

import { useMemo, useState } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { Alert, Button, ButtonLink, IconChevronLeft, IconListChecks, LoadingRegion, Skeleton, formatScore } from "@vitaminvui/ui/v2";
import { AppLink } from "@/components/shell/AppLink";
import { fetchLearnCourse } from "@/lib/learn/api";
import { fetchHistory, startAttempt } from "@/lib/quiz/api";
import { classifyQuizLoadError, type QuizLoadFailure } from "@/lib/quiz/errors";
import { findQuizPlacement } from "@/lib/quiz/outline";
import type { AttemptHistory, AttemptInProgress } from "@/lib/quiz/schemas";
import { routes } from "@/lib/routes";
import { QuizNotice } from "./QuizNotice";
import { QuizRunner } from "./QuizRunner";
import { useLoaded } from "./useLoaded";

interface Intro {
  title: string;
  questionCount: number;
  timeLimitMinutes: number | null;
  exitHref: string;
  history: AttemptHistory;
}

interface IntroArg {
  courseId: number;
  quizId: number;
}

async function loadIntro({ courseId, quizId }: IntroArg): Promise<Intro> {
  const [outline, history] = await Promise.all([fetchLearnCourse(courseId), fetchHistory(quizId)]);
  const placement = findQuizPlacement(outline, quizId);
  if (!placement) throw new ApiError(404, { message: "Không tìm thấy bài kiểm tra." });
  return {
    title: placement.quiz.title,
    questionCount: placement.quiz.question_count,
    timeLimitMinutes: placement.quiz.time_limit_minutes,
    exitHref: placement.lessonId ? routes.lesson(courseId, placement.lessonId) : routes.learn(courseId),
    history,
  };
}

function IntroSkeleton() {
  return (
    <LoadingRegion label="Đang tải bài kiểm tra…" className="mx-auto flex w-full max-w-xl flex-1 flex-col gap-4 px-4 py-10">
      <Skeleton className="h-8 w-2/3" />
      <Skeleton className="h-24 w-full" />
      <Skeleton className="h-12 w-48" />
    </LoadingRegion>
  );
}

/**
 * Trang làm quiz `/hoc/{course}/quiz/{quiz}`: màn giới thiệu (số câu, thời gian, lịch sử) rồi mới bắt đầu lượt. Tách bước này để việc
 * mở trang/F5/prefetch không tự khởi động đồng hồ; có lượt đang làm dở thì nút thành "Làm tiếp" (POST trả lại đúng lượt đó, 200).
 */
export function QuizScreen({ courseId, quizId }: { courseId: number; quizId: number }) {
  const arg = useMemo<IntroArg>(() => ({ courseId, quizId }), [courseId, quizId]);
  const [intro, retry] = useLoaded(loadIntro, arg);
  const [starting, setStarting] = useState(false);
  const [startError, setStartError] = useState<QuizLoadFailure | null>(null);
  const [attempt, setAttempt] = useState<AttemptInProgress | null>(null);

  const backHref = intro.status === "ok" ? intro.data.exitHref : routes.learn(courseId);

  if (attempt && intro.status === "ok") {
    return <QuizRunner key={attempt.id} attempt={attempt} courseId={courseId} quizId={quizId} title={intro.data.title} exitHref={intro.data.exitHref} />;
  }
  if (intro.status === "loading") return <IntroSkeleton />;
  if (intro.status === "failed") return <QuizNotice kind={intro.kind} courseSlug={intro.courseSlug} backHref={backHref} onRetry={retry} />;
  if (startError && startError !== "error" && startError !== "throttled") {
    return <QuizNotice kind={startError} courseSlug={null} backHref={backHref} />;
  }

  const { title, questionCount, timeLimitMinutes, exitHref, history } = intro.data;
  const inProgress = history.data.find((a) => a.status === "in_progress");
  const lastSubmitted = history.data.find((a) => a.status === "submitted");

  async function start() {
    setStarting(true);
    setStartError(null);
    try {
      setAttempt(await startAttempt(quizId));
    } catch (err) {
      setStartError(classifyQuizLoadError(err));
      setStarting(false);
    }
  }

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
      <main id="noi-dung" className="mx-auto flex w-full max-w-xl flex-1 flex-col gap-5 px-4 py-8">
        <div className="flex flex-col gap-1">
          <p className="flex items-center gap-2 text-sm font-medium text-ink-soft">
            <IconListChecks size={18} className="text-accent-ink" />
            Bài kiểm tra trắc nghiệm
          </p>
          <h1 className="text-title font-extrabold tracking-heading text-ink">{title}</h1>
        </div>

        <ul className="num flex flex-col gap-1 rounded-card border border-line bg-surface p-4 text-base text-ink">
          <li>{questionCount} câu, mỗi câu chọn 1 trong 4 đáp án.</li>
          <li>{timeLimitMinutes ? `Thời gian làm bài: ${timeLimitMinutes} phút. Hết giờ, bài tự được nộp.` : "Không giới hạn thời gian."}</li>
          <li>Bài làm được lưu tự động; điểm tính trên thang 10 và bạn xem được đáp án sau khi nộp.</li>
          {history.attempts_count > 0 ? (
            <li>
              Bạn đã làm {history.attempts_count} lần{history.best_score !== null ? `, điểm cao nhất ${formatScore(history.best_score)}/10` : ""}.
            </li>
          ) : null}
        </ul>

        {inProgress ? (
          <Alert tone="info">Bạn đang có một lần làm bài dở dang. Bấm &ldquo;Làm tiếp&rdquo; để quay lại đúng chỗ đã dừng.</Alert>
        ) : null}
        {startError === "throttled" ? <Alert tone="warning">Bạn thao tác hơi nhanh. Đợi một chút rồi thử lại.</Alert> : null}
        {startError === "error" ? <Alert tone="danger">Chưa bắt đầu được bài làm. Kiểm tra kết nối mạng rồi thử lại.</Alert> : null}

        <div className="flex flex-col gap-3 sm:flex-row">
          <Button onClick={() => void start()} loading={starting} loadingText="Đang chuẩn bị…">
            {inProgress ? "Làm tiếp" : history.attempts_count > 0 ? "Làm lại" : "Bắt đầu làm bài"}
          </Button>
          {lastSubmitted ? (
            <ButtonLink href={routes.quizResult(courseId, quizId, { attemptId: lastSubmitted.id })} variant="secondary">
              Xem kết quả lần trước
            </ButtonLink>
          ) : null}
        </div>
      </main>
    </>
  );
}
