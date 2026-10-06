import type { StaffRole } from "./data";

/** GET /admin/subjects item (T06): `{id, name, slug, status, courses_count, created_at, updated_at}`. */
export interface AdminSubject {
  id: number;
  name: string;
  slug: string;
  status: "active" | "hidden";
  courses_count: number;
  created_at: string;
  updated_at: string;
}

export const ADMIN_SUBJECTS: AdminSubject[] = [
  { id: 1, name: "Số học", slug: "so-hoc", status: "active", courses_count: 2, created_at: "2026-08-01T09:00:00+07:00", updated_at: "2026-08-01T09:00:00+07:00" },
  { id: 2, name: "Đại số", slug: "dai-so", status: "active", courses_count: 4, created_at: "2026-08-01T09:00:00+07:00", updated_at: "2026-08-01T09:00:00+07:00" },
  { id: 3, name: "Hình học", slug: "hinh-hoc", status: "active", courses_count: 3, created_at: "2026-08-01T09:00:00+07:00", updated_at: "2026-08-01T09:00:00+07:00" },
  { id: 4, name: "Hàm số và giải tích", slug: "giai-tich", status: "active", courses_count: 2, created_at: "2026-08-01T09:00:00+07:00", updated_at: "2026-08-01T09:00:00+07:00" },
  { id: 5, name: "Xác suất – thống kê", slug: "xac-suat-thong-ke", status: "active", courses_count: 1, created_at: "2026-08-01T09:00:00+07:00", updated_at: "2026-08-01T09:00:00+07:00" },
  { id: 6, name: "Ôn thi vào 10", slug: "on-thi-vao-10", status: "active", courses_count: 1, created_at: "2026-08-01T09:00:00+07:00", updated_at: "2026-08-01T09:00:00+07:00" },
  { id: 7, name: "Ôn thi THPT", slug: "on-thi-thpt", status: "active", courses_count: 2, created_at: "2026-08-01T09:00:00+07:00", updated_at: "2026-08-01T09:00:00+07:00" },
  { id: 8, name: "Toán thực tế", slug: "toan-thuc-te", status: "hidden", courses_count: 0, created_at: "2026-09-12T09:00:00+07:00", updated_at: "2026-09-20T09:00:00+07:00" },
];

/** GET /admin/enrollment-requests item (T14). Email/SĐT đã che. */
export interface EnrollmentRequest {
  id: number;
  status: "pending_approval" | "active" | "rejected";
  requested_at: string;
  approved_at: string | null;
  rejection_reason: string | null;
  course: { id: number; title: string; slug: string };
  student: { id: number; name: string; grade_level: number; email_masked: string | null; phone_masked: string | null };
}

const C104 = { id: 104, title: "Căn bậc hai, căn bậc ba: học chắc nền tảng", slug: "can-bac-hai-can-bac-ba" };
const C105 = { id: 105, title: "Số học 6: Số tự nhiên và phép chia hết", slug: "so-hoc-6-chia-het" };
const C113 = { id: 113, title: "Bồi dưỡng học sinh giỏi Toán 9", slug: "boi-duong-hsg-toan-9" };

export const ENROLLMENT_REQUESTS: EnrollmentRequest[] = [
  { id: 901, status: "pending_approval", requested_at: "2026-10-04T19:12:00+07:00", approved_at: null, rejection_reason: null, course: C113, student: { id: 501, name: "Phạm Gia Huy", grade_level: 9, email_masked: "h***@gmail.com", phone_masked: "******3412" } },
  { id: 902, status: "pending_approval", requested_at: "2026-10-05T08:40:00+07:00", approved_at: null, rejection_reason: null, course: C104, student: { id: 502, name: "Nguyễn Khánh Linh", grade_level: 9, email_masked: "k***@yahoo.com", phone_masked: null } },
  { id: 903, status: "pending_approval", requested_at: "2026-10-05T16:20:00+07:00", approved_at: null, rejection_reason: null, course: C105, student: { id: 503, name: "Minh Anh", grade_level: 9, email_masked: "m***@gmail.com", phone_masked: "******5678" } },
  { id: 904, status: "pending_approval", requested_at: "2026-10-06T07:05:00+07:00", approved_at: null, rejection_reason: null, course: C105, student: { id: 504, name: "Trần Bảo Ngọc", grade_level: 6, email_masked: null, phone_masked: "******9021" } },
  { id: 880, status: "active", requested_at: "2026-10-01T10:00:00+07:00", approved_at: "2026-10-01T15:30:00+07:00", rejection_reason: null, course: C104, student: { id: 505, name: "Lê Quang Vinh", grade_level: 9, email_masked: "v***@gmail.com", phone_masked: "******1180" } },
  { id: 870, status: "rejected", requested_at: "2026-09-28T10:00:00+07:00", approved_at: null, rejection_reason: "Khóa dành cho học sinh lớp chọn của trường, em đăng ký khóa nền tảng trước nhé.", course: C113, student: { id: 503, name: "Minh Anh", grade_level: 9, email_masked: "m***@gmail.com", phone_masked: "******5678" } },
];
/** Khóa giáo viên mẫu (Nguyễn Thu Hà) phụ trách trong danh sách trên. */
export const TEACHER_FREE_COURSE_IDS = [113];

/** Coupon (T15). `state` do server suy ra. */
export type CouponState = "active" | "inactive" | "expired" | "exhausted" | "upcoming";
export interface Coupon {
  id: number;
  code: string;
  name: string | null;
  discount_type: "percent" | "fixed_amount";
  discount_value: number;
  max_uses: number | null;
  max_uses_per_user: 1;
  used_count: number;
  valid_from: string;
  valid_until: string | null;
  status: "active" | "inactive";
  state: CouponState;
  is_restricted: boolean;
  courses_count: number;
  subjects_count: number;
  courses?: Array<{ id: number; title: string }>;
  subjects?: Array<{ id: number; name: string }>;
  created_at: string;
}

export const COUPON_STATE_LABEL: Record<CouponState, string> = {
  active: "Đang hoạt động",
  upcoming: "Sắp diễn ra",
  expired: "Hết hạn",
  exhausted: "Hết lượt",
  inactive: "Đã tắt",
};

export const COUPONS: Coupon[] = [
  { id: 31, code: "KHAIGIANG2026", name: "Khai giảng năm học mới", discount_type: "percent", discount_value: 20, max_uses: 500, max_uses_per_user: 1, used_count: 0, valid_from: "2026-09-01T00:00:00+07:00", valid_until: "2026-10-31T23:59:00+07:00", status: "active", state: "active", is_restricted: false, courses_count: 0, subjects_count: 0, created_at: "2026-08-25T10:00:00+07:00" },
  { id: 32, code: "ONTHIVAO10", name: "Ôn thi vào 10", discount_type: "fixed_amount", discount_value: 100000, max_uses: 200, max_uses_per_user: 1, used_count: 45, valid_from: "2026-09-15T00:00:00+07:00", valid_until: "2026-12-31T23:59:00+07:00", status: "active", state: "active", is_restricted: true, courses_count: 0, subjects_count: 1, subjects: [{ id: 6, name: "Ôn thi vào 10" }], created_at: "2026-09-10T10:00:00+07:00" },
  { id: 33, code: "TET2027", name: "Tết Đinh Mùi", discount_type: "percent", discount_value: 15, max_uses: null, max_uses_per_user: 1, used_count: 0, valid_from: "2027-01-20T00:00:00+07:00", valid_until: "2027-02-10T23:59:00+07:00", status: "active", state: "upcoming", is_restricted: false, courses_count: 0, subjects_count: 0, created_at: "2026-10-02T10:00:00+07:00" },
  { id: 34, code: "HE2026", name: "Học hè 2026", discount_type: "percent", discount_value: 30, max_uses: 300, max_uses_per_user: 1, used_count: 212, valid_from: "2026-06-01T00:00:00+07:00", valid_until: "2026-08-31T23:59:00+07:00", status: "active", state: "expired", is_restricted: false, courses_count: 0, subjects_count: 0, created_at: "2026-05-20T10:00:00+07:00" },
  { id: 35, code: "HINHHOC9", name: null, discount_type: "fixed_amount", discount_value: 50000, max_uses: 50, max_uses_per_user: 1, used_count: 50, valid_from: "2026-09-01T00:00:00+07:00", valid_until: "2026-11-30T23:59:00+07:00", status: "active", state: "exhausted", is_restricted: true, courses_count: 1, subjects_count: 0, courses: [{ id: 101, title: "Hình học 9: Đường tròn từ cơ bản đến nâng cao" }], created_at: "2026-08-28T10:00:00+07:00" },
  { id: 36, code: "THUNGHIEM", name: "Mã thử nội bộ", discount_type: "percent", discount_value: 100, max_uses: 5, max_uses_per_user: 1, used_count: 2, valid_from: "2026-09-01T00:00:00+07:00", valid_until: "2026-09-30T23:59:00+07:00", status: "inactive", state: "inactive", is_restricted: false, courses_count: 0, subjects_count: 0, created_at: "2026-08-30T10:00:00+07:00" },
];

/** StaffAccount (T33). */
export interface StaffAccount {
  id: number;
  name: string;
  email: string;
  role: StaffRole;
  status: "active" | "locked";
  must_change_password: boolean;
  last_login_at: string | null;
  password_changed_at: string | null;
  created_at: string;
  is_self: boolean;
}

export const STAFF_ACCOUNTS: StaffAccount[] = [
  { id: 1, name: "Lê Văn Hùng", email: "hung.le@vitaminvui.vn", role: "admin", status: "active", must_change_password: false, last_login_at: "2026-10-06T08:05:00+07:00", password_changed_at: "2026-09-26T09:00:00+07:00", created_at: "2026-09-25T09:00:00+07:00", is_self: true },
  { id: 2, name: "Đỗ Thị Mai", email: "mai.do@vitaminvui.vn", role: "quan_ly_trang", status: "active", must_change_password: false, last_login_at: "2026-10-06T09:10:00+07:00", password_changed_at: "2026-09-27T09:00:00+07:00", created_at: "2026-09-26T09:00:00+07:00", is_self: false },
  { id: 11, name: "Nguyễn Thu Hà", email: "ha.nguyen@vitaminvui.vn", role: "giao_vien", status: "active", must_change_password: false, last_login_at: "2026-10-05T21:30:00+07:00", password_changed_at: "2026-09-28T09:00:00+07:00", created_at: "2026-09-27T09:00:00+07:00", is_self: false },
  { id: 12, name: "Trần Minh Đức", email: "duc.tran@vitaminvui.vn", role: "giao_vien", status: "active", must_change_password: false, last_login_at: "2026-10-04T20:00:00+07:00", password_changed_at: "2026-09-28T09:00:00+07:00", created_at: "2026-09-27T09:00:00+07:00", is_self: false },
  { id: 14, name: "Lê Đăng Khoa", email: "khoa.le@vitaminvui.vn", role: "giao_vien", status: "locked", must_change_password: false, last_login_at: "2026-09-30T19:00:00+07:00", password_changed_at: "2026-09-28T09:00:00+07:00", created_at: "2026-09-27T09:00:00+07:00", is_self: false },
  { id: 18, name: "Tạ Văn Nam", email: "nam.ta@vitaminvui.vn", role: "giao_vien", status: "active", must_change_password: true, last_login_at: null, password_changed_at: null, created_at: "2026-10-06T07:30:00+07:00", is_self: false },
];

/** Khóa mà giáo viên đang phụ trách — dùng giả lập `released_course_ids` khi đổi vai trò (T33-4). */
export const TEACHER_COURSES: Record<number, Array<{ id: number; title: string }>> = {
  11: [
    { id: 101, title: "Hình học 9: Đường tròn từ cơ bản đến nâng cao" },
    { id: 110, title: "Lượng giác 11: công thức và phương trình" },
  ],
  12: [
    { id: 102, title: "Phương trình bậc hai và hệ thức Vi-ét" },
    { id: 109, title: "Hàm số bậc nhất và bậc hai lớp 10" },
    { id: 111, title: "Đạo hàm và ứng dụng — Toán 12" },
  ],
  14: [{ id: 107, title: "Đại số 7: Biểu thức đại số và đa thức" }],
};

/** GET /admin/audit-logs item (T33). simplePaginate: chỉ có trang trước/sau. */
export interface AuditLog {
  id: number;
  action: string;
  actor_id: number | null;
  actor_role: StaffRole | "cli";
  actor_name: string | null;
  subject_type: string | null;
  subject_id: number | null;
  changes: Record<string, unknown> | null;
  ip: string | null;
  user_agent: string | null;
  created_at: string;
}

export const ACTION_LABEL: Record<string, string> = {
  "staff.login": "Đăng nhập quản trị",
  "staff.login_failed": "Đăng nhập thất bại",
  "staff.create": "Tạo tài khoản staff",
  "staff.role_change": "Đổi vai trò",
  "staff.password_reset": "Đặt lại mật khẩu",
  "user.lock": "Khoá tài khoản",
  "user.unlock": "Mở khoá tài khoản",
  "course.publish": "Xuất bản khóa học",
  "course.price_change": "Đổi giá khóa học",
  "course.update": "Sửa khóa học",
  "enrollment.approve": "Duyệt đăng ký",
  "enrollment.reject": "Từ chối đăng ký",
  "coupon.create": "Tạo mã giảm giá",
  "coupon.deactivate": "Tắt mã giảm giá",
  "subject.status": "Ẩn/hiện chuyên đề",
  "teacher_profile.homepage_toggle": "Bật/tắt giáo viên trang chủ",
};

const UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/141.0";
export const AUDIT_LOGS: AuditLog[] = [
  { id: 5012, action: "teacher_profile.homepage_toggle", actor_id: 2, actor_role: "quan_ly_trang", actor_name: "Đỗ Thị Mai", subject_type: "App\\Models\\User", subject_id: 17, changes: { show_on_homepage: { from: false, to: true } }, ip: "113.161.24.18", user_agent: UA, created_at: "2026-10-06T09:14:00+07:00" },
  { id: 5011, action: "staff.login", actor_id: 2, actor_role: "quan_ly_trang", actor_name: "Đỗ Thị Mai", subject_type: "App\\Models\\User", subject_id: 2, changes: { mfa: true }, ip: "113.161.24.18", user_agent: UA, created_at: "2026-10-06T09:10:00+07:00" },
  { id: 5010, action: "staff.create", actor_id: 1, actor_role: "admin", actor_name: "Lê Văn Hùng", subject_type: "App\\Models\\User", subject_id: 18, changes: { role: "giao_vien" }, ip: "14.232.8.77", user_agent: UA, created_at: "2026-10-06T07:30:00+07:00" },
  { id: 5009, action: "enrollment.approve", actor_id: 11, actor_role: "giao_vien", actor_name: "Nguyễn Thu Hà", subject_type: "App\\Models\\Enrollment", subject_id: 880, changes: null, ip: "27.72.101.5", user_agent: UA, created_at: "2026-10-05T15:30:00+07:00" },
  { id: 5008, action: "course.price_change", actor_id: 1, actor_role: "admin", actor_name: "Lê Văn Hùng", subject_type: "App\\Models\\Course", subject_id: 103, changes: { price: { from: 549000, to: 599000 } }, ip: "14.232.8.77", user_agent: UA, created_at: "2026-10-05T11:02:00+07:00" },
  { id: 5007, action: "user.lock", actor_id: 1, actor_role: "admin", actor_name: "Lê Văn Hùng", subject_type: "App\\Models\\User", subject_id: 14, changes: { status: { from: "active", to: "locked" } }, ip: "14.232.8.77", user_agent: UA, created_at: "2026-10-04T17:45:00+07:00" },
  { id: 5006, action: "staff.login_failed", actor_id: null, actor_role: "cli", actor_name: null, subject_type: null, subject_id: null, changes: { reason: "bad_credentials" }, ip: "103.9.76.201", user_agent: UA, created_at: "2026-10-04T03:12:00+07:00" },
  { id: 5005, action: "coupon.create", actor_id: 2, actor_role: "quan_ly_trang", actor_name: "Đỗ Thị Mai", subject_type: "App\\Models\\Coupon", subject_id: 33, changes: { high_risk: false }, ip: "113.161.24.18", user_agent: UA, created_at: "2026-10-02T10:00:00+07:00" },
  { id: 5004, action: "course.publish", actor_id: 1, actor_role: "admin", actor_name: "Lê Văn Hùng", subject_type: "App\\Models\\Course", subject_id: 108, changes: null, ip: "14.232.8.77", user_agent: UA, created_at: "2026-10-02T08:00:00+07:00" },
  { id: 5003, action: "subject.status", actor_id: 2, actor_role: "quan_ly_trang", actor_name: "Đỗ Thị Mai", subject_type: "App\\Models\\Subject", subject_id: 8, changes: { status: { from: "active", to: "hidden" } }, ip: "113.161.24.18", user_agent: UA, created_at: "2026-09-20T09:00:00+07:00" },
];
