import { notFound } from "next/navigation";
import { QuizResultScreen } from "@/components/quiz/QuizResultScreen";
import { parseAttemptId, parseId, parseResultFilter } from "@/lib/quiz/outline";

export default async function QuizResultPage({ params, searchParams }: PageProps<"/hoc/[course]/quiz/[quiz]/ket-qua">) {
  const { course, quiz } = await params;
  const sp = await searchParams;
  const courseId = parseId(course);
  const quizId = parseId(quiz);
  if (courseId === null || quizId === null) notFound();
  const attemptId = parseAttemptId(sp.lan);
  const filter = parseResultFilter(sp.loc);
  return <QuizResultScreen key={`${courseId}-${quizId}-${attemptId ?? "moi-nhat"}`} courseId={courseId} quizId={quizId} attemptId={attemptId} filter={filter} />;
}
