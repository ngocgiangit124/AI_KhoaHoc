import { Badge, ButtonLink, DataTable, EmptyState, IconListChecks, formatScore, type Column } from "@vitaminvui/ui/v2";
import type { ProgressQuiz } from "@/lib/my/schemas";
import { routes } from "@/lib/routes";

/** Điểm cao nhất (thang 10) và số lượt của từng bài kiểm tra trong khóa (US-008 AC5). */
export function QuizScoreTable({ courseId, quizzes }: { courseId: number; quizzes: ProgressQuiz[] }) {
  const columns: Array<Column<ProgressQuiz>> = [
    {
      key: "title",
      header: "Bài kiểm tra",
      cell: (q) => (
        <span className="flex flex-col">
          <span className="font-semibold">{q.title}</span>
          <span className="num text-sm text-ink-soft md:hidden">
            {q.question_count} câu · {q.attempts_count} lượt
          </span>
        </span>
      ),
    },
    { key: "count", header: "Số câu", align: "right", hideBelow: "md", cell: (q) => q.question_count },
    { key: "attempts", header: "Lượt đã làm", align: "right", hideBelow: "md", cell: (q) => q.attempts_count },
    {
      key: "best",
      header: "Điểm cao nhất",
      align: "right",
      cell: (q) => (q.best_score === null ? <Badge size="sm">Chưa làm</Badge> : <span className="font-extrabold">{formatScore(q.best_score)}/10</span>),
    },
    {
      key: "act",
      header: <span className="sr-only">Thao tác</span>,
      align: "right",
      cell: (q) => (
        <span className="flex flex-wrap justify-end gap-1">
          {q.attempts_count > 0 ? (
            <ButtonLink href={routes.quizResult(courseId, q.id)} variant="ghost" aria-label={`Xem kết quả lượt gần nhất: ${q.title}`}>
              Xem kết quả lượt gần nhất
            </ButtonLink>
          ) : null}
          <ButtonLink href={routes.quiz(courseId, q.id)} variant="ghost" aria-label={`${q.attempted ? "Làm lại" : "Làm bài"}: ${q.title}`}>
            {q.attempted ? "Làm lại" : "Làm bài"}
          </ButtonLink>
        </span>
      ),
    },
  ];
  if (quizzes.length === 0) {
    return <EmptyState size="inline" icon={<IconListChecks size={24} />} title="Khóa học chưa có bài kiểm tra" headingLevel="h3" />;
  }
  return (
    <>
      {/* Từ md: bảng. Dưới md: thẻ — bảng nhiều cột + nút không vừa 375px (cuộn ngang trong bảng làm khuất nút hành động). */}
      <div className="hidden md:block">
        <DataTable caption="Điểm các bài kiểm tra của khóa" columns={columns} rows={quizzes} rowKey={(q) => q.id} />
      </div>
      <ul aria-label="Điểm các bài kiểm tra của khóa" className="flex flex-col gap-3 md:hidden">
        {quizzes.map((q) => (
          <li key={q.id} className="flex flex-col gap-2 rounded-card border border-line bg-surface p-4">
            <p className="text-base font-semibold text-ink">{q.title}</p>
            <p className="num text-sm text-ink-soft">
              {q.question_count} câu · {q.attempts_count} lượt đã làm
            </p>
            <p className="flex items-center gap-2 text-base text-ink">
              Điểm cao nhất:
              {q.best_score === null ? <Badge size="sm">Chưa làm</Badge> : <span className="num font-extrabold">{formatScore(q.best_score)}/10</span>}
            </p>
            <div className="flex flex-wrap gap-2 pt-1">
              <ButtonLink href={routes.quiz(courseId, q.id)} variant={q.attempted ? "secondary" : "primary"}>
                {q.attempted ? "Làm lại" : "Làm bài"}
              </ButtonLink>
              {q.attempts_count > 0 ? (
                <ButtonLink href={routes.quizResult(courseId, q.id)} variant="ghost" aria-label={`Xem kết quả lượt gần nhất: ${q.title}`}>
                  Xem kết quả lượt gần nhất
                </ButtonLink>
              ) : null}
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
