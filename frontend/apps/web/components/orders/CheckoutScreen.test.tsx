import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { item, preview } from "@/lib/orders/fixtures";

const mocks = vi.hoisted(() => ({
  submitCheckout: vi.fn(),
  push: vi.fn(),
  replace: vi.fn(),
}));
vi.mock("@/lib/orders/api", () => ({
  submitCheckout: mocks.submitCheckout,
  fetchCheckoutPreview: vi.fn(),
  fetchPaymentConfig: vi.fn().mockResolvedValue({ paid_checkout_enabled: true, payment_methods: ["manual"], manual_payment: { label: "x", pending_ttl_hours: 72, contact: { phone: null, zalo_url: null, email: null, hours: null } } }),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: mocks.push, replace: mocks.replace }) }));
vi.mock("@/lib/auth/AuthProvider", () => ({
  useAuth: () => ({ state: { status: "user", user: { id: 1, name: "A", email: "hs@example.com", phone: "0912345678", role: "hoc_sinh", grade_level: 9, is_verified: true } }, refresh: vi.fn() }),
}));

import { CheckoutForm } from "./CheckoutScreen";

const err = (status: number, body: Record<string, unknown>) => new ApiError(status, body as unknown as ConstructorParameters<typeof ApiError>[1]);
const view = (p = preview()) => render(<ToastProvider><CheckoutForm initial={p} /></ToastProvider>);
const submitBtn = () => screen.getAllByRole("button", { name: "Gửi đơn" })[0] as HTMLElement;

beforeEach(() => {
  mocks.submitCheckout.mockReset();
  mocks.push.mockReset();
  mocks.replace.mockReset();
  HTMLDialogElement.prototype.showModal ??= function showModal(this: HTMLDialogElement) {
    this.setAttribute("open", "");
  };
  HTMLDialogElement.prototype.close ??= function close(this: HTMLDialogElement) {
    this.removeAttribute("open");
  };
});

describe("CheckoutForm (FW3, US-022)", () => {
  it("AC2: một lựa chọn 'Liên hệ Quản trị viên' chọn sẵn từ payment_methods, không có MoMo; hiện email/SĐT tài khoản và câu nhắc ghi chú", () => {
    view();
    const radios = screen.getAllByRole("radio");
    expect(radios).toHaveLength(1);
    expect(radios[0]).toBeChecked();
    expect(screen.getByText("Liên hệ Quản trị viên")).toBeInTheDocument();
    expect(screen.queryByText(/MoMo/i)).not.toBeInTheDocument();
    expect(screen.getByText(/Email: hs@example.com/)).toBeInTheDocument();
    expect(screen.getByText(/Số điện thoại: 0912345678/)).toBeInTheDocument();
    expect(screen.getByText(/Không ghi mật khẩu hay mã OTP/)).toBeInTheDocument();
  });

  it("danh sách phương thức render theo server: thêm cổng thứ hai thì có 2 radio, mặc định theo default_payment_method", () => {
    view(
      preview({
        payment_methods: [
          { code: "manual", label: "Liên hệ Quản trị viên", description: null },
          { code: "momo", label: "Ví MoMo", description: "Thanh toán ngay" },
        ],
        default_payment_method: "momo",
      }),
    );
    const radios = screen.getAllByRole("radio");
    expect(radios).toHaveLength(2);
    expect(radios[1]).toBeChecked();
    expect(screen.queryByText(/Ghi chú cho Quản trị viên/)).not.toBeInTheDocument(); // ghi chú chỉ cho manual
  });

  it("AC3: gửi đơn với expected_total, payment_method, customer_note; thành công chuyển tới /thanh-toan/da-gui/{code}", async () => {
    mocks.submitCheckout.mockResolvedValueOnce({ order_code: "VV261008K7M2QX", status: "pending", total: 600000 });
    view();
    await userEvent.type(screen.getByLabelText(/Ghi chú cho Quản trị viên/), "Gọi sau 18h");
    await userEvent.click(submitBtn());
    await waitFor(() => expect(mocks.push).toHaveBeenCalledWith("/thanh-toan/da-gui/VV261008K7M2QX"));
    expect(mocks.submitCheckout).toHaveBeenCalledWith({ expected_total: 600000, payment_method: "manual", customer_note: "Gọi sau 18h" });
  });

  it("bấm kép chỉ gửi một request (nút khoá khi đang gửi)", async () => {
    let resolve: (v: unknown) => void = () => {};
    mocks.submitCheckout.mockReturnValueOnce(new Promise((r) => (resolve = r)));
    view();
    const btn = submitBtn();
    await userEvent.click(btn);
    await userEvent.click(btn);
    expect(mocks.submitCheckout).toHaveBeenCalledTimes(1);
    expect(screen.getAllByRole("button", { name: /Đang gửi đơn/ })[0]).toBeDisabled();
    resolve({ order_code: "VV1", status: "pending", total: 1 });
  });

  it("200 reused: sang màn đơn kèm cờ dung-lai", async () => {
    mocks.submitCheckout.mockResolvedValueOnce({ order_code: "VV261008K7M2QX", status: "pending", total: 600000, reused: true });
    view();
    await userEvent.click(submitBtn());
    await waitFor(() => expect(mocks.push).toHaveBeenCalledWith("/thanh-toan/da-gui/VV261008K7M2QX?dung-lai=1"));
  });

  it("AC6: 409 PENDING_ORDER_EXISTS -> hộp thoại; 'Huỷ đơn cũ, đặt đơn mới' gửi lại với replace_pending=true", async () => {
    mocks.submitCheckout
      .mockRejectedValueOnce(
        err(409, { message: "m", code: "PENDING_ORDER_EXISTS", errors: { order_code: "VV261008K7M2QX", payment_method: "manual", items_count: 2, total: 500000, created_at: "2026-10-08T10:15:00+07:00", expires_at: "2026-10-11T10:15:00+07:00" } }),
      )
      .mockResolvedValueOnce({ order_code: "VV261009AAAAAA", status: "pending", total: 600000 });
    view();
    await userEvent.click(submitBtn());
    expect(await screen.findByText("Bạn đang có đơn chờ duyệt", { selector: "h2" })).toBeInTheDocument();
    expect(screen.getByText(/2 khóa/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Huỷ đơn cũ, đặt đơn mới" }));
    await waitFor(() => expect(mocks.push).toHaveBeenCalledWith("/thanh-toan/da-gui/VV261009AAAAAA"));
    expect(mocks.submitCheckout).toHaveBeenLastCalledWith(expect.objectContaining({ replace_pending: true, expected_total: 600000 }));
  });

  it("AC6: 'Giữ đơn cũ' mở đơn cũ, không gọi API lần nữa", async () => {
    mocks.submitCheckout.mockRejectedValueOnce(
      err(409, { message: "m", code: "PENDING_ORDER_EXISTS", errors: { order_code: "VV261008K7M2QX", items_count: 1, total: 1, created_at: "2026-10-08T10:15:00+07:00" } }),
    );
    view();
    await userEvent.click(submitBtn());
    await userEvent.click(await screen.findByRole("button", { name: "Giữ đơn cũ" }));
    expect(mocks.push).toHaveBeenCalledWith("/tai-khoan/don-hang/VV261008K7M2QX");
    expect(mocks.submitCheckout).toHaveBeenCalledTimes(1);
  });

  it("AC7: 409 CHECKOUT_CHANGED hiện preview mới, KHÔNG tự gửi lại; bấm lại dùng expected_total mới", async () => {
    const changed = preview({ items: [item(1), item(2)], pricing: { subtotal: 600000, discount: 0, total: 600000 }, coupon: null });
    mocks.submitCheckout
      .mockRejectedValueOnce(err(409, { message: "m", code: "CHECKOUT_CHANGED", errors: { reasons: ["COUPON_EXHAUSTED"], preview: changed } }))
      .mockResolvedValueOnce({ order_code: "VV1AAAAAA", status: "pending", total: 600000 });
    view(preview({ pricing: { subtotal: 600000, discount: 50000, total: 550000 }, coupon: { code: "HE2026", applies_to_course_ids: [1], discount_amount: 50000 } }));
    await userEvent.click(submitBtn());
    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("Giỏ hàng vừa thay đổi");
    expect(alert).toHaveTextContent("hết lượt");
    expect(mocks.submitCheckout).toHaveBeenCalledTimes(1);
    expect(mocks.push).not.toHaveBeenCalled();
    await userEvent.click(submitBtn());
    await waitFor(() => expect(mocks.submitCheckout).toHaveBeenCalledTimes(2));
    expect(mocks.submitCheckout).toHaveBeenLastCalledWith(expect.objectContaining({ expected_total: 600000 }));
  });

  it("AC8: 429 MANUAL_ORDER_LIMIT hiện thông điệp server + thời điểm đặt lại, không tự thử lại", async () => {
    mocks.submitCheckout.mockRejectedValueOnce(err(429, { message: "Bạn đã đặt quá nhiều đơn hôm nay, vui lòng liên hệ Quản trị viên.", code: "MANUAL_ORDER_LIMIT", errors: { limit: 5, resets_at: "2026-10-09T00:00:00+07:00" } }));
    view();
    await userEvent.click(submitBtn());
    expect(await screen.findByText(/vui lòng liên hệ Quản trị viên/)).toBeInTheDocument();
    expect(screen.getByText(/Từ 00:00, 09\/10\/2026 bạn có thể đặt lại/)).toBeInTheDocument();
    expect(mocks.submitCheckout).toHaveBeenCalledTimes(1);
  });

  it("503 PAYMENT_DISABLED: thông điệp, khoá nút, không tự thử lại", async () => {
    mocks.submitCheckout.mockRejectedValueOnce(err(503, { message: "Thanh toán trực tuyến đang tạm khoá.", code: "PAYMENT_DISABLED" }));
    view();
    await userEvent.click(submitBtn());
    expect(await screen.findByRole("alert")).toHaveTextContent("Đặt mua đang tạm đóng");
    expect(submitBtn()).toBeDisabled();
  });

  it("422 CART_EMPTY về giỏ; 403 ACCOUNT_NOT_VERIFIED sang hướng dẫn xác thực", async () => {
    mocks.submitCheckout.mockRejectedValueOnce(err(422, { message: "m", code: "CART_EMPTY" }));
    const { unmount } = view();
    await userEvent.click(submitBtn());
    await waitFor(() => expect(mocks.replace).toHaveBeenCalledWith("/gio-hang"));
    unmount();
    mocks.submitCheckout.mockRejectedValueOnce(err(403, { message: "m", code: "ACCOUNT_NOT_VERIFIED" }));
    view();
    await userEvent.click(submitBtn());
    await waitFor(() => expect(mocks.push).toHaveBeenCalledWith("/can-xac-thuc"));
  });

  it("ghi chú: đếm ký tự, chặn < > ở máy khách, lỗi 422 customer_note hiện dưới ô", async () => {
    view();
    const note = screen.getByLabelText(/Ghi chú cho Quản trị viên/);
    await userEvent.type(note, "<b>");
    expect(screen.getByText("3/500")).toBeInTheDocument();
    await userEvent.click(submitBtn());
    expect(await screen.findByText(/không dùng dấu < hoặc >/)).toBeInTheDocument();
    expect(mocks.submitCheckout).not.toHaveBeenCalled();
  });

  it("đơn 0đ: không gửi payment_method/customer_note, nút 'Hoàn tất đăng ký'", async () => {
    mocks.submitCheckout.mockResolvedValueOnce({ order_code: "VV1PAID00", status: "paid", total: 0 });
    view(preview({ pricing: { subtotal: 300000, discount: 300000, total: 0 }, requires_payment: false, items: [item(1, { final_amount: 0, discount_amount: 300000 })] }));
    expect(screen.queryByRole("radio")).not.toBeInTheDocument();
    await userEvent.click(screen.getAllByRole("button", { name: "Hoàn tất đăng ký" })[0] as HTMLElement);
    await waitFor(() => expect(mocks.submitCheckout).toHaveBeenCalledWith({ expected_total: 0 }));
  });

  it("báo trước khi đã có đơn chờ (pending_order) và liệt kê khóa không còn bán", () => {
    view(
      preview({
        pending_order: { code: "VV261008K7M2QX", payment_method: "manual", total: 500000, created_at: "2026-10-08T10:15:00+07:00", expires_at: "2026-10-11T10:15:00+07:00" },
        removed_items: [item(9, { title: "Khóa cũ", unavailable: true, discount_amount: null, final_amount: null })],
      }),
    );
    expect(screen.getByText(/Bạn đang có đơn VV261008K7M2QX chờ duyệt/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Xem đơn" })).toHaveAttribute("href", "/tai-khoan/don-hang/VV261008K7M2QX");
    expect(screen.getByText("Khóa cũ")).toBeInTheDocument();
  });

  it("không có phương thức nào (payment_methods rỗng, tổng > 0): nút khoá + thông báo tạm đóng", () => {
    view(preview({ payment_methods: [], default_payment_method: null, can_checkout: false }));
    expect(screen.getByText(/Chưa có phương thức thanh toán nào đang mở/)).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /Đặt mua|Gửi đơn/ })[0]).toBeDisabled();
  });
});
