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
} as const;

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
