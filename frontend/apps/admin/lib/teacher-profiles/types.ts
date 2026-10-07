import type { PaginatedResponse } from "@vitaminvui/api-client";

export const HOMEPAGE_MAX_DEFAULT = 6;
export const HEADLINE_MAX = 120;
export const BIO_MAX = 600;
export const PER_PAGE_OPTIONS = [25, 50] as const;
export type PerPage = (typeof PER_PAGE_OPTIONS)[number];

/** Mã lý do chưa hiện trang chủ (api-contract §2.9), theo đúng thứ tự server trả. */
export type HomepageReason = "not_teacher" | "account_locked" | "not_enabled" | "no_consent" | "no_avatar" | "no_bio" | "no_published_course";

export interface TeacherProfile {
  user: { id: number; name: string; role: string; status: string };
  headline: string | null;
  bio: string | null;
  avatar_url: string | null;
  consent: {
    given: boolean;
    given_at: string | null;
    version: string | null;
    withdrawn_at: string | null;
    current_version: string;
    current_text: string;
  };
  show_on_homepage: boolean;
  homepage_order: number | null;
  homepage_status: { visible: boolean; reasons: HomepageReason[] };
  published_courses_count: number;
  last_edited_by: { id: number; name: string; is_self: boolean } | null;
  last_edited_at: string | null;
  updated_at: string | null;
  /** `consent` không có trong danh sách (`GET /admin/teacher-profiles`). */
  abilities: { edit_content: boolean; consent?: boolean; manage_homepage: boolean };
}

export type TeacherProfilePage = PaginatedResponse<TeacherProfile> & {
  meta: PaginatedResponse<TeacherProfile>["meta"] & { homepage: { enabled_count: number; max: number } };
};

export interface ProfileListQuery {
  q: string;
  /** `true` = chỉ người đang bật trang chủ (`homepage=1`). */
  onlyEnabled: boolean;
  page: number;
  perPage: PerPage;
}

/** Đích của các lệnh hồ sơ: chính mình (giáo viên) hoặc một giáo viên bất kỳ (Admin/QLT). */
export type ProfileTarget = { kind: "me" } | { kind: "user"; id: number };

export interface ContentPatch {
  headline?: string | null;
  bio?: string | null;
}
