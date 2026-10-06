/**
 * Hồ sơ giáo viên công khai (US-020). Tên trường TẠM — Architect đang chốt api-contract
 * (`GET/PUT /admin/me/teacher-profile`, `GET/PUT /admin/teachers/{id}/profile`, PATCH bật/tắt + thứ tự).
 * `avatar_url` là đường dẫn giả: bản xem trước không có ảnh thật nên luôn hiện chữ cái đầu.
 */
export interface TeacherProfile {
  id: number;
  name: string;
  email: string;
  status: "active" | "locked";
  avatar_url: string | null;
  headline: string | null;
  bio: string | null;
  grade_levels: number[];
  published_courses_count: number;
  public_profile_consent_at: string | null;
  public_profile_consent_version: string | null;
  show_on_homepage: boolean;
  homepage_order: number | null;
  profile_updated_by: { id: number; name: string } | null;
  profile_updated_at: string | null;
}

/** Số giáo viên tối đa ở trang chủ (US-020 BR3) — bản thật đọc từ cấu hình, không hard-code. */
export const HOMEPAGE_TEACHER_LIMIT = 6;
export const CONSENT_TEXT = "Tôi đồng ý công khai ảnh, họ tên và phần giới thiệu của tôi trên website VitaminVui";
export const CONSENT_VERSION = "2026-10";

const IMG = "https://static.vitaminvui.vn/avatars/mau.webp";

export const TEACHER_PROFILES: TeacherProfile[] = [
  {
    id: 11, name: "Nguyễn Thu Hà", email: "ha.nguyen@vitaminvui.vn", status: "active", avatar_url: IMG,
    headline: "Giáo viên Toán THCS, chuyên hình học thi vào 10",
    bio: "12 năm dạy Toán THCS tại Hà Nội. Cô thích vẽ hình thật chậm, từng bước, để học sinh tự nhìn ra lời giải.\nPhụ trách các khóa hình học lớp 9 và lượng giác lớp 11.",
    grade_levels: [9, 11], published_courses_count: 3, public_profile_consent_at: "2026-10-05T14:20:00+07:00", public_profile_consent_version: CONSENT_VERSION,
    show_on_homepage: true, homepage_order: 1, profile_updated_by: { id: 2, name: "Đỗ Thị Mai" }, profile_updated_at: "2026-10-06T09:12:00+07:00",
  },
  {
    id: 12, name: "Trần Minh Đức", email: "duc.tran@vitaminvui.vn", status: "active", avatar_url: IMG, headline: "Giáo viên Toán THPT",
    bio: "Dạy Toán THPT, soạn nhiều chuyên đề ôn thi tốt nghiệp.", grade_levels: [9, 10, 12], published_courses_count: 4,
    public_profile_consent_at: "2026-10-04T10:00:00+07:00", public_profile_consent_version: CONSENT_VERSION, show_on_homepage: true, homepage_order: 2,
    profile_updated_by: null, profile_updated_at: "2026-10-04T10:00:00+07:00",
  },
  {
    id: 13, name: "Phạm Ngọc Lan", email: "lan.pham@vitaminvui.vn", status: "active", avatar_url: IMG, headline: "Giáo viên Toán THCS",
    bio: "Phụ trách các khóa nền tảng: số học lớp 6, căn thức lớp 9.", grade_levels: [6, 9], published_courses_count: 3,
    public_profile_consent_at: "2026-10-03T08:00:00+07:00", public_profile_consent_version: CONSENT_VERSION, show_on_homepage: true, homepage_order: 3,
    profile_updated_by: null, profile_updated_at: "2026-10-03T08:00:00+07:00",
  },
  {
    id: 15, name: "Vũ Hải Yến", email: "yen.vu@vitaminvui.vn", status: "active", avatar_url: IMG, headline: "Giáo viên Toán THPT chuyên",
    bio: "Dạy hàm số và lượng giác cho học sinh lớp 10, 11.", grade_levels: [10, 11], published_courses_count: 2,
    public_profile_consent_at: "2026-10-02T08:00:00+07:00", public_profile_consent_version: CONSENT_VERSION, show_on_homepage: true, homepage_order: 4,
    profile_updated_by: null, profile_updated_at: "2026-10-02T08:00:00+07:00",
  },
  {
    id: 17, name: "Đinh Thị Mai Anh", email: "maianh.dinh@vitaminvui.vn", status: "active", avatar_url: IMG, headline: "Giáo viên Toán THCS",
    bio: "Dạy hình học lớp 7, 8.", grade_levels: [7, 8], published_courses_count: 1,
    public_profile_consent_at: null, public_profile_consent_version: null, show_on_homepage: true, homepage_order: 5,
    profile_updated_by: { id: 1, name: "Lê Văn Hùng" }, profile_updated_at: "2026-10-05T17:00:00+07:00",
  },
  {
    id: 14, name: "Lê Đăng Khoa", email: "khoa.le@vitaminvui.vn", status: "active", avatar_url: IMG, headline: null,
    bio: "Dạy đại số lớp 7, hình học lớp 8 và xác suất – thống kê lớp 12.", grade_levels: [7, 8, 12], published_courses_count: 3,
    public_profile_consent_at: "2026-10-01T08:00:00+07:00", public_profile_consent_version: CONSENT_VERSION, show_on_homepage: false, homepage_order: null,
    profile_updated_by: null, profile_updated_at: "2026-10-01T08:00:00+07:00",
  },
  {
    id: 16, name: "Hoàng Quốc Bảo", email: "bao.hoang@vitaminvui.vn", status: "active", avatar_url: IMG, headline: "Giáo viên Toán THCS",
    bio: "Đồng hành cùng học sinh mới lên cấp 2: phân số, số thập phân, biểu thức đại số.", grade_levels: [6, 7], published_courses_count: 2,
    public_profile_consent_at: "2026-09-30T08:00:00+07:00", public_profile_consent_version: CONSENT_VERSION, show_on_homepage: false, homepage_order: null,
    profile_updated_by: null, profile_updated_at: "2026-09-30T08:00:00+07:00",
  },
  {
    id: 18, name: "Tạ Văn Nam", email: "nam.ta@vitaminvui.vn", status: "active", avatar_url: null, headline: null, bio: null,
    grade_levels: [], published_courses_count: 0, public_profile_consent_at: null, public_profile_consent_version: null,
    show_on_homepage: false, homepage_order: null, profile_updated_by: null, profile_updated_at: null,
  },
];

export interface Condition {
  key: "consent" | "avatar" | "bio" | "courses" | "active" | "enabled";
  label: string;
  ok: boolean;
}

/**
 * Điều kiện hiện ở trang chủ (US-020 BR2), dạng checklist có chữ. Bản thật nên lấy lý do từ API
 * (để không lệch logic backend); ở đây suy từ các trường.
 */
export function homepageConditions(p: TeacherProfile): Condition[] {
  return [
    { key: "consent", label: "Giáo viên đã đồng ý công khai", ok: p.public_profile_consent_at !== null },
    { key: "avatar", label: "Có ảnh đại diện", ok: p.avatar_url !== null },
    { key: "bio", label: "Có phần giới thiệu", ok: Boolean(p.bio?.trim()) },
    { key: "courses", label: "Có ít nhất 1 khóa đang bán", ok: p.published_courses_count > 0 },
    { key: "active", label: "Tài khoản đang hoạt động", ok: p.status === "active" },
    { key: "enabled", label: "Admin/Quản lý trang đã bật hiển thị", ok: p.show_on_homepage },
  ];
}

/** Lý do còn thiếu, viết thường để ghép sau "Chưa hiện: ". */
export function missingReasons(p: TeacherProfile, includeEnabled = true): string[] {
  const map: Record<Condition["key"], string> = {
    consent: "chưa đồng ý công khai",
    avatar: "thiếu ảnh",
    bio: "thiếu giới thiệu",
    courses: "chưa có khóa đang bán",
    active: "tài khoản bị khoá",
    enabled: "chưa được bật",
  };
  return homepageConditions(p)
    .filter((c) => !c.ok && (includeEnabled || c.key !== "enabled"))
    .map((c) => map[c.key]);
}
