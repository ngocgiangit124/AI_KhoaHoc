import { expect, test } from "@playwright/test";

test("trang /dang-nhap lấy được CSRF token từ admin-api (CORS đúng origin) và có CSP nonce", async ({
  page,
}) => {
  const response = await page.goto("http://admin-api.localhost:3001/dang-nhap");
  expect(response).not.toBeNull();

  const csp = response?.headers()["content-security-policy"];
  expect(csp, "response phải có header Content-Security-Policy").toBeTruthy();
  expect(csp).toContain("nonce-");
  expect(csp).toContain("frame-src 'none'");

  const loginButton = page.getByRole("button", { name: "Đăng nhập" });
  // Nút bị khoá cho tới khi lấy được CSRF token thành công (LoginForm.tsx).
  await expect(loginButton).toBeEnabled({ timeout: 10_000 });
  await expect(page.getByText("Không kết nối được máy chủ")).toHaveCount(0);
});
