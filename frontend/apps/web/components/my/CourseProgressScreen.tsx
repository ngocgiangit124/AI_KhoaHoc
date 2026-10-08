"use client";

import { Badge, Breadcrumb, ButtonLink, EmptyState, IconInfo, IconPlay, ProgressBar, Skeleton } from "@vitaminvui/ui/v2";
import { fetchCourseProgress } from "@/lib/my/api";
import { courseStatus, ctaLabel, progressText } from "@/lib/my/format";
import type { CourseProgress } from "@/lib/my/schemas";
import { routes } from "@/lib/routes";
import { MyNotice } from "./MyNotice";
import { ProgressOutline } from "./ProgressOutline";
import { QuizScoreTable } from "./QuizScoreTable";
import { PageSkeletonRegion, RequireUser } from "./RequireUser";
import { useMyLoad } from "./useMyLoad";

function DetailSkeleton() {
  return (
    <PageSkeletonRegion label="Đang tải tiến độ khóa học…">
      <Skeleton className="h-8 w-2/3" />
      <Skeleton className="h-24 w-full" />
      <Skeleton className="h-40 w-full" />
    </PageSkeletonRegion>
  );
}

function Loaded({ data }: { data: CourseProgress }) {
  const { course, progress } = data;
  const status = courseStatus(progress, data.enrollment.last_accessed_at);
  return (
    <>
      <Breadcrumb items={[{ label: "Khóa học của tôi", href: routes.myCourses }, { label: course.title }]} />
      <div className="mt-3 flex flex-wrap items-center gap-3">
        <h1 className="text-title font-extrabold tracking-heading text-ink">{course.title}</h1>
        <Badge tone={status.tone}>{status.label}</Badge>
      </div>
      {!course.is_published ? (
        <p className="mt-2 flex items-start gap-1.5 text-sm text-ink-soft">
          <IconInfo size={16} className="mt-0.5 shrink-0" />
          Khóa đã ngừng bán, bạn vẫn học được như bình thường.
        </p>
      ) : null}
      <div className="mt-5 flex flex-col gap-4 rounded-sheet border border-line bg-surface p-5 sm:flex-row sm:items-center">
        <ProgressBar
          className="flex-1"
          size="lg"
          value={progress.percent}
          label="Tiến độ khóa học"
          valueText={progressText(progress)}
          hasContent={progress.has_content}
          tone={progress.is_completed ? "success" : "primary"}
        />
        {data.resume_lesson_id ? (
          <ButtonLink href={routes.lesson(course.id, data.resume_lesson_id)} leadingIcon={<IconPlay size={16} />}>
            {progress.is_completed ? "Xem lại bài học" : ctaLabel(progress, data.enrollment.last_accessed_at)}
          </ButtonLink>
        ) : null}
      </div>

      <section aria-labelledby="bai-kiem-tra" className="mt-10">
        <h2 id="bai-kiem-tra" className="text-heading font-extrabold tracking-heading text-ink">
          Bài kiểm tra
        </h2>
        <div className="mt-4">
          <QuizScoreTable courseId={course.id} quizzes={data.quizzes} />
        </div>
      </section>

      <section aria-labelledby="bai-hoc" className="mt-10">
        <h2 id="bai-hoc" className="text-heading font-extrabold tracking-heading text-ink">
          Bài học
        </h2>
        <div className="mt-4">
          {data.chapters.length === 0 ? (
            <EmptyState size="inline" title="Khóa học chưa có nội dung" headingLevel="h3" />
          ) : (
            <ProgressOutline data={data} />
          )}
        </div>
      </section>
    </>
  );
}

function Content({ courseId }: { courseId: number }) {
  const [state, retry] = useMyLoad(fetchCourseProgress, courseId);
  if (state.status === "loading") return <DetailSkeleton />;
  if (state.status === "failed") {
    return (
      <>
        <Breadcrumb items={[{ label: "Khóa học của tôi", href: routes.myCourses }, { label: "Tiến độ" }]} />
        <div className="mt-4">
          <MyNotice kind={state.kind} courseSlug={state.courseSlug} onRetry={retry} what="course" />
        </div>
      </>
    );
  }
  return <Loaded data={state.data} />;
}

/** `/tai-khoan/khoa-hoc-cua-toi/{course}` (US-008 §2.2, GET /me/courses/{course}/progress). */
export function CourseProgressScreen({ courseId }: { courseId: number }) {
  return (
    <div className="mx-auto w-full max-w-4xl px-4 pb-14 pt-6 sm:px-6">
      <RequireUser next={routes.myCourse(courseId)} skeleton={<DetailSkeleton />}>
        <Content key={courseId} courseId={courseId} />
      </RequireUser>
    </div>
  );
}
