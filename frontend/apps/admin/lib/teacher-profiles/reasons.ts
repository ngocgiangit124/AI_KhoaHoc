import type { HomepageReason, TeacherProfile } from "./types";

/** Lý do chưa hiện, dạng ngắn dùng sau "Chưa hiện: ". */
export const REASON_SHORT: Record<HomepageReason, string> = {
  not_teacher: "không còn là giáo viên",
  account_locked: "tài khoản bị khoá",
  not_enabled: "chưa bật hiển thị",
  no_consent: "chưa đồng ý công khai",
  no_avatar: "chưa có ảnh",
  no_bio: "chưa có phần giới thiệu",
  no_published_course: "chưa có khóa đang bán",
};

export function reasonText(reason: string): string {
  return REASON_SHORT[reason as HomepageReason] ?? reason;
}

/** "Chưa hiện: chưa đồng ý công khai, chưa có ảnh". Rỗng khi không có lý do. */
export function notShownLabel(reasons: readonly string[]): string {
  return reasons.length > 0 ? `Chưa hiện: ${reasons.map(reasonText).join(", ")}` : "";
}

/** Mục kiểm của giáo viên ở "Hồ sơ của tôi" (AC1): đạt khi lý do tương ứng không có trong `reasons`. */
export const CHECKLIST: ReadonlyArray<{ reason: HomepageReason; label: string }> = [
  { reason: "no_consent", label: "Bạn đã đồng ý công khai" },
  { reason: "no_avatar", label: "Có ảnh đại diện" },
  { reason: "no_bio", label: "Có phần giới thiệu" },
  { reason: "no_published_course", label: "Có ít nhất 1 khóa đang bán" },
  { reason: "not_enabled", label: "Admin/Quản lý trang đã bật hiển thị" },
  { reason: "account_locked", label: "Tài khoản đang hoạt động" },
];

export function checklistFor(reasons: readonly string[]): Array<{ key: HomepageReason; label: string; ok: boolean }> {
  return CHECKLIST.map((c) => ({ key: c.reason, label: c.label, ok: !reasons.includes(c.reason) }));
}

export const isTeacherRole = (p: Pick<TeacherProfile, "user">): boolean => p.user.role === "giao_vien";
