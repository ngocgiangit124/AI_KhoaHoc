/**
 * `process.env.NODE_ENV` được Next.js/webpack inline thành literal `"production"` /
 * `"development"` lúc build client bundle — an toàn dùng để chặn cứng những hành vi CHỈ
 * được phép ở local/dev (security review M3: không gửi `LOCAL_FAKE_CAPTCHA_TOKEN` nếu một
 * build production nào đó lỡ thiếu `NEXT_PUBLIC_TURNSTILE_SITE_KEY`).
 *
 * Đặt trong module riêng (thay vì đọc `process.env.NODE_ENV` thẳng trong component) để
 * test giả lập "production" được bằng cách gọi `__setIsProductionBuildForTest(true)` —
 * biến export là **binding sống** của ES module nên nơi import (`RegisterForm.tsx`) luôn
 * thấy giá trị mới nhất ngay khi gán lại, không cần `vi.resetModules()`/import động (cách
 * đó sẽ tạo ra 2 bản `@vitaminvui/ui` khác nhau → `useToast()` không khớp `ToastProvider`).
 */
export let isProductionBuild = process.env.NODE_ENV === "production";

/**
 * CHỈ dùng trong test. Vitest không thay `process.env.NODE_ENV` bằng cơ chế thay thế lúc
 * build như webpack `DefinePlugin`, nên cần cách giả lập khác cho test.
 */
export function __setIsProductionBuildForTest(value: boolean): void {
  isProductionBuild = value;
}
