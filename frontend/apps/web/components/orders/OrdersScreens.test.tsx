import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { cart, item, listItem, order } from "@/lib/orders/fixtures";
import { resetPaymentConfigCache } from "@/lib/orders/usePaymentConfig";

const mocks = vi.hoisted(() => ({
  fetchCart: vi.fn(),
  removeCartItem: vi.fn(),
  applyCoupon: vi.fn(),
  removeCoupon: vi.fn(),
  fetchOrder: vi.fn(),
  fetchOrders: vi.fn(),
  cancelOrder: vi.fn(),
  fetchPaymentConfig: vi.fn(),
  replace: vi.fn(),
  refresh: vi.fn(),
  auth: { state: { status: "user" } as { status: string } },
}));
vi.mock("@/lib/orders/api", () => ({
  fetchCart: mocks.fetchCart,
  removeCartItem: mocks.removeCartItem,
  applyCoupon: mocks.applyCoupon,
  removeCoupon: mocks.removeCoupon,
  fetchOrder: mocks.fetchOrder,
  fetchOrders: mocks.fetchOrders,
  cancelOrder: mocks.cancelOrder,
  fetchPaymentConfig: mocks.fetchPaymentConfig,
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: mocks.replace, push: vi.fn() }), usePathname: () => "/" }));
vi.mock("@/lib/auth/AuthProvider", () => ({ useAuth: () => ({ state: mocks.auth.state, refresh: mocks.refresh }) }));

import { CartScreen } from "./CartScreen";
import { OrderDetailScreen } from "./OrderDetailScreen";
import { OrderSentScreen } from "./OrderSentScreen";
import { OrdersScreen } from "./OrdersScreen";

const err = (status: number, body: Record<string, unknown>, retry?: number) => new ApiError(status, body as unknown as ConstructorParameters<typeof ApiError>[1], retry);
const config = (contact: { phone: string | null; zalo_url: string | null; email: string | null; hours: string | null } = { phone: "0915 592 224", zalo_url: "https://zalo.me/0915592224", email: "hotro@vitaminvui.vn", hours: "8h–17h" }) => ({
  paid_checkout_enabled: true,
  payment_methods: ["manual"],
  manual_payment: { label: "Liên hệ Quản trị viên", description: null, pending_ttl_hours: 72, contact },
});
const wrap = (ui: React.ReactNode) => render(<ToastProvider>{ui}</ToastProvider>);

beforeEach(() => {
  for (const k of ["fetchCart", "removeCartItem", "applyCoupon", "removeCoupon", "fetchOrder", "fetchOrders", "cancelOrder", "fetchPaymentConfig", "replace", "refresh"] as const) mocks[k].mockReset();
  mocks.auth.state = { status: "user" };
  mocks.fetchPaymentConfig.mockResolvedValue(config());
  resetPaymentConfigCache();
  HTMLDialogElement.prototype.showModal ??= function showModal(this: HTMLDialogElement) {
    this.setAttribute("open", "");
  };
  HTMLDialogElement.prototype.close ??= function close(this: HTMLDialogElement) {
    this.removeAttribute("open");
  };
});

describe("Giỏ hàng", () => {
  it("hiện khóa, tổng lấy từ server; xoá khóa gọi DELETE rồi thay giỏ bằng response và làm mới số giỏ", async () => {
    mocks.fetchCart.mockResolvedValue(cart());
    mocks.removeCartItem.mockResolvedValue(cart({ items: [item(2)], pricing: { subtotal: 300000, discount: 0, total: 300000 } }));
    wrap(<CartScreen />);
    expect(await screen.findByText("Khóa 1")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Xoá khóa Khóa 1 khỏi giỏ" }));
    await waitFor(() => expect(screen.queryByRole("link", { name: "Khóa 1" })).not.toBeInTheDocument());
    expect(mocks.removeCartItem).toHaveBeenCalledWith(1);
    expect(mocks.refresh).toHaveBeenCalled();
    expect(screen.getAllByText(/300\.000/).length).toBeGreaterThan(0);
  });

  it("áp mã sai: lỗi dưới ô (thông điệp server), giữ nguyên giỏ; mã đúng hiện mã + nút Gỡ mã", async () => {
    mocks.fetchCart.mockResolvedValue(cart());
    mocks.applyCoupon.mockRejectedValueOnce(err(422, { message: "Mã giảm giá đã hết hạn.", code: "COUPON_EXPIRED" }));
    mocks.applyCoupon.mockResolvedValueOnce(
      cart({ coupon: { code: "HE2026", name: "Giảm hè", applies_to_course_ids: [1], discount_amount: 50000 }, pricing: { subtotal: 600000, discount: 50000, total: 550000 } }),
    );
    wrap(<CartScreen />);
    const input = await screen.findByLabelText(/Nhập mã/);
    await userEvent.type(input, "he2026");
    await userEvent.click(screen.getByRole("button", { name: "Áp dụng" }));
    expect(await screen.findByText("Mã giảm giá đã hết hạn.")).toBeInTheDocument();
    expect(mocks.applyCoupon).toHaveBeenCalledWith("HE2026");
    await userEvent.click(screen.getByRole("button", { name: "Áp dụng" }));
    expect(await screen.findByRole("button", { name: "Gỡ mã" })).toBeInTheDocument();
    expect(screen.getAllByText(/HE2026/).length).toBeGreaterThan(0);
  });

  it("khóa không còn bán: nhãn + thông báo notice của server; chỉ còn khóa không bán thì nút Tiếp tục bị khoá", async () => {
    mocks.fetchCart.mockResolvedValue(
      cart({ items: [item(3, { unavailable: true, discount_amount: null, final_amount: null })], pricing: { subtotal: 0, discount: 0, total: 0 }, notices: [{ code: "ITEMS_UNAVAILABLE", message: "1 khóa trong giỏ không còn bán." }] }),
    );
    wrap(<CartScreen />);
    expect(await screen.findByText("1 khóa trong giỏ không còn bán.")).toBeInTheDocument();
    expect(screen.getByText("Không còn bán")).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: "Tiếp tục đặt mua" })[0]).toBeDisabled();
  });

  it("giỏ trống: trạng thái rỗng; lỗi tải: Thử lại; khách: chuyển tới đăng nhập kèm next", async () => {
    mocks.fetchCart.mockResolvedValueOnce(cart({ items: [] }));
    const { unmount } = wrap(<CartScreen />);
    expect(await screen.findByText("Giỏ hàng đang trống")).toBeInTheDocument();
    unmount();

    mocks.fetchCart.mockRejectedValueOnce(err(500, { message: "x" }));
    mocks.fetchCart.mockResolvedValueOnce(cart());
    const second = wrap(<CartScreen />);
    await userEvent.click(await screen.findByRole("button", { name: "Thử lại" }));
    expect(await screen.findByText("Khóa 1")).toBeInTheDocument();
    second.unmount();

    mocks.auth.state = { status: "guest" };
    wrap(<CartScreen />);
    await waitFor(() => expect(mocks.replace).toHaveBeenCalledWith(expect.stringContaining("/dang-nhap?")));
    expect(mocks.replace.mock.calls[0]?.[0]).toContain(encodeURIComponent("/gio-hang"));
  });
});

describe("Đơn đã gửi (AC9)", () => {
  it("đơn pending: mã to + sao chép, câu ghi mã đơn, kênh liên hệ (bỏ kênh null), câu chống lừa đảo, hạn chờ, KHÔNG có số tài khoản", async () => {
    mocks.fetchOrder.mockResolvedValue(order());
    mocks.fetchPaymentConfig.mockResolvedValue(config({ phone: null, zalo_url: null, email: "hotro@vitaminvui.vn", hours: null }));
    wrap(<OrderSentScreen code="VV261008K7M2QX" reused={false} />);
    expect(await screen.findByRole("heading", { name: "Đã gửi đơn" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Sao chép mã đơn" })).toBeInTheDocument();
    expect(screen.getByText(/vui lòng ghi mã đơn/)).toBeInTheDocument();
    expect(await screen.findByRole("link", { name: /hotro@vitaminvui\.vn/ })).toHaveAttribute("href", "mailto:hotro@vitaminvui.vn?subject=%C4%90%C6%A1n%20VV261008K7M2QX");
    expect(screen.queryByRole("link", { name: /Gọi điện/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Zalo/ })).not.toBeInTheDocument();
    expect(screen.getByText(/không đăng số tài khoản trên website/)).toBeInTheDocument();
    expect(screen.getByText(/chưa phải trả tiền/)).toBeInTheDocument();
    expect(screen.getByText(/còn \d+ giờ|đã quá hạn|dưới 1 giờ/)).toBeInTheDocument();
  });

  it("Zalo sai định dạng bị ẩn; email có ký tự lạ (?&#) không thành mailto", async () => {
    mocks.fetchOrder.mockResolvedValue(order());
    mocks.fetchPaymentConfig.mockResolvedValue(config({ phone: "0915 592 224", zalo_url: "javascript:alert(1)", email: "a@b.com?bcc=x@evil.com", hours: null }));
    wrap(<OrderSentScreen code="VV261008K7M2QX" reused={false} />);
    expect(await screen.findByRole("link", { name: /Gọi điện/ })).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Zalo/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Gửi email/ })).not.toBeInTheDocument();
  });

  it("đủ 4 kênh: tel:, Zalo mở tab mới noopener noreferrer, giờ hỗ trợ", async () => {
    mocks.fetchOrder.mockResolvedValue(order());
    wrap(<OrderSentScreen code="VV261008K7M2QX" reused={false} />);
    const tel = await screen.findByRole("link", { name: /Gọi điện/ });
    expect(tel).toHaveAttribute("href", "tel:0915592224");
    const zalo = screen.getByRole("link", { name: /Nhắn Zalo/ });
    expect(zalo).toHaveAttribute("href", "https://zalo.me/0915592224");
    expect(zalo).toHaveAttribute("target", "_blank");
    expect(zalo).toHaveAttribute("rel", "noopener noreferrer");
    expect(screen.getByText("8h–17h")).toBeInTheDocument();
  });

  it("đơn đã duyệt / đã huỷ: không còn hướng dẫn liên hệ", async () => {
    mocks.fetchOrder.mockResolvedValue(order({ status: "paid", status_reason: "manual_confirmed", paid_at: "2026-10-09T09:00:00+07:00", can_cancel: false }));
    wrap(<OrderSentScreen code="VV261008K7M2QX" reused={false} />);
    expect(await screen.findByText(/Các khóa trong đơn đã mở/)).toBeInTheDocument();
    expect(screen.queryByText("Liên hệ Quản trị viên")).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Gọi điện/ })).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Vào học" })).toBeInTheDocument();
  });

  it("đơn của người khác / không tồn tại (404) -> thông báo không tìm thấy, không lộ gì", async () => {
    mocks.fetchOrder.mockRejectedValue(err(404, { message: "m", code: "NOT_FOUND" }));
    wrap(<OrderSentScreen code="VV261008K7M2QX" reused={false} />);
    expect(await screen.findByText("Không tìm thấy đơn hàng")).toBeInTheDocument();
  });

  it("dùng lại đơn (?dung-lai=1) có thông báo", async () => {
    mocks.fetchOrder.mockResolvedValue(order());
    wrap(<OrderSentScreen code="VV261008K7M2QX" reused />);
    expect(await screen.findByText("Đơn này bạn đã gửi trước đó")).toBeInTheDocument();
  });

  it("huỷ đơn: hộp xác nhận -> POST cancel -> đổi sang trạng thái đã huỷ, bỏ hướng dẫn liên hệ", async () => {
    mocks.fetchOrder.mockResolvedValue(order());
    mocks.cancelOrder.mockResolvedValue(order({ status: "cancelled", status_reason: "user_cancelled", cancelled_at: "2026-10-08T11:00:00+07:00", can_cancel: false }));
    wrap(<OrderSentScreen code="VV261008K7M2QX" reused={false} />);
    await userEvent.click(await screen.findByRole("button", { name: "Huỷ đơn" }));
    expect(screen.getByText(/Bạn đã chuyển khoản cho đơn này/)).toBeInTheDocument();
    await userEvent.click(screen.getAllByRole("button", { name: "Huỷ đơn" }).at(-1) as HTMLElement);
    await waitFor(() => expect(mocks.cancelOrder).toHaveBeenCalledWith("VV261008K7M2QX"));
    expect((await screen.findAllByText(/Bạn đã huỷ đơn|Đã huỷ đơn/)).length).toBeGreaterThan(0);
    expect(screen.queryByRole("link", { name: /Gọi điện/ })).not.toBeInTheDocument();
  });
});

describe("Chi tiết đơn (AC10–AC14)", () => {
  it("admin_cancelled: hiện cancel_reason dạng text nhiều dòng, không HTML; không có nút huỷ", async () => {
    mocks.fetchOrder.mockResolvedValue(
      order({ status: "cancelled", status_reason: "admin_cancelled", cancel_reason: "Không liên hệ được\n<b>qua SĐT</b>", cancelled_at: "2026-10-09T09:00:00+07:00", can_cancel: false }),
    );
    const { container } = wrap(<OrderDetailScreen code="VV261008K7M2QX" />);
    expect(await screen.findByText("Quản trị viên đã huỷ đơn")).toBeInTheDocument();
    expect(container.querySelector("b")).toBeNull();
    expect(screen.getByText(/<b>qua SĐT<\/b>/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Huỷ đơn" })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Gọi điện/ })).not.toBeInTheDocument();
  });

  it("superseded có link tới replaced_by_code; coupon_code và ghi chú học sinh hiện", async () => {
    mocks.fetchOrder.mockResolvedValue(
      order({ status: "cancelled", status_reason: "superseded", replaced_by_code: "VV261009ZZZZZZ", cancelled_at: "2026-10-08T12:00:00+07:00", can_cancel: false, coupon_code: "HE2026", discount: 50000, customer_note: "Gọi sau 18h" }),
    );
    wrap(<OrderDetailScreen code="VV261008K7M2QX" />);
    expect(await screen.findByRole("link", { name: "Xem đơn VV261009ZZZZZZ" })).toHaveAttribute("href", "/tai-khoan/don-hang/VV261009ZZZZZZ");
    expect(screen.getByText(/Giảm giá \(HE2026\)/)).toBeInTheDocument();
    expect(screen.getByText("Gọi sau 18h")).toBeInTheDocument();
  });

  it("huỷ gặp 409 (QTV vừa duyệt): tải lại đơn, báo 'đơn vừa được duyệt', không thử lại", async () => {
    mocks.fetchOrder.mockResolvedValueOnce(order());
    mocks.cancelOrder.mockRejectedValueOnce(err(409, { message: "Đơn đã được duyệt", code: "ORDER_STATUS_CHANGED", errors: { status: "paid", status_reason: "manual_confirmed" } }));
    mocks.fetchOrder.mockResolvedValueOnce(order({ status: "paid", status_reason: "manual_confirmed", paid_at: "2026-10-08T19:58:00+07:00", can_cancel: false }));
    wrap(<OrderDetailScreen code="VV261008K7M2QX" />);
    await userEvent.click(await screen.findByRole("button", { name: "Huỷ đơn" }));
    await userEvent.click(screen.getAllByRole("button", { name: "Huỷ đơn" }).at(-1) as HTMLElement);
    expect(await screen.findByText("Không huỷ được: đơn vừa được duyệt")).toBeInTheDocument();
    expect(mocks.cancelOrder).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("button", { name: "Huỷ đơn" })).not.toBeInTheDocument();
  });

  it("huỷ lỗi mạng: lỗi trong hộp, hộp giữ mở để thử lại", async () => {
    mocks.fetchOrder.mockResolvedValue(order());
    mocks.cancelOrder.mockRejectedValueOnce(err(500, { message: "Lỗi máy chủ" }));
    wrap(<OrderDetailScreen code="VV261008K7M2QX" />);
    await userEvent.click(await screen.findByRole("button", { name: "Huỷ đơn" }));
    await userEvent.click(screen.getAllByRole("button", { name: "Huỷ đơn" }).at(-1) as HTMLElement);
    expect(await screen.findByText("Lỗi máy chủ")).toBeInTheDocument();
  });
});

describe("Đơn hàng của tôi (AC10)", () => {
  it("danh sách: nhãn trạng thái tiếng Việt, hạn chờ với đơn pending, tóm tắt khóa; link tới chi tiết", async () => {
    mocks.fetchOrders.mockResolvedValue({
      data: [listItem(), listItem({ code: "VV260925Q1ZD4H", status: "cancelled", status_reason: "expired", expires_at: "2026-09-28T21:03:00+07:00", can_cancel: false, items_count: 1, item_titles: ["Ôn thi vào 10"], discount: 0, total: 599000 })],
      meta: { current_page: 1, per_page: 10, total: 2, last_page: 1 },
    });
    wrap(<OrdersScreen page={1} />);
    expect(await screen.findByText("Chờ Quản trị viên duyệt")).toBeInTheDocument();
    expect(screen.getByText("Đã huỷ do quá hạn chờ")).toBeInTheDocument();
    expect(screen.getByText("Toán 9 nâng cao và 1 khóa khác")).toBeInTheDocument();
    expect(screen.getAllByText(/Hạn chờ duyệt:/)).toHaveLength(1); // chỉ đơn pending
    expect(screen.getByRole("link", { name: /VV261008K7M2QX/ })).toHaveAttribute("href", "/tai-khoan/don-hang/VV261008K7M2QX");
  });

  it("phân trang theo ?trang= và trạng thái rỗng", async () => {
    mocks.fetchOrders.mockResolvedValueOnce({ data: [listItem()], meta: { current_page: 1, per_page: 10, total: 25, last_page: 3 } });
    const { unmount } = wrap(<OrdersScreen page={1} />);
    expect(await screen.findByRole("link", { name: "Trang 2" })).toHaveAttribute("href", "/tai-khoan/don-hang?trang=2");
    unmount();
    mocks.fetchOrders.mockResolvedValueOnce({ data: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } });
    wrap(<OrdersScreen page={1} />);
    expect(await screen.findByText("Bạn chưa có đơn hàng nào")).toBeInTheDocument();
  });
});
