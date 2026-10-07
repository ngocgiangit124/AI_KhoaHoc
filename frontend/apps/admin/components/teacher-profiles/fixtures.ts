import type { TeacherProfile } from "@/lib/teacher-profiles/types";

/** Dữ liệu mẫu đúng `TeacherProfile` của api-contract §2.9 (chỉ dùng trong test). */
export function profileFixture(over: Partial<TeacherProfile> & { id?: number; name?: string } = {}): TeacherProfile {
  const { id = 12, name = "Nguyễn Thị Lan", ...rest } = over;
  return {
    user: { id, name, role: "giao_vien", status: "active" },
    headline: "Giáo viên Toán THPT",
    bio: "10 năm luyện thi vào 10.\nHọc sinh đạt giải cấp tỉnh.",
    avatar_url: null,
    consent: {
      given: false,
      given_at: null,
      version: null,
      withdrawn_at: null,
      current_version: "2026-10",
      current_text: "Tôi đồng ý công khai ảnh, họ tên và phần giới thiệu của tôi trên website VitaminVui",
    },
    show_on_homepage: false,
    homepage_order: null,
    homepage_status: { visible: false, reasons: ["not_enabled", "no_consent", "no_avatar"] },
    published_courses_count: 2,
    last_edited_by: null,
    last_edited_at: null,
    updated_at: null,
    abilities: { edit_content: true, consent: true, manage_homepage: false },
    ...rest,
  };
}
