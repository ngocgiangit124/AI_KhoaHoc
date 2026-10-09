import type { AuditLog } from "./schemas";

const timeVn = new Intl.DateTimeFormat("vi-VN", { timeZone: "Asia/Ho_Chi_Minh", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false });
const dateVn = new Intl.DateTimeFormat("vi-VN", { timeZone: "Asia/Ho_Chi_Minh", day: "2-digit", month: "2-digit", year: "numeric" });

/** ISO → "09/10/2026 15:41:07" (giờ Việt Nam, cố định múi giờ để server và trình duyệt ra cùng kết quả). */
export function formatAuditTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return `${dateVn.format(d)} ${timeVn.format(d)}`;
}

/** Nhãn tiếng Việt cho action phổ biến (lấy từ `AuditLogger->log(...)` ở backend). Action lạ hiện nguyên mã. */
export const ACTION_LABELS: Readonly<Record<string, string>> = {
  "order.manual_approve": "Duyệt đơn",
  "order.manual_cancel": "Huỷ đơn",
  "order.refund": "Hoàn tiền",
  "order.note_add": "Thêm ghi chú đơn",
  "order.view_pii": "Xem thông tin cá nhân đơn",
  "order.search_contact": "Tra cứu email/SĐT",
  "staff.create": "Tạo tài khoản staff",
  "staff.role_change": "Đổi vai trò staff",
  "staff.password_reset": "Đặt lại mật khẩu staff",
  "staff.login": "Staff đăng nhập",
  "staff.login_failed": "Staff đăng nhập sai",
  "staff.login_mfa_sent": "Gửi mã MFA",
  "staff.mfa_failed": "Nhập sai mã MFA",
  "staff.logout": "Staff đăng xuất",
  "staff.password_changed": "Staff đổi mật khẩu",
  "staff.password_change_failed": "Đổi mật khẩu thất bại",
  "user.lock": "Khoá tài khoản",
  "user.unlock": "Mở khoá tài khoản",
  "enrollment.request": "Xin đăng ký khóa",
  "enrollment.approve": "Duyệt đăng ký",
  "enrollment.reject": "Từ chối đăng ký",
  "enrollment.grant": "Cấp quyền học",
  "enrollment.revoke": "Thu hồi quyền học",
  "coupon.create": "Tạo mã giảm giá",
  "coupon.update": "Sửa mã giảm giá",
  "coupon.delete": "Xoá mã giảm giá",
  "course.create": "Tạo khóa học",
  "course.update": "Sửa khóa học",
  "course.delete": "Xoá khóa học",
  "course.publish": "Xuất bản khóa học",
  "course.unpublish": "Ngừng xuất bản khóa học",
  "course.price_change": "Đổi giá khóa học",
  "course.teachers": "Gán giáo viên cho khóa",
  "course.manual_order": "Đơn thủ công của khóa",
  "chapter.create": "Tạo chương",
  "chapter.update": "Sửa chương",
  "chapter.delete": "Xoá chương",
  "lesson.create": "Tạo bài học",
  "lesson.update": "Sửa bài học",
  "lesson.delete": "Xoá bài học",
  "lesson.video_upload": "Tải video bài học",
  "curriculum.reorder": "Sắp xếp lại chương/bài",
  "quiz.create": "Tạo bài tập",
  "quiz.update": "Sửa bài tập",
  "quiz.delete": "Xoá bài tập",
  "quiz_question.create": "Tạo câu hỏi",
  "quiz_question.update": "Sửa câu hỏi",
  "quiz_question.delete": "Xoá câu hỏi",
  "quiz_question.reorder": "Sắp xếp lại câu hỏi",
  "subject.create": "Tạo chuyên đề",
  "subject.update": "Sửa chuyên đề",
  "subject.status": "Đổi trạng thái chuyên đề",
  "subject.delete": "Xoá chuyên đề",
  "teacher_profile.update": "Sửa hồ sơ giáo viên",
  "teacher_profile.consent": "Ghi nhận đồng ý hồ sơ giáo viên",
  "teacher_profile.consent_withdraw": "Rút đồng ý hồ sơ giáo viên",
  "teacher_profile.erase": "Xoá hồ sơ giáo viên",
  "teacher_profile.homepage_order": "Sắp xếp giáo viên trang chủ",
  "teacher_profile.homepage_toggle": "Bật/tắt giáo viên trang chủ",
  "account.verified": "Xác thực tài khoản",
  "account.contact_changed": "Đổi liên hệ tài khoản",
  "account.password_changed": "Đổi mật khẩu tài khoản",
  "account.password_reset": "Đặt lại mật khẩu tài khoản",
  "parent_contact.update": "Sửa liên hệ phụ huynh",
  "privacy.account_anonymized": "Ẩn danh tài khoản",
  "privacy.account_delete_otp_sent": "Gửi mã xoá tài khoản",
  "privacy.policy_accepted": "Chấp nhận chính sách",
  "privacy.data_export": "Xuất dữ liệu cá nhân",
  "otp.send_limit_reached": "Chạm giới hạn gửi OTP",
  "video.provider_migrated": "Chuyển nhà cung cấp video",
  "video.provider_rolled_back": "Hoàn tác chuyển video",
  "video.provider_source_deleted": "Xoá nguồn video cũ",
};

/** Các action đưa vào ô chọn lọc (theo thứ tự nhóm trong ACTION_LABELS). */
export const KNOWN_ACTIONS: readonly string[] = Object.keys(ACTION_LABELS);

export const actionLabel = (action: string): string | null => ACTION_LABELS[action] ?? null;

export const CLI_LABEL = "Lệnh hệ thống";
const ROLE_LABELS: Record<string, string> = { admin: "Admin", quan_ly_trang: "Quản lý trang", giao_vien: "Giáo viên", hoc_sinh: "Học sinh" };

/** Vai trò của người làm: `cli` = lệnh server; null = hệ thống/không xác định. */
export function actorRoleLabel(role: string | null): string | null {
  if (!role) return null;
  if (role === "cli") return CLI_LABEL;
  return ROLE_LABELS[role] ?? role;
}

export interface ActorView {
  name: string;
  role: string | null;
}
export function actorView(log: Pick<AuditLog, "actor_id" | "actor_role" | "actor_name">): ActorView {
  if (log.actor_role === "cli") return { name: CLI_LABEL, role: null };
  if (log.actor_name) return { name: log.actor_name, role: actorRoleLabel(log.actor_role) };
  if (log.actor_id !== null) return { name: `Tài khoản #${log.actor_id}`, role: actorRoleLabel(log.actor_role) };
  return { name: "Hệ thống / không xác định", role: actorRoleLabel(log.actor_role) };
}

/** Loại đối tượng: tên lớp Eloquent → nhãn tiếng Việt. Dùng cho ô lọc và cột Đối tượng. */
export const SUBJECT_TYPES: ReadonlyArray<{ value: string; label: string }> = [
  { value: "App\\Models\\Order", label: "Đơn hàng" },
  { value: "App\\Models\\Course", label: "Khóa học" },
  { value: "App\\Models\\Coupon", label: "Mã giảm giá" },
  { value: "App\\Models\\Subject", label: "Chuyên đề" },
  { value: "App\\Models\\User", label: "Tài khoản" },
  { value: "App\\Models\\Enrollment", label: "Đăng ký học" },
  { value: "App\\Models\\Chapter", label: "Chương" },
  { value: "App\\Models\\Lesson", label: "Bài học" },
  { value: "App\\Models\\Quiz", label: "Bài tập" },
  { value: "App\\Models\\QuizQuestion", label: "Câu hỏi" },
  { value: "App\\Models\\TeacherProfile", label: "Hồ sơ giáo viên" },
  { value: "App\\Models\\VideoAsset", label: "Video" },
];

const shortClass = (t: string) => t.split("\\").pop() ?? t;

export function subjectTypeLabel(type: string): string {
  return SUBJECT_TYPES.find((s) => s.value === type)?.label ?? shortClass(type);
}

export interface SubjectView {
  text: string;
  /** Trang chi tiết trong admin, nếu có và biết đủ thông tin. */
  href: string | null;
}

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);

/** Mã đơn hợp lệ để đưa vào đường dẫn (chữ/số/gạch). Giá trị lạ không tạo link. */
const SAFE_CODE = /^[A-Za-z0-9_-]{3,40}$/;

export function subjectView(log: Pick<AuditLog, "subject_type" | "subject_id" | "changes">): SubjectView {
  if (!log.subject_type) return { text: "—", href: null };
  const label = subjectTypeLabel(log.subject_type);
  const id = log.subject_id;
  const text = id === null ? label : `${label} #${id}`;
  const kind = shortClass(log.subject_type);
  let href: string | null = null;
  if (id !== null) {
    if (kind === "Course") href = `/quan-tri/khoa-hoc/${id}/sua`;
    else if (kind === "Coupon") href = `/quan-tri/ma-giam-gia/${id}`;
    else if (kind === "Order" && isRecord(log.changes)) {
      const code = log.changes["code"] ?? log.changes["order_code"];
      if (typeof code === "string" && SAFE_CODE.test(code)) href = `/quan-tri/don-hang/${encodeURIComponent(code)}`;
    }
  }
  return { text, href };
}

export const VALUE_PREVIEW_MAX = 160;

/** Giá trị → chuỗi hiển thị (text thuần). Object/mảng lồng nhau thành JSON gọn. */
export function stringifyValue(v: unknown): string {
  if (v === null || v === undefined) return "—";
  if (typeof v === "string") return v === "" ? "(trống)" : v;
  if (typeof v === "boolean") return v ? "có" : "không";
  if (typeof v === "number" || typeof v === "bigint") return String(v);
  try {
    return JSON.stringify(v) ?? "—";
  } catch {
    return "(không hiển thị được)";
  }
}

export interface ChangeEntry {
  key: string;
  value: string;
}

/** `changes` → danh sách khoá–giá trị (mảng thì khoá là chỉ số). Rỗng/không có → []. */
export function changeEntries(changes: AuditLog["changes"]): ChangeEntry[] {
  if (changes === null || changes === undefined) return [];
  const entries: Array<[string, unknown]> = Array.isArray(changes) ? changes.map((v, i) => [String(i), v]) : Object.entries(changes);
  return entries.map(([key, v]) => ({ key, value: stringifyValue(v) }));
}

export function truncate(text: string, max: number = VALUE_PREVIEW_MAX): { text: string; truncated: boolean } {
  return text.length > max ? { text: `${text.slice(0, max)}…`, truncated: true } : { text, truncated: false };
}

/** User agent → "Chrome 141 · macOS" (rút gọn); không nhận ra thì cắt 60 ký tự. */
export function shortUserAgent(ua: string | null): string {
  if (!ua) return "—";
  const BROWSERS: Array<[RegExp, string]> = [
    [/Edg\/(\d+)/, "Edge"],
    [/OPR\/(\d+)/, "Opera"],
    [/Firefox\/(\d+)/, "Firefox"],
    [/Chrome\/(\d+)/, "Chrome"],
    [/Version\/(\d+).*Safari/, "Safari"],
  ];
  let browser: string | null = null;
  for (const [re, name] of BROWSERS) {
    const m = re.exec(ua);
    if (m) {
      browser = `${name} ${m[1]}`;
      break;
    }
  }
  const os = /Windows/.test(ua) ? "Windows" : /Android/.test(ua) ? "Android" : /iPhone|iPad|iOS/.test(ua) ? "iOS" : /Mac OS X|Macintosh/.test(ua) ? "macOS" : /Linux/.test(ua) ? "Linux" : null;
  if (browser) return os ? `${browser} · ${os}` : browser;
  return ua.length > 60 ? `${ua.slice(0, 60)}…` : ua;
}
