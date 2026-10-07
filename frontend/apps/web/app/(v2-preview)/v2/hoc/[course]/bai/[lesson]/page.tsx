import Link from "next/link";
import { notFound } from "next/navigation";
import {
  Avatar,
  ButtonLink,
  IconCheckCircle,
  IconChevronLeft,
  IconChevronRight,
  IconInfo,
  IconListChecks,
  ProgressBar,
  SessionEndedDialog,
  formatClock,
} from "@vitaminvui/ui/v2";
import { CompletionNotice } from "@/components/v2/learn/CompletionNotice";
import { LessonOutline } from "@/components/v2/learn/LessonOutline";
import { VideoFrame, type VideoState } from "@/components/v2/learn/VideoFrame";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { getLearnCourse, getLesson, lessonCounts } from "@/lib/mock/v2/learn";
import { one, routes, sampleStudent } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const STATES: Array<{ key?: string; label: string }> = [
  { label: "Đang xem" },
  { key: "dang-tai", label: "Đang tải video" },
  { key: "loi", label: "Lỗi tải video" },
  { key: "dang-xu-ly", label: "Video đang xử lý" },
  { key: "hoan-thanh", label: "Vừa hoàn thành" },
  { key: "phien-thay-the", label: "Đăng nhập ở thiết bị khác (401)" },
  { key: "phien-thu-hoi", label: "Mật khẩu/email vừa đổi (401)" },
];

/** Trang học video (US-006). Không có header/footer/bottom-nav của site: chỉ còn đường quay lại khóa học. */
export default async function LessonPreview({ params, searchParams }: PageProps<"/v2/hoc/[course]/bai/[lesson]">) {
  const { course: courseParam, lesson: lessonParam } = await params;
  const sp = await searchParams;
  const data = getLesson(Number(courseParam), Number(lessonParam));
  const learnCourse = getLearnCourse(Number(courseParam));
  if (!data || !learnCourse) notFound();
  const state = one(sp["trang-thai"]);
  const videoState: VideoState = state === "dang-tai" ? "loading" : state === "loi" ? "error" : state === "dang-xu-ly" || !data.lesson.video_ready ? "processing" : "ready";
  const counts = lessonCounts(learnCourse);
  const { lesson, prev, next, progress } = data;
  const base = routes.lesson(data.course.id, lesson.id);

  return (
    <>
      <PreviewBar variants={STATES.map((s) => ({ label: s.label, href: s.key ? `${base}?trang-thai=${s.key}` : base, current: s.key === state }))} />
      {state === "hoan-thanh" ? <CompletionNotice lessonTitle={lesson.title} coursePercent={44} /> : null}
      {/* US-014: 401 SESSION_REPLACED / SESSION_REVOKED khi đang học → hộp thoại chặn, không đóng được. */}
      {state === "phien-thay-the" || state === "phien-thu-hoi" ? (
        <SessionEndedDialog
          open
          reason={state === "phien-thay-the" ? "replaced" : "revoked"}
          context="lesson"
          loginHref={`${routes.login}?next=${encodeURIComponent(base)}`}
          forgotHref={routes.forgot}
        />
      ) : null}

      <header className="sticky top-0 z-30 border-b border-line bg-surface">
        <div className="flex h-14 items-center gap-2 px-2 sm:px-4">
          <Link href={routes.myCourse(data.course.id)} className="focus-ring flex min-h-11 min-w-0 flex-1 items-center gap-1 rounded-control pr-2 hover:text-primary">
            <IconChevronLeft className="shrink-0" />
            <span className="sr-only">Quay lại khóa học: </span>
            <span className="truncate text-base font-semibold text-ink">{data.course.title}</span>
          </Link>
          <div className="hidden w-56 md:block">
            <ProgressBar value={learnCourse.course_percent} label="Tiến độ khóa" valueText={`${counts.completed}/${counts.total} bài`} size="sm" />
          </div>
          <Link href={routes.account} className="focus-ring rounded-full" aria-label="Tài khoản">
            <Avatar name={sampleStudent.name} size="sm" />
          </Link>
        </div>
      </header>

      <main id="noi-dung" className="flex-1 lg:grid lg:grid-cols-[1fr_400px]">
        <div className="min-w-0 lg:px-6 lg:py-6">
          <VideoFrame title={lesson.title} durationSeconds={lesson.duration_seconds} positionSeconds={progress?.last_position_seconds ?? 0} state={videoState} />

          <div className="flex flex-col gap-5 px-4 py-5 lg:px-0">
            <div className="flex flex-col gap-1">
              <p className="text-sm font-medium text-ink-soft">{lesson.chapter_title}</p>
              <h1 className="text-heading font-extrabold tracking-heading text-ink md:text-heading-lg">{lesson.title}</h1>
              {progress?.status === "completed" || state === "hoan-thanh" ? (
                <p className="mt-1 flex items-center gap-1.5 text-sm font-semibold text-success">
                  <IconCheckCircle size={18} />
                  Đã hoàn thành bài này
                </p>
              ) : (
                <p className="mt-1 flex items-start gap-1.5 text-sm text-ink-soft">
                  <IconInfo size={18} className="shrink-0" />
                  Xem tới 90% video, bài sẽ tự được đánh dấu hoàn thành.
                  {progress ? ` Bạn đang ở ${formatClock(progress.last_position_seconds)}.` : ""}
                </p>
              )}
            </div>

            <nav aria-label="Chuyển bài" className="grid grid-cols-2 gap-3">
              {prev ? (
                <ButtonLink href={routes.lesson(data.course.id, prev.id)} variant="secondary" leadingIcon={<IconChevronLeft size={18} />} className="justify-start">
                  Bài trước
                </ButtonLink>
              ) : (
                <span />
              )}
              {next ? (
                <ButtonLink href={routes.lesson(data.course.id, next.id)} trailingIcon={<IconChevronRight size={18} />} className="justify-end">
                  Bài tiếp theo
                </ButtonLink>
              ) : (
                <span />
              )}
            </nav>

            {data.quizzes.length ? (
              <section aria-labelledby="bai-tap" className="rounded-card border border-line bg-surface p-4">
                <h2 id="bai-tap" className="flex items-center gap-2 text-base font-semibold text-ink">
                  <IconListChecks className="text-accent-ink" />
                  Bài tập của bài này
                </h2>
                <ul className="mt-3 flex flex-col gap-2">
                  {data.quizzes.map((q) => (
                    <li key={q.id} className="flex flex-col gap-3 sm:flex-row sm:items-center">
                      <div className="flex-1">
                        <p className="font-medium text-ink">{q.title}</p>
                        <p className="num text-sm text-ink-soft">
                          {q.question_count} câu{q.time_limit_minutes ? ` · ${q.time_limit_minutes} phút` : " · không giới hạn thời gian"}
                        </p>
                      </div>
                      <ButtonLink href={routes.quiz(data.course.id, q.id)} variant="soft">
                        Làm bài
                      </ButtonLink>
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
          <ProgressBar className="my-4" value={learnCourse.course_percent} label="Tiến độ của bạn" valueText={`${counts.completed}/${counts.total} bài · ${learnCourse.course_percent}%`} />
          <LessonOutline data={learnCourse} currentLessonId={lesson.id} />
        </aside>
      </main>
    </>
  );
}
