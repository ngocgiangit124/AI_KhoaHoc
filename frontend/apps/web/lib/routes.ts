/**
 * Đường dẫn THẬT của web học sinh (bản xem trước v2 dùng `lib/v2/routes.ts` với tiền tố `/v2`).
 * Chỉ liệt kê những trang đã có thật: không đưa vào header/footer liên kết tới trang chưa dựng
 * (US-019 "không liên kết chết"). Khi FW3/FW4/FW5 xong thì thêm `myCourses`, `cart`, ... ở đây.
 */
export const routes = {
  home: "/",
  catalog: "/khoa-hoc",
  grade: (grade: number) => `/lop-${grade}`,
  course: (slug: string) => `/khoa-hoc/${slug}`,
  login: "/dang-nhap",
  register: "/dang-ky",
  verifyOtp: "/xac-thuc-otp",
  forgotPassword: "/quen-mat-khau",
  resetPassword: "/quen-mat-khau/dat-lai",
  account: "/tai-khoan",
  /** Màn chặn khi vào thẳng (design-system-v2 §12.8); ở chi tiết khóa học cùng nội dung hiện trong hộp thoại. */
  needVerify: "/can-xac-thuc",
  parentPending: "/cho-phu-huynh",
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
