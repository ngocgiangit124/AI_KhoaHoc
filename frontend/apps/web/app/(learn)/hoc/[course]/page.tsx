import { notFound } from "next/navigation";
import { ResumeRedirect } from "@/components/learn/ResumeRedirect";

export default async function LearnCoursePage({ params }: PageProps<"/hoc/[course]">) {
  const { course } = await params;
  if (!/^[1-9]\d{0,9}$/.test(course)) notFound();
  return <ResumeRedirect key={course} courseId={Number(course)} />;
}
