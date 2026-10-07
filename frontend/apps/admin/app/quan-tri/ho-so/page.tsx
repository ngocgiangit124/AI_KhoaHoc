import type { Metadata } from "next";
import { RequireRole } from "@/components/shell/RequireRole";
import { TeacherProfileScreen } from "@/components/teacher-profiles/TeacherProfileScreen";

export const metadata: Metadata = { title: "Hồ sơ của tôi — VitaminVui Quản trị" };

/** "Hồ sơ của tôi" (US-020 AC1): chỉ giáo viên; Admin/QLT gọi API này nhận 403 (BR12). */
export default function MyTeacherProfilePage() {
  return (
    <RequireRole roles={["giao_vien"]}>
      <TeacherProfileScreen target={{ kind: "me" }} mode="self" />
    </RequireRole>
  );
}
