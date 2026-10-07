import { SiteFooter } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/routes";

const GRADES = [6, 7, 8, 9, 10, 11, 12];

/**
 * Chân trang thật. Chưa có liên kết Điều khoản/Chính sách (trang V2) và "Khóa học của tôi" (FW5) — không để
 * liên kết chết (US-019). Hai nhóm theo lớp là liên kết nội bộ tới `/lop-{n}` (có trang thật + SEO).
 */
export function ShellFooter() {
  return (
    <SiteFooter
      homeHref={routes.home}
      groups={[
        {
          title: "Học",
          links: [
            { href: routes.catalog, label: "Tất cả khóa học" },
            { href: routes.register, label: "Tạo tài khoản" },
            { href: routes.login, label: "Đăng nhập" },
          ],
        },
        {
          title: "Toán THCS",
          links: GRADES.slice(0, 4).map((g) => ({ href: routes.grade(g), label: `Toán lớp ${g}` })),
        },
        {
          title: "Toán THPT",
          links: GRADES.slice(4).map((g) => ({ href: routes.grade(g), label: `Toán lớp ${g}` })),
        },
      ]}
      note="© 2026 VitaminVui. Khóa học Toán lớp 6–12 trực tuyến."
    />
  );
}
