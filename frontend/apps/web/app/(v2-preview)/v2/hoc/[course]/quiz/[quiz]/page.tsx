import { ButtonLink, EmptyState, IconListChecks } from "@vitaminvui/ui/v2";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { QuizRunner } from "@/components/v2/quiz/QuizRunner";
import { attemptInProgress, quizMeta } from "@/lib/mock/v2/quiz";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const STATES: Array<{ key?: string; label: string }> = [
  { label: "Đang làm (còn 11:40)" },
  { key: "sap-het-gio", label: "Còn dưới 1 phút" },
  { key: "het-gio", label: "Hết giờ → tự nộp" },
  { key: "khong-gio", label: "Không giới hạn giờ" },
  { key: "mat-mang", label: "Mất mạng" },
  { key: "chua-san-sang", label: "Quiz chưa có câu" },
];

/** Làm bài trắc nghiệm (US-007). Không có header site/bottom-nav: tập trung vào bài. */
export default async function QuizPreview({ params, searchParams }: PageProps<"/v2/hoc/[course]/quiz/[quiz]">) {
  const { course, quiz } = await params;
  const sp = await searchParams;
  const state = one(sp["trang-thai"]);
  const courseId = Number(course);
  const base = routes.quiz(courseId, Number(quiz));
  const exitHref = routes.lesson(courseId, quizMeta.lesson_id);
  const preview = <PreviewBar variants={STATES.map((s) => ({ label: s.label, href: s.key ? `${base}?trang-thai=${s.key}` : base, current: s.key === state }))} />;

  if (state === "chua-san-sang") {
    // 422 QUIZ_NOT_READY
    return (
      <>
        {preview}
        <main id="noi-dung" className="mx-auto flex w-full max-w-xl flex-1 items-center px-4">
          <EmptyState
            headingLevel="h1"
            icon={<IconListChecks size={32} />}
            title="Bài kiểm tra chưa sẵn sàng"
            description="Giáo viên đang chuẩn bị câu hỏi, bạn quay lại sau nhé."
            action={<ButtonLink href={exitHref}>Quay lại bài học</ButtonLink>}
          />
        </main>
      </>
    );
  }

  const remaining = state === "sap-het-gio" ? 65 : state === "het-gio" ? 4 : state === "khong-gio" ? null : attemptInProgress.remaining_seconds;
  return (
    <>
      {preview}
      <h1 className="sr-only">{quizMeta.title}</h1>
      <QuizRunner
        key={state ?? "mac-dinh"}
        attempt={{ ...attemptInProgress, remaining_seconds: remaining, expires_at: remaining === null ? null : attemptInProgress.expires_at }}
        title={quizMeta.title}
        exitHref={exitHref}
        resultHref={routes.quizResult(courseId, Number(quiz))}
        offline={state === "mat-mang"}
      />
    </>
  );
}
