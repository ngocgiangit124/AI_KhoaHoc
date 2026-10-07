import type { ReactNode } from "react";
import {
  BottomNav,
  IconBookOpen,
  IconHome,
  IconPlayCircle,
  IconUser,
  SiteFooter,
  SiteHeader,
  cx,
} from "@vitaminvui/ui/v2";
import { publicConfig } from "@/lib/mock/v2/catalog";
import { routes, sampleStudent } from "@/lib/v2/routes";

export type ShellSection = "home" | "catalog" | "my-courses" | "account" | "auth";

export interface StudentShellProps {
  current: ShellSection;
  loggedIn: boolean;
  children: ReactNode;
  /** Dải xem trước (PreviewBar). */
  preview?: ReactNode;
  /** Trang đăng nhập/đăng ký: header tối giản, không footer đầy đủ. */
  minimal?: boolean;
  searchDefault?: string;
  /** Giả lập `paid_checkout_enabled` (mặc định theo config/public mẫu: false). */
  paidCheckoutEnabled?: boolean;
  /** Trang có thanh hành động dính đáy (chi tiết khóa học) thì không hiện bottom-nav. */
  hideBottomNav?: boolean;
  /**
   * Nền vùng nội dung: `oly` (mặc định) = vở ô ly nhạt chủ đạo của trang khách (`bg-oly-page`, §3.1);
   * `plain` = giấy trơn (dùng khi cả trang là một bảng/form dày đặc).
   */
  background?: "oly" | "plain";
}

/** Khung trang học sinh: header + nội dung + footer (+ bottom-nav mobile khi đã đăng nhập). */
export function StudentShell({
  current,
  loggedIn,
  children,
  preview,
  minimal = false,
  searchDefault,
  paidCheckoutEnabled,
  hideBottomNav = false,
  background = "oly",
}: StudentShellProps) {
  const showBottomNav = loggedIn && !minimal && !hideBottomNav;
  const paid = paidCheckoutEnabled ?? publicConfig.paid_checkout_enabled;
  const nav = [
    { href: routes.catalog, label: "Khóa học", current: current === "catalog", icon: <IconBookOpen /> },
    ...(loggedIn ? [{ href: routes.myCourses, label: "Khóa học của tôi", current: current === "my-courses", icon: <IconPlayCircle /> }] : []),
  ];
  return (
    <>
      {preview}
      <a
        href="#noi-dung"
        className="sr-only z-50 rounded-control bg-primary px-4 py-2 font-semibold text-on-primary focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      >
        Bỏ qua tới nội dung
      </a>
      <SiteHeader
        homeHref={routes.home}
        nav={nav}
        viewer={loggedIn ? { name: sampleStudent.name, accountHref: routes.account } : null}
        loginHref={routes.login}
        registerHref={routes.register}
        searchAction={routes.catalog}
        searchDefault={searchDefault}
        cart={paid && loggedIn ? { href: routes.cart, count: 1 } : null}
        minimal={minimal}
      />
      <main id="noi-dung" className={cx("flex-1", minimal && "flex flex-col", background === "oly" ? "bg-oly-page" : "bg-paper", showBottomNav && "pb-20 md:pb-0")}>
        {children}
      </main>
      {/* Footer không có liên kết Điều khoản/Chính sách cho tới khi có trang (V2) — US-019 "không liên kết chết". */}
      {minimal ? null : (
        <SiteFooter
          homeHref={routes.home}
          groups={[
            {
              title: "Học",
              links: [
                { href: routes.catalog, label: "Tất cả khóa học" },
                { href: routes.catalogQuery({ grade: 9 }), label: "Toán lớp 9 – ôn thi vào 10" },
                { href: routes.catalogQuery({ grade: 12 }), label: "Toán lớp 12 – ôn thi THPT" },
              ],
            },
            {
              title: "Tài khoản",
              links: [
                { href: routes.login, label: "Đăng nhập" },
                { href: routes.register, label: "Đăng ký" },
                { href: routes.myCourses, label: "Khóa học của tôi" },
              ],
            },
          ]}
          note="© 2026 VitaminVui. Bản xem trước — nội dung và số liệu là dữ liệu mẫu."
        />
      )}
      {showBottomNav ? (
        <BottomNav
          items={[
            { href: routes.home, label: "Trang chủ", icon: <IconHome />, current: current === "home" },
            { href: routes.catalog, label: "Khóa học", icon: <IconBookOpen />, current: current === "catalog" },
            { href: routes.myCourses, label: "Học của tôi", icon: <IconPlayCircle />, current: current === "my-courses" },
            { href: routes.account, label: "Tài khoản", icon: <IconUser />, current: current === "account" },
          ]}
        />
      ) : null}
    </>
  );
}
