/**
 * Đường dẫn bản xem trước v2. Route thật bỏ tiền tố `/v2` (xem design-system.md §7.1):
 * `/khoa-hoc`, `/khoa-hoc/{slug}`, `/hoc/{course}/bai/{lesson}`...
 */
const P = "/v2";

export const routes = {
  home: P,
  index: `${P}/muc-luc`,
  gallery: `${P}/thanh-phan`,
  catalog: `${P}/khoa-hoc`,
  catalogQuery: (q: Record<string, string | number | Array<string | number> | undefined>) => {
    const sp = new URLSearchParams();
    for (const [k, v] of Object.entries(q)) {
      if (v === undefined || v === "") continue;
      if (Array.isArray(v)) v.forEach((x) => sp.append(k, String(x)));
      else sp.set(k, String(v));
    }
    const qs = sp.toString();
    return qs ? `${P}/khoa-hoc?${qs}` : `${P}/khoa-hoc`;
  },
  course: (slug: string) => `${P}/khoa-hoc/${slug}`,
  login: `${P}/dang-nhap`,
  register: `${P}/dang-ky`,
  lesson: (courseId: number, lessonId: number) => `${P}/hoc/${courseId}/bai/${lessonId}`,
  quiz: (courseId: number, quizId: number) => `${P}/hoc/${courseId}/quiz/${quizId}`,
  quizResult: (courseId: number, quizId: number) => `${P}/hoc/${courseId}/quiz/${quizId}/ket-qua`,
  myCourses: `${P}/tai-khoan/khoa-hoc-cua-toi`,
  myCourse: (courseId: number) => `${P}/tai-khoan/khoa-hoc-cua-toi/${courseId}`,
  account: `${P}/tai-khoan`,
  cart: `${P}/gio-hang`,
  terms: `${P}/dieu-khoan`,
  privacy: `${P}/chinh-sach-du-lieu`,
  forgot: `${P}/quen-mat-khau`,
  resetPassword: `${P}/quen-mat-khau/dat-lai`,
  verifyOtp: `${P}/xac-thuc-otp`,
  needVerify: `${P}/can-xac-thuc`,
  parentPending: `${P}/cho-phu-huynh`,
} as const;

/** Che email kiểu `m*****1@gmail.com` (giữ ký tự đầu, cuối phần tên và tên miền). */
export function maskEmail(email: string): string {
  const at = email.lastIndexOf("@");
  if (at <= 1) return email;
  const local = email.slice(0, at);
  return `${local[0]}${"*".repeat(Math.min(6, Math.max(2, local.length - 2)))}${local[local.length - 1]}${email.slice(at)}`;
}

/** Học sinh mẫu đang đăng nhập. */
export const sampleStudent = { name: "Minh Anh", email: "minhanh.2011@gmail.com", phone: "0912345678", grade_level: 9 };

/** Đọc 1 giá trị query (Next 16: searchParams là Promise<Record<string, string | string[] | undefined>>). */
export function one(v: string | string[] | undefined): string | undefined {
  return Array.isArray(v) ? v[0] : v;
}

export function many(v: string | string[] | undefined): string[] {
  if (v === undefined) return [];
  return Array.isArray(v) ? v : [v];
}
