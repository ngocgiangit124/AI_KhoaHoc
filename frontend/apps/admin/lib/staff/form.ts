import { STAFF_ROLES, type StaffRole } from "@/lib/auth/types";
import type { CreateErrors } from "./errors";

export const NAME_MAX_LENGTH = 100;
export const EMAIL_MAX_LENGTH = 254;
export const INITIAL_PASSWORD_HINT_MIN = 12;

export interface CreateValues {
  name: string;
  email: string;
  role: StaffRole | "";
}
export const EMPTY_CREATE: CreateValues = { name: "", email: "", role: "" };

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Kiểm sơ bộ (server vẫn kiểm lại). Không có lựa chọn `hoc_sinh`. */
export function validateCreate(v: CreateValues): CreateErrors {
  const errors: CreateErrors = {};
  const name = v.name.trim();
  if (!name) errors.name = "Vui lòng nhập họ và tên.";
  else if (name.length > NAME_MAX_LENGTH) errors.name = `Họ và tên tối đa ${NAME_MAX_LENGTH} ký tự.`;
  const email = v.email.trim();
  if (!email) errors.email = "Vui lòng nhập email.";
  else if (email.length > EMAIL_MAX_LENGTH || !EMAIL_RE.test(email)) errors.email = "Email không hợp lệ.";
  if (!v.role || !(STAFF_ROLES as readonly string[]).includes(v.role)) errors.role = "Vui lòng chọn vai trò.";
  return errors;
}

export const CREATE_LABELS = { name: "Họ và tên", email: "Email", role: "Vai trò" } as const;
export const CREATE_IDS = { name: "staff-name", email: "staff-email", role: "staff-role" } as const;
