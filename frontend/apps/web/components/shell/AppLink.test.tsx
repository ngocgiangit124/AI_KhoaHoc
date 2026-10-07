import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

// Link giả đánh dấu bằng data-soft để phân biệt với <a> thường.
vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} data-soft="1" {...rest}>
      {children}
    </a>
  ),
}));

import { CAPTCHA_PATHS, isCaptchaHref, routes } from "@/lib/routes";
import { AppLink } from "./AppLink";

describe("AppLink: điều hướng cứng tới route có Turnstile (CSP gắn theo tài liệu)", () => {
  it.each(CAPTCHA_PATHS)("%s là <a> thường, không phải next/link", (path) => {
    render(<AppLink href={path}>đi</AppLink>);
    const a = screen.getByRole("link", { name: "đi" });
    expect(a).toHaveAttribute("href", path);
    expect(a).not.toHaveAttribute("data-soft");
  });

  it("kể cả khi có query/hash; route khác vẫn là next/link", () => {
    render(
      <>
        <AppLink href="/dang-ky?next=%2F">a</AppLink>
        <AppLink href="/khoa-hoc">b</AppLink>
        <AppLink href="/dang-nhap">c</AppLink>
      </>,
    );
    expect(screen.getByRole("link", { name: "a" })).not.toHaveAttribute("data-soft");
    expect(screen.getByRole("link", { name: "b" })).toHaveAttribute("data-soft");
    expect(screen.getByRole("link", { name: "c" })).toHaveAttribute("data-soft");
  });

  it("CAPTCHA_PATHS gồm đúng đăng ký, quên mật khẩu, đặt lại; isCaptchaHref không khớp tiền tố gần giống", () => {
    expect([...CAPTCHA_PATHS]).toEqual([routes.register, routes.forgotPassword, routes.resetPassword]);
    expect(isCaptchaHref("/dang-ky-2")).toBe(false);
    expect(isCaptchaHref("/quen-mat-khau/dat-lai#x")).toBe(true);
  });
});
