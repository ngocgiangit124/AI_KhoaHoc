import { notFound } from "next/navigation";
import { Badge, Breadcrumb, ButtonLink, DataTable, EmptyState, IconListChecks, IconPlay, ProgressBar, formatScore, type Column } from "@vitaminvui/ui/v2";
import { LessonOutline } from "@/components/v2/learn/LessonOutline";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { getLearnCourse, lessonCounts } from "@/lib/mock/v2/learn";
import { progressQuizzes } from "@/lib/mock/v2/my-courses";
import type { ProgressQuiz } from "@/lib/mock/v2/types";
import { routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

/** Tiến độ một khóa (US-008 §2.2, GET /me/courses/{course}/progress). */
export default async function CourseProgressPreview({ params }: PageProps<"/v2/tai-khoan/khoa-hoc-cua-toi/[course]">) {
  const { course } = await params;
  const learnCourse = getLearnCourse(Number(course));
  if (!learnCourse) notFound();
  const counts = lessonCounts(learnCourse);
  const quizzes = learnCourse.course.id === 101 ? progressQuizzes : [];
  const columns: Array<Column<ProgressQuiz>> = [
    { key: "title", header: "Bài kiểm tra", cell: (q) => <span className="font-semibold">{q.title}</span> },
    { key: "count", header: "Số câu", align: "right", hideBelow: "md", cell: (q) => q.question_count },
    { key: "attempts", header: "Lượt đã làm", align: "right", hideBelow: "md", cell: (q) => q.attempts_count },
    {
      key: "best",
      header: "Điểm cao nhất",
      align: "right",
      cell: (q) => (q.best_score === null ? <Badge size="sm">Chưa làm</Badge> : <span className="font-extrabold">{formatScore(q.best_score)}</span>),
    },
    {
      key: "act",
      header: <span className="sr-only">Thao tác</span>,
      align: "right",
      cell: (q) => (
        <ButtonLink href={routes.quiz(learnCourse.course.id, q.id)} variant="ghost" size="sm">
          {q.attempted ? "Làm lại" : "Làm bài"}
        </ButtonLink>
      ),
    },
  ];
  return (
    <StudentShell current="my-courses" loggedIn preview={<PreviewBar />}>
      <div className="mx-auto max-w-4xl px-4 pb-14 pt-6 sm:px-6">
        <Breadcrumb items={[{ label: "Khóa học của tôi", href: routes.myCourses }, { label: learnCourse.course.title }]} />
        <h1 className="mt-3 text-title font-extrabold tracking-heading text-ink">{learnCourse.course.title}</h1>
        <div className="mt-5 flex flex-col gap-4 rounded-sheet border border-line bg-surface p-5 sm:flex-row sm:items-center">
          <ProgressBar className="flex-1" size="lg" value={learnCourse.course_percent} label="Tiến độ khóa học" valueText={`${counts.completed}/${counts.total} bài · ${learnCourse.course_percent}%`} />
          {learnCourse.resume_lesson_id ? (
            <ButtonLink href={routes.lesson(learnCourse.course.id, learnCourse.resume_lesson_id)} leadingIcon={<IconPlay size={16} />}>
              Tiếp tục học
            </ButtonLink>
          ) : null}
        </div>

        <section aria-labelledby="bai-kiem-tra" className="mt-10">
          <h2 id="bai-kiem-tra" className="text-heading font-extrabold tracking-heading text-ink">
            Bài kiểm tra
          </h2>
          <div className="mt-4">
            <DataTable
              caption="Điểm các bài kiểm tra của khóa"
              columns={columns}
              rows={quizzes}
              rowKey={(q) => q.id}
              empty={<EmptyState size="inline" icon={<IconListChecks size={24} />} title="Khóa học chưa có bài kiểm tra" headingLevel="h3" />}
            />
          </div>
        </section>

        <section aria-labelledby="bai-hoc" className="mt-10">
          <h2 id="bai-hoc" className="text-heading font-extrabold tracking-heading text-ink">
            Bài học
          </h2>
          <div className="mt-4">
            <LessonOutline data={learnCourse} />
          </div>
        </section>
      </div>
    </StudentShell>
  );
}
