import type { Coupon, DiscountType } from "./types";

export const CODE_MIN = 4;
export const CODE_MAX = 50;
export const NAME_MAX = 255;
export const PERCENT_MAX = 100;
export const FIXED_MAX = 100_000_000;
export const MAX_USES_MAX = 1_000_000;
export const SCOPE_MAX = 200;

export const HIGH_RISK_MESSAGE =
  "Mã giảm 100% (hoặc giảm hết giá trị đơn hàng thấp nhất) bắt buộc có giới hạn lượt dùng và ngày hết hạn để tránh bị lộ mã và giữ chỗ ảo.";

export type ScopeKind = "all" | "subjects" | "courses" | "both";
export interface PickedCourse {
  id: number;
  title: string;
}

export interface CouponFormValues {
  code: string;
  name: string;
  type: DiscountType;
  /** Chuỗi chữ số (ô nhập). */
  value: string;
  maxUses: string;
  /** `YYYY-MM-DDTHH:mm` theo giờ Việt Nam (giá trị của `datetime-local`). */
  from: string;
  until: string;
  scope: ScopeKind;
  subjectIds: number[];
  courses: PickedCourse[];
}

export const EMPTY_VALUES: CouponFormValues = {
  code: "",
  name: "",
  type: "percent",
  value: "",
  maxUses: "",
  from: "",
  until: "",
  scope: "all",
  subjectIds: [],
  courses: [],
};

export type FieldKey = "code" | "name" | "discount_type" | "discount_value" | "max_uses" | "valid_from" | "valid_until" | "subject_ids" | "course_ids";
export type FieldErrors = Partial<Record<FieldKey, string>>;

export const FIELD_LABELS: Record<FieldKey, string> = {
  code: "Mã giảm giá",
  name: "Tên gợi nhớ",
  discount_type: "Loại giảm giá",
  discount_value: "Giá trị giảm",
  max_uses: "Tổng số lượt dùng tối đa",
  valid_from: "Bắt đầu",
  valid_until: "Kết thúc",
  subject_ids: "Chuyên đề",
  course_ids: "Khóa học",
};
export const FIELD_ORDER: readonly FieldKey[] = ["code", "name", "discount_type", "discount_value", "valid_from", "valid_until", "max_uses", "subject_ids", "course_ids"];

// --- Thời gian: ô nhập luôn theo giờ Việt Nam (+07:00), không phụ thuộc múi giờ máy ---

const VN_PARTS = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Asia/Ho_Chi_Minh",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  hourCycle: "h23",
});

/** ISO 8601 → `YYYY-MM-DDTHH:mm` giờ Việt Nam (cho `datetime-local`). */
export function isoToVnLocal(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  const p = Object.fromEntries(VN_PARTS.formatToParts(d).map((x) => [x.type, x.value]));
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
}

const LOCAL_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;
export const isVnLocal = (s: string) => LOCAL_RE.test(s) && !Number.isNaN(new Date(`${s}:00+07:00`).getTime());
export const vnLocalToIso = (s: string) => `${s}:00+07:00`;

// --- Chuẩn hoá & kiểm tra ---

export const normalizeCode = (raw: string) => raw.trim().toUpperCase();

export function parseIntStrict(raw: string): number | null {
  const t = raw.trim();
  return /^\d{1,12}$/.test(t) ? Number(t) : null;
}

export function valuesFromCoupon(c: Coupon): CouponFormValues {
  const hasSubjects = (c.subjects?.length ?? c.subjects_count ?? 0) > 0;
  const hasCourses = (c.courses?.length ?? c.courses_count ?? 0) > 0;
  const scope: ScopeKind = !c.is_restricted ? "all" : hasSubjects && hasCourses ? "both" : hasCourses ? "courses" : "subjects";
  return {
    code: c.code,
    name: c.name ?? "",
    type: c.discount_type,
    value: String(c.discount_value),
    maxUses: c.max_uses === null ? "" : String(c.max_uses),
    from: isoToVnLocal(c.valid_from),
    until: isoToVnLocal(c.valid_until),
    scope,
    subjectIds: (c.subjects ?? []).map((s) => s.id),
    courses: (c.courses ?? []).map((x) => ({ id: x.id, title: x.title })),
  };
}

/** Mã "giảm hết" (S18): 100%, hoặc giảm cố định ≥ giá khóa rẻ nhất đang bán (`cheapest` null = chưa biết → chỉ biết ca 100%). */
export function isHighRisk(v: Pick<CouponFormValues, "type" | "value">, cheapest: number | null): boolean {
  const n = parseIntStrict(v.value);
  if (n === null) return false;
  return v.type === "percent" ? n === PERCENT_MAX : cheapest !== null && n >= cheapest;
}

export interface ValidateContext {
  mode: "create" | "edit";
  /** Mã đã dùng: mã/loại/giá trị bị khoá, không kiểm lại. */
  locked: boolean;
  usedCount: number;
  cheapest: number | null;
  /** Giờ hiện tại theo `YYYY-MM-DDTHH:mm` giờ VN (tiêm để test). */
  nowLocal: string;
}

/** Kiểm sơ bộ khớp `CouponRequest` (server mới là nguồn sự thật). Trả lỗi theo field; rỗng = hợp lệ. */
export function validateCouponForm(v: CouponFormValues, ctx: ValidateContext): FieldErrors {
  const e: FieldErrors = {};
  if (!ctx.locked) {
    const code = normalizeCode(v.code);
    if (code === "") e.code = "Vui lòng nhập mã giảm giá.";
    else if (code.length < CODE_MIN || code.length > CODE_MAX) e.code = `Mã gồm ${CODE_MIN}–${CODE_MAX} ký tự.`;
    else if (!/^[A-Z0-9_-]+$/.test(code)) e.code = "Mã chỉ gồm chữ, số, gạch ngang (-) hoặc gạch dưới (_), không dấu, không khoảng trắng.";

    const n = parseIntStrict(v.value);
    if (v.value.trim() === "") e.discount_value = "Vui lòng nhập giá trị giảm.";
    else if (n === null || n < 1) e.discount_value = "Giá trị giảm phải là số nguyên lớn hơn 0.";
    else if (v.type === "percent" && n > PERCENT_MAX) e.discount_value = "Giá trị giảm không được vượt quá 100%.";
    else if (v.type === "fixed_amount" && n > FIXED_MAX) e.discount_value = "Số tiền giảm tối đa 100.000.000đ.";
  }

  const name = v.name.trim();
  if ([...name].length > NAME_MAX) e.name = `Tên tối đa ${NAME_MAX} ký tự.`;
  else if (/[<>]/.test(name)) e.name = "Tên không được chứa ký tự < hoặc > (chỉ nhập văn bản thuần).";

  if (v.from !== "" && !isVnLocal(v.from)) e.valid_from = "Ngày bắt đầu không hợp lệ.";
  if (v.until !== "" && !isVnLocal(v.until)) e.valid_until = "Ngày kết thúc không hợp lệ.";
  if (!e.valid_until && v.until !== "") {
    // Ô bắt đầu trống: tạo mới = từ bây giờ; sửa = giữ nguyên giá trị cũ (không biết ở đây) nên chỉ so khi tạo.
    const start = v.from !== "" ? v.from : ctx.mode === "create" ? ctx.nowLocal : "";
    if (start !== "" && !e.valid_from && v.until < start) e.valid_until = "Ngày kết thúc phải sau ngày bắt đầu.";
  }

  let maxUses: number | null = null;
  if (v.maxUses.trim() !== "") {
    maxUses = parseIntStrict(v.maxUses);
    if (maxUses === null || maxUses < 1) e.max_uses = "Số lượt phải là số nguyên lớn hơn 0 (để trống nếu không giới hạn).";
    else if (maxUses > MAX_USES_MAX) e.max_uses = "Tối đa 1.000.000 lượt.";
    else if (ctx.mode === "edit" && maxUses < ctx.usedCount) e.max_uses = `Không được nhỏ hơn số lượt đã dùng (${ctx.usedCount}).`;
  }

  if (isHighRisk(v, ctx.cheapest) && !ctx.locked) {
    if (v.maxUses.trim() === "" && !e.max_uses) e.max_uses = HIGH_RISK_MESSAGE;
    if (v.until === "" && !e.valid_until) e.valid_until = HIGH_RISK_MESSAGE;
  }

  if ((v.scope === "subjects" || v.scope === "both") && v.subjectIds.length === 0) e.subject_ids = "Chọn ít nhất 1 chuyên đề, hoặc chọn “Toàn bộ khóa học”.";
  else if (v.subjectIds.length > SCOPE_MAX) e.subject_ids = `Chọn tối đa ${SCOPE_MAX} chuyên đề.`;
  if ((v.scope === "courses" || v.scope === "both") && v.courses.length === 0) e.course_ids = "Chọn ít nhất 1 khóa học, hoặc chọn “Toàn bộ khóa học”.";
  else if (v.courses.length > SCOPE_MAX) e.course_ids = `Chọn tối đa ${SCOPE_MAX} khóa học.`;
  return e;
}

export const sameValues = (a: CouponFormValues, b: CouponFormValues) => JSON.stringify(normalizeForCompare(a)) === JSON.stringify(normalizeForCompare(b));

function normalizeForCompare(v: CouponFormValues) {
  const subjects = v.scope === "subjects" || v.scope === "both" ? [...v.subjectIds].sort((x, y) => x - y) : [];
  const courses = v.scope === "courses" || v.scope === "both" ? v.courses.map((c) => c.id).sort((x, y) => x - y) : [];
  return {
    code: normalizeCode(v.code),
    name: v.name.trim(),
    type: v.type,
    value: v.value.trim(),
    maxUses: v.maxUses.trim(),
    from: v.from,
    until: v.until,
    scope: v.scope === "all" ? "all" : "restricted",
    subjects,
    courses,
  };
}

/** Body POST/PUT. Sửa: `valid_from` null = giữ nguyên; mốc không đổi giữ NGUYÊN chuỗi ISO cũ (giữ giây, tránh audit diff giả). */
export function buildCouponBody(v: CouponFormValues, ctx: { mode: "create" | "edit"; initial: CouponFormValues | null; initialIso?: { from: string; until: string | null } }): Record<string, unknown> {
  const body: Record<string, unknown> = {
    code: normalizeCode(v.code),
    name: v.name.trim() === "" ? null : v.name.trim(),
    discount_type: v.type,
    discount_value: Number(v.value.trim()),
    max_uses: v.maxUses.trim() === "" ? null : Number(v.maxUses.trim()),
    subject_ids: v.scope === "subjects" || v.scope === "both" ? v.subjectIds : [],
    course_ids: v.scope === "courses" || v.scope === "both" ? v.courses.map((c) => c.id) : [],
  };
  if (ctx.mode === "create") {
    if (v.from !== "") body.valid_from = vnLocalToIso(v.from);
    body.valid_until = v.until === "" ? null : vnLocalToIso(v.until);
  } else {
    body.valid_from = v.from === "" || v.from === ctx.initial?.from ? null : vnLocalToIso(v.from);
    body.valid_until = v.until === "" ? null : v.until === ctx.initial?.until && ctx.initialIso?.until ? ctx.initialIso.until : vnLocalToIso(v.until);
  }
  return body;
}
