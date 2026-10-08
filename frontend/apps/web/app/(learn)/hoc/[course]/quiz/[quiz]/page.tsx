import { notFound } from "next/navigation";
import { QuizScreen } from "@/components/quiz/QuizScreen";
import { parseId } from "@/lib/quiz/outline";

/** Id trong URL là số nguyên dương; sai định dạng → 404 thật (không gọi API). */
export default async function QuizPage({ params }: PageProps<"/hoc/[course]/quiz/[quiz]">) {
  const { course, quiz } = await params;
  const courseId = parseId(course);
  const quizId = parseId(quiz);
  if (courseId === null || quizId === null) notFound();
  return <QuizScreen key={`${courseId}-${quizId}`} courseId={courseId} quizId={quizId} />;
}
