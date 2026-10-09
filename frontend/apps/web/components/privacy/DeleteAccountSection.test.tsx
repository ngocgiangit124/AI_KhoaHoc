import { render as rtlRender, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactElement } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_API_URL: "http://api.test" } }));
const authFetch = vi.fn();
vi.mock("@/lib/api", () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }));
const clearCsrfToken = vi.fn();
vi.mock("@vitaminvui/api-client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@vitaminvui/api-client")>()),
  clearCsrfToken: (...a: unknown[]) => clearCsrfToken(...a),
}));

import { DeleteAccountSection } from "./DeleteAccountSection";

const render = (ui: ReactElement) => rtlRender(<ToastProvider>{ui}</ToastProvider>);
const apiErr = (status: number, body: Record<string, unknown>, retry?: number) =>
  new ApiError(status, body as unknown as ConstructorParameters<typeof ApiError>[1], retry);

const assign = vi.fn();
beforeEach(() => {
  authFetch.mockReset();
  clearCsrfToken.mockReset();
  assign.mockReset();
  sessionStorage.clear();
  vi.stubGlobal("location", { ...window.location, assign });
  HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement) {
    this.setAttribute("open", "");
  };
  HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement) {
    this.removeAttribute("open");
  };
});

/** Mở hộp thoại, gửi OTP (202), tới bước nhập mã. `confirm` quyết định kết quả POST /me/account/delete. */
async function toOtpStep(confirm: () => Promise<unknown>) {
  authFetch.mockImplementation(async (path: string) => {
    if (path.endsWith("/delete/otp")) return { resend_available_at: new Date(Date.now() + 60_000).toISOString(), destination_masked: "an***@example.com" };
    return confirm();
  });
  const user = userEvent.setup();
  render(<DeleteAccountSection />);
  await user.click(screen.getByRole("button", { name: "Xoá tài khoản của tôi" }));
  await user.click(await screen.findByRole("button", { name: "Gửi mã xác nhận" }));
  await user.type(await screen.findByLabelText(/Mã xác nhận/), "123456");
  return user;
}

describe("DeleteAccountSection", () => {
  it("xoá thành công: bỏ cache CSRF (một lần), đặt cờ flash và tải cứng '/'", async () => {
    const user = await toOtpStep(async () => ({ message: "ok" }));
    await user.click(screen.getByRole("button", { name: "Xoá tài khoản vĩnh viễn" }));
    await waitFor(() => expect(assign).toHaveBeenCalledWith("/"));
    expect(clearCsrfToken).toHaveBeenCalledTimes(1);
    expect(sessionStorage.getItem("vv:account-flash")).toBe("account-deleted");
  });

  it("409 ACCOUNT_HAS_PENDING_PAYMENT ở bước xác nhận: quay về cảnh báo, hiện giờ thử lại, không rời trang", async () => {
    const user = await toOtpStep(async () => {
      throw apiErr(409, { message: "m", code: "ACCOUNT_HAS_PENDING_PAYMENT", errors: { retry_after_at: "2026-10-08T15:30:00+07:00" } });
    });
    await user.click(screen.getByRole("button", { name: "Xoá tài khoản vĩnh viễn" }));
    expect(await screen.findByText(/đơn chờ thanh toán.*15:30, 08\/10\/2026/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Gửi mã xác nhận" })).toBeInTheDocument();
    expect(assign).not.toHaveBeenCalled();
    expect(clearCsrfToken).not.toHaveBeenCalled();
  });

  it("US-022 AC28: 409 có errors.pending_order_code -> thông điệp server + liên kết tới đơn để tự huỷ", async () => {
    const user = await toOtpStep(async () => {
      throw apiErr(409, {
        message: "Bạn đang có đơn #VVFW3CANC001 chờ Quản trị viên duyệt. Hãy huỷ đơn trong Đơn của tôi nếu không còn muốn mua, rồi thử lại.",
        code: "ACCOUNT_HAS_PENDING_PAYMENT",
        errors: { retry_after_at: "2026-10-12T10:15:00+07:00", pending_order_code: "VVFW3CANC001" },
      });
    });
    await user.click(screen.getByRole("button", { name: "Xoá tài khoản vĩnh viễn" }));
    expect(await screen.findByText(/Hãy huỷ đơn trong Đơn của tôi/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Xem đơn VVFW3CANC001" })).toHaveAttribute("href", "/tai-khoan/don-hang/VVFW3CANC001");
    expect(assign).not.toHaveBeenCalled();
  });

  it("429 hết lượt nhập mã (không Retry-After): khoá nút xoá, chỉ còn gửi lại mã", async () => {
    const user = await toOtpStep(async () => {
      throw apiErr(429, { message: "m", code: "TOO_MANY_ATTEMPTS" });
    });
    await user.click(screen.getByRole("button", { name: "Xoá tài khoản vĩnh viễn" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Xoá tài khoản vĩnh viễn" })).toBeDisabled());
    expect(screen.getByText(/sai mã này 5 lần/)).toBeInTheDocument();
    expect(assign).not.toHaveBeenCalled();
  });
});
