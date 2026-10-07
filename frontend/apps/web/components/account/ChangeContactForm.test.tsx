import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";

const updateContact = vi.fn();
vi.mock("@/lib/auth/api", () => ({ updateContact: (...a: unknown[]) => updateContact(...a) }));

import { ChangeContactForm } from "./ChangeContactForm";

const onDone = vi.fn();
const props = { email: "nguyenvana@gmail.com", phone: "0912345678", onDone };

type U = ReturnType<typeof userEvent.setup>;
const save = (u: U) => u.click(screen.getByRole("button", { name: "Lưu thay đổi" }));

async function setEmail(u: U, value: string) {
  const email = screen.getByLabelText(/^Email/);
  await u.clear(email);
  await u.type(email, value);
}

describe("ChangeContactForm (đổi email/SĐT, cần mật khẩu hiện tại)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("chỉ gửi field đổi + current_password, rồi báo onDone với resendAvailableAt", async () => {
    const at = new Date(Date.now() + 60_000).toISOString();
    updateContact.mockResolvedValue({ resendAvailableAt: at });
    const u = userEvent.setup();
    render(<ChangeContactForm {...props} />);
    await setEmail(u, "moi@example.com");
    await u.type(screen.getByLabelText(/^Mật khẩu hiện tại/), "matkhau-123");
    await save(u);
    await waitFor(() => expect(updateContact).toHaveBeenCalledWith({ email: "moi@example.com", current_password: "matkhau-123" }));
    await waitFor(() => expect(onDone).toHaveBeenCalledWith({ resendAvailableAt: at, emailChanged: true }));
  });

  it("422 email trùng → lỗi dưới ô email và xoá ô mật khẩu", async () => {
    updateContact.mockRejectedValue(new ApiError(422, { message: "x", errors: { email: ["Email đã được sử dụng."] } }));
    const u = userEvent.setup();
    render(<ChangeContactForm {...props} />);
    await setEmail(u, "trung@example.com");
    await u.type(screen.getByLabelText(/^Mật khẩu hiện tại/), "matkhau-123");
    await save(u);
    expect(await screen.findByText("Email đã được sử dụng.")).toBeInTheDocument();
    expect(screen.getByLabelText(/^Mật khẩu hiện tại/)).toHaveValue("");
    expect(onDone).not.toHaveBeenCalled();
  });

  it("thiếu mật khẩu hiện tại -> lỗi tại ô, không gọi API", async () => {
    const u = userEvent.setup();
    render(<ChangeContactForm {...props} />);
    await setEmail(u, "moi@example.com");
    await save(u);
    expect(await screen.findByText("Vui lòng nhập mật khẩu hiện tại để xác nhận thay đổi.")).toBeInTheDocument();
    expect(updateContact).not.toHaveBeenCalled();
  });

  it("422 current_password -> lỗi dưới ô; 429 -> cảnh báo kèm thời gian chờ và khoá nút", async () => {
    updateContact.mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { current_password: ["Mật khẩu hiện tại không đúng."] } }));
    const u = userEvent.setup();
    render(<ChangeContactForm {...props} />);
    await setEmail(u, "moi@example.com");
    await u.type(screen.getByLabelText(/^Mật khẩu hiện tại/), "sai-roi-1234");
    await save(u);
    expect(await screen.findByText("Mật khẩu hiện tại không đúng.")).toBeInTheDocument();
    expect(screen.getByLabelText(/^Mật khẩu hiện tại/)).toHaveValue("");

    updateContact.mockRejectedValueOnce(new ApiError(429, { message: "x" }, 120));
    await u.type(screen.getByLabelText(/^Mật khẩu hiện tại/), "lai-sai-1234");
    await save(u);
    expect(await screen.findByText(/thử quá nhiều lần.*2 phút/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Lưu thay đổi" })).toBeDisabled();
  });

  it("chỉ đổi SĐT mà server không gửi mã mới → onDone(resendAvailableAt null, emailChanged false)", async () => {
    updateContact.mockResolvedValue({ resendAvailableAt: null });
    const u = userEvent.setup();
    render(<ChangeContactForm {...props} />);
    const phone = screen.getByLabelText(/^Số điện thoại/);
    await u.clear(phone);
    await u.type(phone, "0987654321");
    await u.type(screen.getByLabelText(/^Mật khẩu hiện tại/), "matkhau-123");
    await save(u);
    await waitFor(() => expect(onDone).toHaveBeenCalledWith({ resendAvailableAt: null, emailChanged: false }));
    expect(updateContact).toHaveBeenCalledWith({ phone: "0987654321", current_password: "matkhau-123" });
  });

  it("giá trị không đổi sau chuẩn hoá → không gọi API", async () => {
    const u = userEvent.setup();
    render(<ChangeContactForm {...props} />);
    const phone = screen.getByLabelText(/^Số điện thoại/);
    await u.clear(phone);
    await u.type(phone, "+84912345678");
    await save(u);
    expect(await screen.findByText("Bạn chưa thay đổi email hoặc số điện thoại.")).toBeInTheDocument();
    expect(updateContact).not.toHaveBeenCalled();
  });
});
