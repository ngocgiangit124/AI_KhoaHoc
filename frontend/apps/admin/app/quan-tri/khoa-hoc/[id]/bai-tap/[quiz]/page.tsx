import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { QuizComposerScreen } from "@/components/quiz/QuizComposerScreen";

export const metadata: Metadata = { title: "Soạn bài tập — VitaminVui Quản trị" };

export default async function QuizComposerPage({ params }: PageProps<"/quan-tri/khoa-hoc/[id]/bai-tap/[quiz]">) {
  const { id, quiz } = await params;
  if (!/^[1-9]\d{0,9}$/.test(id) || !/^[1-9]\d{0,9}$/.test(quiz)) notFound();
  return <QuizComposerScreen courseId={Number(id)} quizId={Number(quiz)} />;
}
