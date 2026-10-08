import type { Metadata } from "next";
import { ProfileEntry } from "@/components/teacher-profiles/ProfileEntry";

export const metadata: Metadata = { title: "Hồ sơ — VitaminVui Quản trị" };

export default function MyTeacherProfilePage() {
  return <ProfileEntry />;
}
