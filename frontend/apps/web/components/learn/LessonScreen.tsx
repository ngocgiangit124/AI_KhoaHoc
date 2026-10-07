"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  Alert,
  Avatar,
  Button,
  ButtonLink,
  EmptyState,
  IconAlertTriangle,
  IconCheckCircle,
  IconChevronLeft,
  IconChevronRight,
  IconInfo,
  IconListChecks,
  IconLock,
  LoadingRegion,
  ProgressBar,
  Skeleton,
  formatClock,
  useToast,
} from "@vitaminvui/ui/v2";
import { useRouter } from "next/navigation";
import { ApiError } from "@vitaminvui/api-client";
import { AppLink } from "@/components/shell/AppLink";
import { useOptionalAuth } from "@/lib/auth/AuthProvider";
import { fetchLearnCourse, fetchLesson } from "@/lib/learn/api";
import { courseRefFromError } from "@/lib/learn/errors";
import { lessonCounts } from "@/lib/learn/progress";
import type { HeartbeatResult, LearnCourse } from "@/lib/learn/schemas";
import { routes } from "@/lib/routes";
import { CompleteButton } from "./CompleteButton";
import { LessonOutline } from "./LessonOutline";
import { VideoPlayer } from "./VideoPlayer";

type Loaded<T> = { status: "loading" } | { status: "ok"; data: T } | { status: "forbidden"; courseSlug: string | null } | { status: "not_found" } | { status: "error" };

function toFailure(err: unknown): Loaded<never> {
  if (err instanceof ApiError && err.status === 403) return { status: "forbidden", courseSlug: courseRefFromError(err)?.slug ?? null };
  if (err instanceof ApiError && err.status === 404) return { status: "not_found" };
  return { status: "error" };
}

/** `load` PHẢI là hàm module (tham chiếu ổn định), `id` là tham số của nó. */
function useLoaded<T>(load: (id: number) => Promise<T>, id: number): [Loaded<T>, () => void] {
  const [state, setState] = useState<Loaded<T>>({ status: "loading" });
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let cancelled = false;
    load(id).then(
      (data) => {
        if (!cancelled) setState({ status: "ok", data });
      },
      (err: unknown) => {
        if (!cancelled) setState(toFailure(err));
      },
    );
    return () => {
      cancelled = true;
    };
  }, [load, id, attempt]);
  // Đặt lại "đang tải" ở chỗ gọi lại (không phải trong effect). Đổi bài/khóa thì trang remount nhờ `key` ở page.tsx.
  return [
    state,
    () => {
      setState({ status: "loading" });
      setAttempt((a) => a + 1);
    },
  ];
}

function LessonSkeleton() {
  return (
    <LoadingRegion label="Đang tải bài học…" className="flex-1 lg:grid lg:grid-cols-[1fr_400px]">
      <div className="lg:px-6 lg:py-6">
        <Skeleton className="aspect-video w-full sm:rounded-card" />
        <div className="flex flex-col gap-3 px-4 py-5 lg:px-0">
          <Skeleton className="h-4 w-40" />
          <Skeleton className="h-8 w-3/4" />
        </div>
      </div>
      <div className="flex flex-col gap-3 border-t border-line bg-paper px-4 py-5 lg:border-l lg:border-t-0">
        <Skeleton className="h-6 w-48" />
        <Skeleton className="h-14 w-full" />
        <Skeleton className="h-14 w-full" />
        <Skeleton className="h-14 w-full" />
      </div>
    </LoadingRegion>
  );
}

/**
 * Chưa sở hữu khóa: API trả `errors.course` khi khóa đang published → chuyển về trang chi tiết khóa (US-006 AC3, nơi mua/đăng ký);
 * không có (khóa nháp/ẩn) thì ở lại màn thông báo + nút về danh mục.
 */
function BlockedView({ courseSlug }: { courseSlug: string | null }) {
  const router = useRouter();
  useEffect(() => {
    if (courseSlug) router.replace(routes.course(courseSlug));
  }, [courseSlug, router]);
  if (courseSlug) {
    return (
      <main id="noi-dung" className="flex flex-1 items-center justify-center">
        <EmptyState
          headingLevel="h1"
          icon={<IconLock size={40} />}
          title="Bạn chưa sở hữu khóa học này"
          description="Đang chuyển tới trang khóa học để bạn đăng ký hoặc mua."
          action={<ButtonLink href={routes.course(courseSlug)}>Tới trang khóa học</ButtonLink>}
        />
      </main>
    );
  }
  return (
    <main id="noi-dung" className="flex flex-1 items-center justify-center">
      <EmptyState
        headingLevel="h1"
        icon={<IconLock size={40} />}
        title="Bạn chưa sở hữu khóa học này"
        description="Bài học này chỉ dành cho học sinh đã đăng ký hoặc mua khóa học. Hãy chọn khóa học trong danh mục để bắt đầu."
        action={<ButtonLink href={routes.catalog}>Xem danh sách khóa học</ButtonLink>}
      />
    </main>
  );
}

function NotFoundView() {
  return (
    <main id="noi-dung" className="flex flex-1 items-center justify-center">
      <EmptyState
        headingLevel="h1"
        icon={<IconAlertTriangle size={40} />}
        title="Không tìm thấy bài học"
        description="Bài học hoặc khóa học này không còn tồn tại."
        action={<ButtonLink href={routes.catalog}>Về danh mục khóa học</ButtonLink>}
      />
    </main>
  );
}

function ErrorView({ onRetry }: { onRetry: () => void }) {
  return (
    <main id="noi-dung" className="flex flex-1 items-center justify-center">
      <EmptyState
        headingLevel="h1"
        icon={<IconAlertTriangle size={40} />}
        title="Không tải được bài học"
        description="Có thể do kết nối mạng chập chờn. Hãy thử lại."
        action={<Button onClick={onRetry}>Thử lại</Button>}
      />
    </main>
  );
}

/**
 * Trang học video (US-006, design-system-v2 §12.3): màn học yên tĩnh — không header site/footer/bottom-nav, không nền ô ly.
 * Mọi dữ liệu lấy từ TRÌNH DUYỆT (cookie phiên host-only của API; link phát ràng IP học sinh).
 */
export function LessonScreen({ courseId, lessonId }: { courseId: number; lessonId: number }) {
  const toast = useToast();
  const auth = useOptionalAuth();
  const [lessonState, retryLesson] = useLoaded(fetchLesson, lessonId);
  const [courseState, retryCourse] = useLoaded(fetchLearnCourse, courseId);
  const [done, setDone] = useState<ReadonlySet<number>>(() => new Set());
  const doneRef = useRef<ReadonlySet<number>>(done);
  const [livePercent, setLivePercent] = useState<number | null>(null);
  const [videoKind, setVideoKind] = useState<"hls" | "embed" | null>(null);
  // Thu hồi giữa phiên: `null` = chưa bị; có `slug` (khóa published) thì hiện nút "Xem khóa học" (không tự chuyển trang).
  const [revoked, setRevoked] = useState<{ slug: string | null } | null>(null);
  const onRevoked = useCallback((course: { slug: string } | null) => setRevoked({ slug: course?.slug ?? null }), []);

  const lessonData = lessonState.status === "ok" ? lessonState.data : null;
  const lessonWasCompleted = lessonData?.progress?.status === "completed";

  const onProgress = useCallback(
    (result: HeartbeatResult) => {
      setLivePercent(result.course_percent);
      if (result.completed && !doneRef.current.has(lessonId)) {
        // Cập nhật ngoài hàm của setState: gọi toast.show (setState của provider khác) trong updater là tác dụng phụ khi render.
        doneRef.current = new Set(doneRef.current).add(lessonId);
        setDone(doneRef.current);
        // Toast chỉ lần đầu hoàn thành (bài đã xong từ trước thì replay không báo lại).
        if (!lessonWasCompleted) {
          toast.show({ tone: "success", title: "Bạn đã hoàn thành bài này", description: `Tiến độ khóa học: ${result.course_percent}%.` });
        }
      }
    },
    [lessonId, lessonWasCompleted, toast],
  );

  const userName = auth?.state.status === "user" ? auth.state.user.name : "Tài khoản";
  const outline: LearnCourse | null = courseState.status === "ok" ? courseState.data : null;
  const counts = useMemo(() => (outline ? lessonCounts(outline, done) : null), [outline, done]);

  if (lessonState.status === "loading") return <LessonSkeleton />;
  if (lessonState.status === "forbidden") return <BlockedView courseSlug={lessonState.courseSlug} />;
  if (lessonState.status === "not_found") return <NotFoundView />;
  if (lessonState.status === "error") return <ErrorView onRetry={retryLesson} />;
  const { lesson, course, prev, next, quizzes, progress, can_track } = lessonState.data;
  if (lesson.course_id !== courseId) return <NotFoundView />;

  const percent = livePercent ?? outline?.course_percent ?? 0;
  const completed = done.has(lesson.id) || progress?.status === "completed";

  return (
    <>
      <a
        href="#noi-dung"
        className="sr-only z-50 rounded-control bg-primary px-4 py-2 font-semibold text-on-primary focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      >
        Bỏ qua tới nội dung
      </a>
      <header className="sticky top-0 z-30 border-b border-line bg-surface">
        <div className="flex h-14 items-center gap-2 px-2 sm:px-4">
          <AppLink href={routes.course(course.slug)} className="focus-ring flex min-h-11 min-w-0 flex-1 items-center gap-1 rounded-control pr-2 hover:text-primary">
            <IconChevronLeft className="shrink-0" />
            <span className="sr-only">Quay lại khóa học: </span>
            <span className="truncate text-base font-semibold text-ink">{course.title}</span>
          </AppLink>
          {counts ? (
            <div className="hidden w-56 md:block">
              <ProgressBar value={percent} label="Tiến độ khóa" valueText={`${counts.completed}/${counts.total} bài`} size="sm" />
            </div>
          ) : null}
          <AppLink href={routes.account} className="focus-ring rounded-full" aria-label="Tài khoản">
            <Avatar name={userName} size="sm" />
          </AppLink>
        </div>
      </header>

      <main id="noi-dung" className="flex-1 lg:grid lg:grid-cols-[1fr_400px]">
        <div className="min-w-0 lg:px-6 lg:py-6">
          <VideoPlayer
            key={lesson.id}
            lessonId={lesson.id}
            title={lesson.title}
            durationSeconds={lesson.duration_seconds}
            canTrack={can_track}
            videoReady={lesson.video_ready}
            onProgress={onProgress}
            onRevoked={onRevoked}
            onKind={setVideoKind}
          />

          <div className="flex flex-col gap-5 px-4 py-5 lg:px-0">
            <div className="flex flex-col gap-1">
              <p className="text-sm font-medium text-ink-soft">{lesson.chapter_title}</p>
              <h1 className="text-heading font-extrabold tracking-heading text-ink md:text-heading-lg">{lesson.title}</h1>
              {completed ? (
                <p className="mt-1 flex items-center gap-1.5 text-sm font-semibold text-success">
                  <IconCheckCircle size={18} />
                  Đã hoàn thành bài này
                </p>
              ) : can_track ? (
                <p className="mt-1 flex items-start gap-1.5 text-sm text-ink-soft">
                  <IconInfo size={18} className="shrink-0" />
                  <span>
                    Xem tới 90% video, bài sẽ tự được đánh dấu hoàn thành.
                    {progress && progress.last_position_seconds > 0 ? ` Bạn đang ở ${formatClock(progress.last_position_seconds)}.` : ""}
                  </span>
                </p>
              ) : (
                <p className="mt-1 flex items-start gap-1.5 text-sm text-ink-soft">
                  <IconInfo size={18} className="shrink-0" />
                  Đây là bài học thử. Đăng ký khóa học để lưu tiến độ học.
                </p>
              )}
            </div>

            {videoKind === "embed" && can_track ? (
              <Alert
                tone="info"
                action={<CompleteButton lessonId={lesson.id} completed={completed} onDone={onProgress} onRevoked={onRevoked} />}
              >
                Video này phát từ trang ngoài nên không tự ghi tiến độ. Xem xong, hãy bấm &ldquo;Đánh dấu đã học&rdquo;.
              </Alert>
            ) : null}
            {revoked ? (
              <Alert
                tone="warning"
                title="Quyền học khóa này đã bị thu hồi"
                action={revoked.slug ? <ButtonLink href={routes.course(revoked.slug)}>Xem khóa học</ButtonLink> : undefined}
              >
                Hãy liên hệ nhà trường hoặc quản trị viên nếu đây là nhầm lẫn.
              </Alert>
            ) : null}

            <nav aria-label="Chuyển bài" className="grid grid-cols-2 gap-3">
              {prev ? (
                <ButtonLink href={routes.lesson(course.id, prev.id)} variant="secondary" leadingIcon={<IconChevronLeft size={18} />} className="justify-start">
                  Bài trước
                </ButtonLink>
              ) : (
                <span />
              )}
              {next ? (
                <ButtonLink href={routes.lesson(course.id, next.id)} trailingIcon={<IconChevronRight size={18} />} className="justify-end">
                  Bài tiếp theo
                </ButtonLink>
              ) : (
                <span />
              )}
            </nav>

            {quizzes.length ? (
              <section aria-labelledby="bai-tap" className="rounded-card border border-line bg-surface p-4">
                <h2 id="bai-tap" className="flex items-center gap-2 text-base font-semibold text-ink">
                  <IconListChecks className="text-accent-ink" />
                  Bài tập của bài này
                </h2>
                <ul className="mt-3 flex flex-col gap-2">
                  {quizzes.map((q) => (
                    <li key={q.id}>
                      <p className="font-medium text-ink">{q.title}</p>
                      <p className="num text-sm text-ink-soft">
                        {q.question_count} câu{q.time_limit_minutes ? ` · ${q.time_limit_minutes} phút` : " · không giới hạn thời gian"}
                      </p>
                    </li>
                  ))}
                </ul>
              </section>
            ) : null}
          </div>
        </div>

        <aside aria-labelledby="noi-dung-khoa" className="border-t border-line bg-paper px-4 py-5 lg:sticky lg:top-14 lg:h-[calc(100dvh-3.5rem)] lg:overflow-y-auto lg:border-l lg:border-t-0">
          <h2 id="noi-dung-khoa" className="text-lg font-extrabold tracking-heading text-ink">
            Nội dung khóa học
          </h2>
          {courseState.status === "loading" ? (
            <LoadingRegion label="Đang tải mục lục…" className="mt-4 flex flex-col gap-3">
              <Skeleton className="h-14 w-full" />
              <Skeleton className="h-14 w-full" />
            </LoadingRegion>
          ) : null}
          {courseState.status === "error" || courseState.status === "forbidden" || courseState.status === "not_found" ? (
            <Alert
              tone="warning"
              className="mt-4"
              action={
                <Button variant="secondary" size="sm" onClick={retryCourse}>
                  Thử lại
                </Button>
              }
            >
              Không tải được mục lục khóa học.
            </Alert>
          ) : null}
          {outline && counts ? (
            <>
              <ProgressBar className="my-4" value={percent} label="Tiến độ của bạn" valueText={`${counts.completed}/${counts.total} bài · ${percent}%`} />
              <LessonOutline data={outline} currentLessonId={lesson.id} done={done} />
            </>
          ) : null}
        </aside>
      </main>
    </>
  );
}
