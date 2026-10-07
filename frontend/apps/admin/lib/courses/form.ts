import type { CourseAbilities, CourseDetail } from "./types";

export const TITLE_MAX = 255;
export const SHORT_DESC_MAX = 500;
export const DESC_MAX = 100_000;
export const PRICE_MAX = 50_000_000;
export const MAX_SUBJECTS = 20;
export const TEACHER_REQUIRED_MESSAGE = "Khóa học cần tối thiểu 1 giáo viên phụ trách.";

export interface CourseFormValues {
  title: string;
  gradeLevel: string;
  subjectIds: number[];
  shortDescription: string;
  description: string;
  /** Chuỗi chữ số (ô nhập); rỗng = chưa nhập. */
  price: string;
  teacherIds: number[];
  thumbnail: File | null;
}

export const EMPTY_VALUES: CourseFormValues = {
  title: "",
  gradeLevel: "",
  subjectIds: [],
  shortDescription: "",
  description: "",
  price: "",
  teacherIds: [],
  thumbnail: null,
};

export function valuesFromCourse(course: CourseDetail): CourseFormValues {
  return {
    title: course.title,
    gradeLevel: String(course.grade_level),
    subjectIds: course.subjects.map((s) => s.id),
    shortDescription: course.short_description ?? "",
    description: course.description,
    price: String(course.price),
    teacherIds: course.teachers.map((t) => t.id),
    thumbnail: null,
  };
}

/** Chuẩn hoá tên như backend: trim + gộp khoảng trắng. */
export function normalizeTitle(raw: string): string {
  return raw.replace(/\s+/g, " ").trim();
}

// Thẻ HTML hoàn chỉnh (có ">" đóng); "a <b" hay "x < y" vẫn là văn bản thường và được escape.
const HAS_TAG = /<\/?[a-z][a-z0-9-]*(?:\s[^<>]*)?\/?>|<!--/i;

function escapeText(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

/**
 * Ô mô tả là textarea: nếu người dùng gõ văn bản thường (không có thẻ HTML) thì chuyển đoạn trống thành `<p>`
 * để học sinh thấy đúng xuống dòng. Có thẻ HTML thì gửi nguyên (server lọc bằng Purifier, S8).
 */
export function descriptionToHtml(raw: string): string {
  const text = raw.trim();
  if (text === "" || HAS_TAG.test(text)) return text;
  return text
    .split(/\n\s*\n/)
    .map((para) => para.trim())
    .filter(Boolean)
    .map((para) => `<p>${escapeText(para).replace(/\r?\n/g, "<br>")}</p>`)
    .join("");
}

export function parsePrice(raw: string): number | null {
  return /^\d{1,9}$/.test(raw.trim()) ? Number(raw.trim()) : null;
}

export interface FormRules {
  mode: "create" | "edit";
  isStaff: boolean;
  editPrice: boolean;
  editGradeLevel: boolean;
  /** Đã có ảnh trên server (sửa) → không bắt buộc chọn ảnh mới. */
  hasThumbnail: boolean;
}

export function rulesFromAbilities(a: CourseAbilities, hasThumbnail: boolean): FormRules {
  return { mode: "edit", isStaff: a.manage_teachers, editPrice: a.edit_price, editGradeLevel: a.edit_grade_level, hasThumbnail };
}

export type FieldErrors = Partial<Record<"title" | "grade_level" | "subject_ids" | "short_description" | "description" | "price" | "teacher_ids" | "thumbnail", string>>;

const PLAIN_TEXT_ERROR = "không được chứa ký tự < hoặc > (chỉ nhập văn bản thuần).";
const CONTROL_CHARS = /[\u0000-\u001f\u007f-\u009f]/;

/** Kiểm sơ bộ (server mới là nguồn sự thật). Trả lỗi theo field; rỗng = hợp lệ. */
export function validateCourseForm(v: CourseFormValues, rules: FormRules): FieldErrors {
  const errors: FieldErrors = {};
  const title = normalizeTitle(v.title);
  if (title === "") errors.title = "Vui lòng nhập tên khóa học.";
  else if ([...title].length > TITLE_MAX) errors.title = `Tên khóa học tối đa ${TITLE_MAX} ký tự.`;
  else if (/[<>]/.test(title)) errors.title = `Tên khóa học ${PLAIN_TEXT_ERROR}`;
  else if (CONTROL_CHARS.test(title)) errors.title = "Tên khóa học không được chứa ký tự điều khiển.";

  if (rules.editGradeLevel && !/^(6|7|8|9|10|11|12)$/.test(v.gradeLevel)) errors.grade_level = "Vui lòng chọn lớp (6–12).";

  if (v.subjectIds.length === 0) errors.subject_ids = "Vui lòng chọn ít nhất 1 chuyên đề.";
  else if (v.subjectIds.length > MAX_SUBJECTS) errors.subject_ids = `Chọn tối đa ${MAX_SUBJECTS} chuyên đề.`;

  const short = v.shortDescription.trim();
  if ([...short].length > SHORT_DESC_MAX) errors.short_description = `Mô tả ngắn tối đa ${SHORT_DESC_MAX} ký tự.`;
  else if (/[<>]/.test(short)) errors.short_description = `Mô tả ngắn ${PLAIN_TEXT_ERROR}`;

  const html = descriptionToHtml(v.description);
  if (html === "") errors.description = "Vui lòng nhập mô tả khóa học.";
  else if (html.length > DESC_MAX) errors.description = "Mô tả quá dài (tối đa 100.000 ký tự).";

  if (rules.editPrice) {
    const price = parsePrice(v.price);
    if (v.price.trim() === "") errors.price = "Vui lòng nhập học phí.";
    else if (price === null) errors.price = "Học phí phải là số nguyên (VNĐ), không âm.";
    else if (price > PRICE_MAX) errors.price = "Học phí tối đa 50.000.000đ.";
  }

  if (rules.mode === "create" && rules.isStaff && v.teacherIds.length === 0) errors.teacher_ids = TEACHER_REQUIRED_MESSAGE;
  if (rules.mode === "create" && v.thumbnail === null) errors.thumbnail = "Vui lòng chọn ảnh bìa.";
  return errors;
}

/** Body tạo: multipart (thumbnail bắt buộc). `teacher_ids[]` chỉ staff gửi (giáo viên bị bỏ qua, tự gán mình). */
export function buildCreateFormData(v: CourseFormValues, opts: { isStaff: boolean }): FormData {
  const f = new FormData();
  f.append("title", normalizeTitle(v.title));
  f.append("grade_level", v.gradeLevel);
  v.subjectIds.forEach((id) => f.append("subject_ids[]", String(id)));
  if (v.shortDescription.trim() !== "") f.append("short_description", v.shortDescription.trim());
  f.append("description", descriptionToHtml(v.description));
  f.append("price", String(parsePrice(v.price) ?? 0));
  if (opts.isStaff) v.teacherIds.forEach((id) => f.append("teacher_ids[]", String(id)));
  if (v.thumbnail) f.append("thumbnail", v.thumbnail);
  return f;
}

export type UpdateRequest =
  | { kind: "none" }
  | { kind: "json"; body: Record<string, unknown> }
  | { kind: "multipart"; form: FormData };

function sameIds(a: number[], b: number[]): boolean {
  return a.length === b.length && [...a].sort((x, y) => x - y).every((id, i) => id === [...b].sort((x, y) => x - y)[i]);
}

/**
 * Chỉ gửi trường đã đổi (API: tất cả `sometimes`). Trường bị ẩn theo `abilities` (giá, lớp) không bao giờ gửi.
 * Có ảnh mới → multipart (`POST` + `_method=PUT`, vì PHP không đọc multipart của PUT); không thì JSON `PUT`.
 * `teacher_ids` không gửi ở đây: gán giáo viên có thẻ riêng (PUT .../teachers).
 */
export function buildUpdateRequest(initial: CourseDetail, v: CourseFormValues): UpdateRequest {
  const a = initial.abilities;
  const fields: Record<string, string | number | number[] | null> = {};
  const title = normalizeTitle(v.title);
  if (title !== initial.title) fields.title = title;
  if (a.edit_grade_level && Number(v.gradeLevel) !== initial.grade_level) fields.grade_level = Number(v.gradeLevel);
  if (a.edit_price && parsePrice(v.price) !== initial.price) fields.price = parsePrice(v.price) ?? 0;
  if (v.shortDescription.trim() !== (initial.short_description ?? "")) fields.short_description = v.shortDescription.trim() === "" ? null : v.shortDescription.trim();
  if (v.description.trim() !== initial.description.trim()) fields.description = descriptionToHtml(v.description);
  if (!sameIds(v.subjectIds, initial.subjects.map((s) => s.id))) fields.subject_ids = v.subjectIds;

  if (v.thumbnail) {
    const form = new FormData();
    form.append("_method", "PUT");
    for (const [key, value] of Object.entries(fields)) {
      if (Array.isArray(value)) value.forEach((id) => form.append(`${key}[]`, String(id)));
      else form.append(key, value === null ? "" : String(value));
    }
    form.append("thumbnail", v.thumbnail);
    return { kind: "multipart", form };
  }
  return Object.keys(fields).length === 0 ? { kind: "none" } : { kind: "json", body: fields };
}

export function formatPriceLabel(price: number, formatter: (n: number) => string): string {
  return price === 0 ? "Miễn phí" : formatter(price);
}
