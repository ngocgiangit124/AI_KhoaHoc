/**
 * Đường dẫn THẬT của web học sinh (bản xem trước v2 dùng `lib/v2/routes.ts` với tiền tố `/v2`).
 * Chỉ liệt kê những trang đã có thật: không đưa vào header/footer liên kết tới trang chưa dựng
 * (US-019 "không liên kết chết"). Khi FW3/FW4/FW5 xong thì thêm `myCourses`, `account`, `cart`, ... ở đây.
 */
export const routes = {
  home: "/",
  catalog: "/khoa-hoc",
  grade: (grade: number) => `/lop-${grade}`,
  course: (slug: string) => `/khoa-hoc/${slug}`,
  login: "/dang-nhap",
  register: "/dang-ky",
  verifyOtp: "/xac-thuc-otp",
} as const;

/** Mục "Khóa học" của header sáng khi đang ở danh mục, trang lớp hoặc chi tiết khóa. */
export function isCatalogPath(pathname: string): boolean {
  return pathname === routes.catalog || pathname.startsWith(`${routes.catalog}/`) || /^\/lop-\d+$/.test(pathname);
}
