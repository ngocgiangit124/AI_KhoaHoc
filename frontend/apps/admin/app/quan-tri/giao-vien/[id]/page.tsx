import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { RequireRole } from "@/components/shell/RequireRole";
import { TeacherProfileScreen } from "@/components/teacher-profiles/TeacherProfileScreen";

export const metadata: Metadata = { title: "Hồ sơ giáo viên — VitaminVui Quản trị" };

/** Admin/QLT sửa hộ hồ sơ giáo viên (US-020 BR6, AC8): không đồng ý thay. */
export default async function AdminTeacherProfilePage({ params }: PageProps<"/quan-tri/giao-vien/[id]">) {
  const { id } = await params;
  if (!/^[1-9]\d{0,9}$/.test(id)) notFound();
  return (
    <RequireRole roles={["admin", "quan_ly_trang"]}>
      <TeacherProfileScreen key={id} target={{ kind: "user", id: Number(id) }} mode="admin" />
    </RequireRole>
  );
}
