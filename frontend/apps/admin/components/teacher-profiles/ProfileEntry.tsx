"use client";

import { useSession } from "@/lib/auth/SessionProvider";
import { LegacyProfileScreen } from "./LegacyProfileScreen";
import { TeacherProfileScreen } from "./TeacherProfileScreen";

/**
 * Giáo viên: "Hồ sơ của tôi" (US-020 AC1). Người không còn là giáo viên: "Hồ sơ giáo viên cũ" chỉ để rút đồng ý/xoá ảnh
 * (FA11-1); người chưa từng có hồ sơ nhận 403 từ API → ForbiddenView.
 */
export function ProfileEntry() {
  const { state } = useSession();
  if (state.kind !== "staff") return null;
  return state.user.role === "giao_vien" ? <TeacherProfileScreen target={{ kind: "me" }} mode="self" /> : <LegacyProfileScreen />;
}
