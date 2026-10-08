import type { Metadata } from "next";
import { MyCoursesScreen } from "@/components/my/MyCoursesScreen";
import { parsePage } from "@/lib/my/errors";

export const metadata: Metadata = { title: "Khóa học của tôi — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

export default async function MyCoursesPage({ searchParams }: PageProps<"/tai-khoan/khoa-hoc-cua-toi">) {
  const sp = await searchParams;
  return <MyCoursesScreen page={parsePage(sp.trang)} />;
}
