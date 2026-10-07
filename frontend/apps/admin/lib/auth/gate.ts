/** Sự kiện cổng truy cập staff (khoá/MFA/đổi mật khẩu) phát từ mọi lệnh gọi API đã đăng nhập. */
export const STAFF_GATE_EVENT = "vv:staff-gate";
export type StaffGateCode = "ACCOUNT_LOCKED" | "MFA_REQUIRED" | "PASSWORD_CHANGE_REQUIRED";
export const GATE_CODES: readonly string[] = ["ACCOUNT_LOCKED", "MFA_REQUIRED", "PASSWORD_CHANGE_REQUIRED"];
