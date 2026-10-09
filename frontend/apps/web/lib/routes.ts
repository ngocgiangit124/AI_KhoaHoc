/**
 * Đường dẫn THẬT của web học sinh (bản xem trước v2 dùng `lib/v2/routes.ts` với tiền tố `/v2`).
 * Chỉ liệt kê những trang đã có thật: không đưa vào header/footer liên kết tới trang chưa dựng
 * (US-019 "không liên kết chết"). Khi FW3 xong thì thêm `cart`, ... ở đây.
 */
export const routes = {
  home: "/",
  catalog: "/khoa-hoc",
  grade: (grade: number) => `/lop-${grade}`,
  course: (slug: string) => `/khoa-hoc/${slug}`,
  /** Trang học video (FW4). `/hoc/{course}` chuyển tới bài cần học tiếp (`resume_lesson_id`). */
  learn: (courseId: number) => `/hoc/${courseId}`,
  lesson: (courseId: number, lessonId: number) => `/hoc/${courseId}/bai/${lessonId}`,
  /** Làm quiz (FW5). Kết quả: `?lan=<attemptId>` (bỏ trống = lượt đã nộp gần nhất); `loc=sai|bo-trong` lọc câu. */
  quiz: (courseId: number, quizId: number) => `/hoc/${courseId}/quiz/${quizId}`,
  quizResult: (courseId: number, quizId: number, opts: { attemptId?: number; filter?: "sai" | "bo-trong" } = {}) => {
    const q = new URLSearchParams();
    if (opts.attemptId) q.set("lan", String(opts.attemptId));
    if (opts.filter) q.set("loc", opts.filter);
    const qs = q.toString();
    return `/hoc/${courseId}/quiz/${quizId}/ket-qua${qs ? `?${qs}` : ""}`;
  },
  login: "/dang-nhap",
  register: "/dang-ky",
  verifyOtp: "/xac-thuc-otp",
  forgotPassword: "/quen-mat-khau",
  resetPassword: "/quen-mat-khau/dat-lai",
  account: "/tai-khoan",
  /** Khóa học của tôi + tiến độ (FW6). `?trang=` phân trang danh sách. */
  myCourses: "/tai-khoan/khoa-hoc-cua-toi",
  myCourse: (courseId: number) => `/tai-khoan/khoa-hoc-cua-toi/${courseId}`,
  /** Giỏ hàng, thanh toán, đơn hàng của tôi (FW3, US-022). `orderSent` = màn "Đơn đã gửi" (link trong thư cho học sinh). */
  cart: "/gio-hang",
  checkout: "/thanh-toan",
  orderSent: (code: string) => `/thanh-toan/da-gui/${code}`,
  myOrders: "/tai-khoan/don-hang",
  myOrder: (code: string) => `/tai-khoan/don-hang/${code}`,
  /** Quyền dữ liệu cá nhân (FW7): đồng ý, thông tin phụ huynh, tải dữ liệu, xoá tài khoản. */
  privacyData: "/tai-khoan/quyen-du-lieu-ca-nhan",
  /** Công khai, link trong thư phụ huynh: `?t=<token>` (token bị gỡ khỏi URL ngay khi mở). */
  parentUnsubscribe: "/phu-huynh/huy-nhan-thong-bao",
  /** Màn chặn khi vào thẳng (design-system-v2 §12.8); ở chi tiết khóa học cùng nội dung hiện trong hộp thoại. */
  needVerify: "/can-xac-thuc",
  terms: "/dieu-khoan",
  privacy: "/chinh-sach-du-lieu",
} as const;

/** Mục "Khóa học" của header sáng khi đang ở danh mục, trang lớp hoặc chi tiết khóa. */
export function isCatalogPath(pathname: string): boolean {
  return pathname === routes.catalog || pathname.startsWith(`${routes.catalog}/`) || /^\/lop-\d+$/.test(pathname);
}

/**
 * Route có Cloudflare Turnstile. CSP (nonce + frame-src/connect-src Cloudflare) gắn vào TÀI LIỆU HTML đầu tiên, nên mọi liên
 * kết tới các route này phải là điều hướng CỨNG (`<a href>`, tải lại tài liệu) — điều hướng mềm của App Router giữ CSP của
 * trang trước và iframe Turnstile bị chặn. Dùng chung cho `proxy.ts` và `AppLink`. Lý do không mở Cloudflare cho mọi trang khách:
 * giữ CSP chặt nhất có thể (ADR-004 §2.6), chi phí chỉ là một lần tải lại tài liệu khi vào 3 trang này.
 */
export const CAPTCHA_PATHS: readonly string[] = [routes.register, routes.forgotPassword, routes.resetPassword];

/** `href` (có thể kèm query/hash) trỏ tới route cần CSP Turnstile. */
export function isCaptchaHref(href: string): boolean {
  const path = href.split(/[?#]/)[0] ?? "";
  return CAPTCHA_PATHS.includes(path);
}
