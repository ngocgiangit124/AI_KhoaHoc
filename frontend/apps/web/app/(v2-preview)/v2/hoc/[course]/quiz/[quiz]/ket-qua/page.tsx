import Link from "next/link";
import { Badge, ButtonLink, IconChevronLeft, IconRotateCcw, LinkTabs, formatDateTime } from "@vitaminvui/ui/v2";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { ResultQuestion, ScoreRing } from "@/components/v2/quiz/ResultView";
import { attemptResult, quizMeta } from "@/lib/mock/v2/quiz";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

/** Kết quả quiz (US-007 §2.3): điểm, số câu đúng, xem lại từng câu kèm lời giải; lọc câu sai/bỏ trống. */
export default async function QuizResultPreview({ params, searchParams }: PageProps<"/v2/hoc/[course]/quiz/[quiz]/ket-qua">) {
  const { course, quiz } = await params;
  const sp = await searchParams;
  const courseId = Number(course);
  const auto = one(sp["tu-nop"]) === "1";
  const filter = one(sp.loc);
  const base = routes.quizResult(courseId, Number(quiz));
  const r = { ...attemptResult, auto_submitted: auto };
  const wrong = r.questions.filter((q) => q.selected_option_id !== null && !q.is_correct);
  const skipped = r.questions.filter((q) => q.selected_option_id === null);
  const shown = filter === "sai" ? wrong : filter === "bo-trong" ? skipped : r.questions;
  const qs = (loc?: string) => {
    const p = new URLSearchParams();
    if (auto) p.set("tu-nop", "1");
    if (loc) p.set("loc", loc);
    const s = p.toString();
    return s ? `${base}?${s}` : base;
  };

  return (
    <>
      <PreviewBar
        variants={[
          { label: "Nộp bình thường", href: base, current: !auto },
          { label: "Tự nộp khi hết giờ", href: `${base}?tu-nop=1`, current: auto },
        ]}
      />
      <header className="border-b border-line bg-surface">
        <div className="mx-auto flex h-14 max-w-3xl items-center px-2 sm:px-4">
          <Link href={routes.lesson(courseId, quizMeta.lesson_id)} className="focus-ring flex min-h-11 items-center gap-1 rounded-control pr-2 font-semibold text-ink hover:text-primary">
            <IconChevronLeft />
            Quay lại bài học
          </Link>
        </div>
      </header>
      <main id="noi-dung" className="mx-auto w-full max-w-3xl flex-1 px-4 pb-16 pt-6">
        <section aria-labelledby="ket-qua" className="flex flex-col items-center gap-6 rounded-sheet border border-line bg-surface p-6 text-center sm:flex-row sm:text-left">
          <ScoreRing score={r.score} />
          <div className="flex flex-1 flex-col gap-2">
            <p className="text-sm font-medium text-ink-soft">{quizMeta.title}</p>
            <h1 id="ket-qua" className="text-title font-extrabold tracking-heading text-ink">
              {r.score >= 8 ? "Làm tốt lắm!" : r.score >= 5 ? "Khá rồi, xem lại câu sai nhé" : "Cùng xem lại bài nhé"}
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
              <ButtonLink href={routes.lesson(courseId, 308)}>Học bài tiếp theo</ButtonLink>
              <ButtonLink href={routes.quiz(courseId, Number(quiz))} variant="secondary" leadingIcon={<IconRotateCcw size={16} />}>
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
            { href: qs(), label: "Tất cả", count: r.questions.length, current: !filter },
            { href: qs("sai"), label: "Câu sai", count: wrong.length, current: filter === "sai" },
            { href: qs("bo-trong"), label: "Bỏ trống", count: skipped.length, current: filter === "bo-trong" },
          ]}
        />
        <div className="mt-5 flex flex-col gap-4">
          {shown.map((q) => (
            <ResultQuestion key={q.id} q={q} />
          ))}
        </div>
      </main>
    </>
  );
}
