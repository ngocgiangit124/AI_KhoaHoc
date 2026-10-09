import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/orders/api";
import { detailFixture } from "@/lib/orders/fixtures";
import { OrderDetailScreen } from "./OrderDetailScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: vi.fn(), push: vi.fn() }), usePathname: () => "/quan-tri/don-hang/VV261008K7M2QX" }));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
const refreshPending = vi.fn();
vi.mock("@/lib/orders/PendingOrders", () => ({ usePendingOrders: () => ({ count: 3, expiringSoon: 0, refresh: refreshPending }) }));
vi.mock("@/lib/orders/api", async (orig) => ({
  ...(await orig<typeof import("@/lib/orders/api")>()),
  getOrder: vi.fn(),
  approveOrder: vi.fn(),
  cancelOrder: vi.fn(),
  refundOrder: vi.fn(),
  addOrderNote: vi.fn(),
}));

const CODE = "VV261008K7M2QX";

function setUser(role: StaffUser["role"], permissions: StaffUser["permissions"] = null) {
  const user: StaffUser = { id: 1, name: "Lê Văn Hùng", email: null, role, permissions, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}
const renderScreen = () =>
  render(
    <ToastProvider>
      <OrderDetailScreen code={CODE} />
    </ToastProvider>,
  );
const apiErr = (status: number, code: string, errors?: Record<string, unknown>, message = "Lỗi từ máy chủ.") =>
  new ApiError(status, { message, code, ...(errors ? { errors: errors as Record<string, string[]> } : {}) });

beforeEach(() => {
  vi.resetAllMocks();
  setUser("quan_ly_trang");
  vi.mocked(api.getOrder).mockResolvedValue(detailFixture());
});

describe("OrderDetailScreen", () => {
  it("gọi chi tiết đúng 1 lần; hiện văn bản người dùng dạng TEXT (không HTML); tel/mailto mã hoá", async () => {
    vi.mocked(api.getOrder).mockResolvedValue(
      detailFixture({
        customer_note: '<img src=x onerror="alert(1)"> https://evil.example',
        notes: [{ id: 1, body: "<b>đậm</b>", author: { id: 3, name: "Trần Thị Bình" }, created_at: "2026-10-08T02:05:00Z" }],
      }),
    );
    const { container } = renderScreen();
    await screen.findByTestId("customer-note");
    expect(screen.getByRole("heading", { level: 1, name: /Đơn VV261008K7M2QX/ })).toBeInTheDocument();
    expect(screen.getByTestId("customer-note")).toHaveTextContent('<img src=x onerror="alert(1)"> https://evil.example');
    expect(container.querySelector("img")).toBeNull();
    expect(container.querySelector("b")).toBeNull();
    expect(screen.getByText("<b>đậm</b>")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /evil\.example/ })).toBeNull();
    expect(screen.getByRole("link", { name: "Gọi" })).toHaveAttribute("href", "tel:0901234123");
    expect(screen.getByRole("link", { name: "Gửi email" }).getAttribute("href")).toMatch(/^mailto:nguyenvanan%40gmail\.com\?subject=/);
    expect(screen.getByText("Chưa xác thực")).toBeInTheDocument(); // SĐT chưa xác thực
    expect(screen.getByText(/Lần xem thông tin liên hệ này đã được ghi vào nhật ký/)).toBeInTheDocument();
    expect(screen.getAllByText("Có khóa đã ngừng bán").length).toBeGreaterThan(0);
    expect(api.getOrder).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("button", { name: "Duyệt muộn" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Đánh dấu hoàn tiền" })).toBeNull();
  });

  it("duyệt: nút khoá tới khi tick 'Đã nhận đủ', bấm kép chỉ gửi 1 lần, dùng phản hồi 200 (không GET lại) và làm mới số đơn chờ", async () => {
    let done: (o: ReturnType<typeof detailFixture>) => void = () => {};
    vi.mocked(api.approveOrder).mockReturnValue(new Promise((r) => (done = r)));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Duyệt: đã nhận tiền" }));
    const dlg = screen.getByRole("dialog", { name: /Duyệt đơn VV261008K7M2QX/ });
    const confirm = within(dlg).getByRole("button", { name: "Duyệt đơn" });
    expect(confirm).toBeDisabled();
    expect(within(dlg).getByText("Tick ô này để bật nút duyệt.")).toBeInTheDocument();
    await userEvent.click(within(dlg).getByRole("checkbox", { name: /Đã nhận đủ 500\.000/ }));
    await userEvent.type(within(dlg).getByLabelText(/Mã giao dịch/), "FT26100812345");
    await userEvent.dblClick(confirm);
    expect(api.approveOrder).toHaveBeenCalledTimes(1);
    expect(api.approveOrder).toHaveBeenCalledWith(CODE, { late: false, payment_reference: "FT26100812345", note: "" });
    done(
      detailFixture({
        status: "paid",
        status_reason: "manual_confirmed",
        paid_at: "2026-10-09T05:00:00Z",
        confirmed_by: { id: 1, name: "Lê Văn Hùng" },
        approval: { can_approve: false, can_cancel: false },
      }),
    );
    expect(await screen.findByText("Đã duyệt đơn")).toBeInTheDocument();
    expect(screen.getByTestId("confirmed-by")).toHaveTextContent("Lê Văn Hùng");
    expect(screen.getByTestId("confirmed-by")).toHaveTextContent("lúc 12:00 09/10/2026");
    expect(screen.getByRole("button", { name: "Đánh dấu hoàn tiền" })).toBeInTheDocument();
    expect(api.getOrder).toHaveBeenCalledTimes(1);
    expect(refreshPending).toHaveBeenCalled();
  });

  it("409 ALREADY_PROCESSED khi duyệt: đóng hộp, tải lại đơn 1 lần, báo ai đã duyệt, không tự thử lại", async () => {
    vi.mocked(api.approveOrder).mockRejectedValue(apiErr(409, "ALREADY_PROCESSED", { status: "paid", status_reason: "manual_confirmed" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Duyệt: đã nhận tiền" }));
    const dlg = screen.getByRole("dialog", { name: /Duyệt đơn/ });
    await userEvent.click(within(dlg).getByRole("checkbox", { name: /Đã nhận đủ/ }));
    vi.mocked(api.getOrder).mockResolvedValue(
      detailFixture({ status: "paid", status_reason: "manual_confirmed", paid_at: "2026-10-08T12:59:00Z", confirmed_by: { id: 2, name: "Đỗ Thị Mai" }, approval: { can_approve: false, can_cancel: false } }),
    );
    await userEvent.click(within(dlg).getByRole("button", { name: "Duyệt đơn" }));
    const notice = await screen.findByTestId("order-notice");
    expect(notice).toHaveTextContent("Đơn đã được người khác xử lý");
    expect(notice).toHaveTextContent("Đỗ Thị Mai đã duyệt lúc 19:59 08/10/2026");
    expect(notice.querySelector('[role="alert"]')).not.toBeNull();
    expect(api.getOrder).toHaveBeenCalledTimes(2);
    expect(api.approveOrder).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("button", { name: "Duyệt: đã nhận tiền" })).toBeNull();
  });

  it("409 khi học sinh vừa tự huỷ: báo rõ và hiện ngay nút Duyệt muộn", async () => {
    vi.mocked(api.approveOrder).mockRejectedValue(apiErr(409, "ORDER_STATUS_CHANGED", { status: "cancelled", status_reason: "user_cancelled", can_approve_late: true }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Duyệt: đã nhận tiền" }));
    const dlg = screen.getByRole("dialog", { name: /Duyệt đơn/ });
    await userEvent.click(within(dlg).getByRole("checkbox", { name: /Đã nhận đủ/ }));
    vi.mocked(api.getOrder).mockResolvedValue(
      detailFixture({
        status: "cancelled",
        status_reason: "user_cancelled",
        cancelled_at: "2026-10-08T12:59:00Z",
        status_logs: [{ from: "pending", to: "cancelled", reason: "user_cancelled", actor_type: "user", actor: { id: 501, name: "Nguyễn Văn An" }, meta: {}, created_at: "2026-10-08T12:59:00Z" }],
        approval: { can_approve: false, can_cancel: false, can_approve_late: true, approval_window_until: "2026-11-07T12:59:00Z" },
      }),
    );
    await userEvent.click(within(dlg).getByRole("button", { name: "Duyệt đơn" }));
    const notice = await screen.findByTestId("order-notice");
    expect(notice).toHaveTextContent("Chưa duyệt: đơn vừa đổi trạng thái");
    expect(notice).toHaveTextContent("Học sinh đã tự huỷ đơn lúc 19:59 08/10/2026");
    expect(await screen.findByRole("button", { name: "Duyệt muộn" })).toBeInTheDocument();
  });

  it("409 COURSE_UNAVAILABLE: báo khóa đã xoá, đơn giữ nguyên", async () => {
    vi.mocked(api.approveOrder).mockRejectedValue(apiErr(409, "COURSE_UNAVAILABLE", { courses: [{ id: 15, title: "Ngữ văn 9" }] }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Duyệt: đã nhận tiền" }));
    const dlg = screen.getByRole("dialog", { name: /Duyệt đơn/ });
    await userEvent.click(within(dlg).getByRole("checkbox", { name: /Đã nhận đủ/ }));
    await userEvent.click(within(dlg).getByRole("button", { name: "Duyệt đơn" }));
    const notice = await screen.findByTestId("order-notice");
    expect(notice).toHaveTextContent("Không duyệt được: có khóa đã bị xoá");
    expect(notice).toHaveTextContent("“Ngữ văn 9”");
    expect(screen.getByRole("button", { name: "Duyệt: đã nhận tiền" })).toBeInTheDocument();
  });

  it("duyệt muộn: 2 bước, hiện late_approval_warnings, gửi late:true", async () => {
    vi.mocked(api.getOrder).mockResolvedValue(
      detailFixture({
        status: "cancelled",
        status_reason: "expired",
        cancelled_at: "2026-10-08T12:59:00Z",
        cancel_reason: null,
        approval: {
          can_approve: false,
          can_cancel: false,
          can_approve_late: true,
          approval_window_until: "2026-11-07T12:59:00Z",
          warnings: [],
          late_approval_warnings: [{ code: "COUPON_OVER_LIMIT", coupon_code: "HE2026" }, { code: "ALREADY_OWNED", course_id: 12, title: "Toán 9 nâng cao" }],
        },
      }),
    );
    vi.mocked(api.approveOrder).mockResolvedValue(detailFixture({ status: "paid", needs_review: true, needs_review_reasons: ["late_payment"], approval: { can_approve: false, can_cancel: false } }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Duyệt muộn" }));
    const dlg = screen.getByRole("dialog", { name: /Duyệt muộn đơn/ });
    const box = within(dlg).getByTestId("late-warning-box");
    expect(box).toHaveTextContent("Đơn đã huỷ lúc 19:59 08/10/2026");
    expect(box).toHaveTextContent("Mã giảm giá HE2026 đã hết lượt");
    expect(box).toHaveTextContent("đã sở hữu khóa “Toán 9 nâng cao”");
    expect(within(dlg).getByRole("button", { name: "Tiếp tục" })).toBeDisabled();
    await userEvent.click(within(dlg).getByRole("checkbox", { name: /Đã nhận đủ/ }));
    await userEvent.click(within(dlg).getByRole("button", { name: "Tiếp tục" }));
    expect(api.approveOrder).not.toHaveBeenCalled();
    const dlg2 = screen.getByRole("dialog", { name: /Xác nhận lần 2/ });
    await userEvent.click(within(dlg2).getByRole("button", { name: "Xác nhận duyệt muộn" }));
    await waitFor(() => expect(api.approveOrder).toHaveBeenCalledWith(CODE, { late: true, payment_reference: "", note: "" }));
    expect(await screen.findByText("Đã duyệt muộn")).toBeInTheDocument();
    expect(screen.getAllByText("Cần xem lại").length).toBeGreaterThan(0);
  });

  it("huỷ: lý do gửi học sinh bắt buộc (lỗi dưới ô), ghi chú nội bộ tách riêng", async () => {
    vi.mocked(api.cancelOrder).mockResolvedValue(detailFixture({ status: "cancelled", status_reason: "admin_cancelled", cancel_reason: "Không liên lạc được", approval: { can_approve: false, can_cancel: false } }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Huỷ đơn" }));
    const dlg = screen.getByRole("dialog", { name: /Huỷ đơn VV261008K7M2QX/ });
    await userEvent.click(within(dlg).getByRole("button", { name: "Huỷ đơn" }));
    expect(within(dlg).getByText(/ít nhất 5 ký tự/)).toBeInTheDocument();
    expect(api.cancelOrder).not.toHaveBeenCalled();
    await userEvent.type(within(dlg).getByLabelText(/Lý do gửi học sinh/), "Không liên lạc được");
    await userEvent.type(within(dlg).getByLabelText(/Ghi chú nội bộ/), "Gọi 3 lần");
    await userEvent.click(within(dlg).getByRole("button", { name: "Huỷ đơn" }));
    await waitFor(() => expect(api.cancelOrder).toHaveBeenCalledWith(CODE, { reason: "Không liên lạc được", note: "Gọi 3 lần" }));
    expect(await screen.findByText("Đã huỷ đơn")).toBeInTheDocument();
  });

  it("huỷ: 422 của server hiện dưới đúng ô lý do", async () => {
    vi.mocked(api.cancelOrder).mockRejectedValue(apiErr(422, "VALIDATION_ERROR", { reason: ["Lý do không được chứa mã HTML."] }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Huỷ đơn" }));
    const dlg = screen.getByRole("dialog", { name: /Huỷ đơn VV/ });
    await userEvent.type(within(dlg).getByLabelText(/Lý do gửi học sinh/), "<b>không</b>");
    await userEvent.click(within(dlg).getByRole("button", { name: "Huỷ đơn" }));
    expect(await within(dlg).findByText("Lý do không được chứa mã HTML.")).toBeInTheDocument();
    expect(screen.getByRole("dialog", { name: /Huỷ đơn VV/ })).toHaveAttribute("open");
  });

  it("đơn đã duyệt: có Đánh dấu hoàn tiền (phải tick) và gửi confirm", async () => {
    vi.mocked(api.getOrder).mockResolvedValue(detailFixture({ status: "paid", status_reason: "manual_confirmed", paid_at: "2026-10-08T12:59:00Z", confirmed_by: { id: 2, name: "Mai" }, approval: { can_approve: false, can_cancel: false } }));
    vi.mocked(api.refundOrder).mockResolvedValue(detailFixture({ status: "refunded", approval: { can_approve: false, can_cancel: false }, refunded_at: "2026-10-09T05:00:00Z", refunded_by: { id: 1, name: "Lê Văn Hùng" } }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Đánh dấu hoàn tiền" }));
    const dlg = screen.getByRole("dialog", { name: /hoàn tiền đơn/ });
    expect(within(dlg).getByRole("button", { name: "Xác nhận hoàn tiền" })).toBeDisabled();
    await userEvent.click(within(dlg).getByRole("checkbox", { name: /Tôi đã hoàn/ }));
    await userEvent.click(within(dlg).getByRole("button", { name: "Xác nhận hoàn tiền" }));
    await waitFor(() => expect(api.refundOrder).toHaveBeenCalledWith(CODE, ""));
    expect(await screen.findByText("Đã đánh dấu hoàn tiền")).toBeInTheDocument();
  });

  it("ghi chú nội bộ: thêm, hiện ngay (mới nhất trên), giữ nội dung khi lỗi", async () => {
    vi.mocked(api.addOrderNote).mockRejectedValueOnce(apiErr(422, "VALIDATION_ERROR", { body: ["Ghi chú không hợp lệ."] }));
    renderScreen();
    const box = await screen.findByLabelText(/Thêm ghi chú/);
    await userEvent.type(box, "Hẹn chiều nay");
    await userEvent.click(screen.getByRole("button", { name: "Lưu ghi chú" }));
    expect(await screen.findByText("Ghi chú không hợp lệ.")).toBeInTheDocument();
    expect(box).toHaveValue("Hẹn chiều nay");
    vi.mocked(api.addOrderNote).mockResolvedValueOnce({ id: 99, body: "Hẹn chiều nay", author: { id: 1, name: "Lê Văn Hùng" }, created_at: "2026-10-09T05:00:00Z" });
    await userEvent.click(screen.getByRole("button", { name: "Lưu ghi chú" }));
    const items = await screen.findAllByRole("listitem");
    expect(items.map((li) => li.textContent).join("|")).toMatch(/Hẹn chiều nay.*Đã gọi 9h/);
    expect(box).toHaveValue("");
    expect(api.getOrder).toHaveBeenCalledTimes(1);
  });

  it("đơn của tài khoản đã xoá: không có liên hệ, không có Duyệt muộn; thông báo rõ", async () => {
    vi.mocked(api.getOrder).mockResolvedValue(
      detailFixture({
        status: "cancelled",
        status_reason: "account_deleted",
        cancelled_at: "2026-10-08T12:59:00Z",
        student: { id: 501, name: "Tài khoản đã xoá", email: null, phone: null, is_deleted: true, account_status: "active" },
        approval: { can_approve: false, can_cancel: false, can_approve_late: false, approval_window_until: "2026-11-07T12:59:00Z", warnings: [] },
      }),
    );
    renderScreen();
    expect(await screen.findByText("Tài khoản đã xoá (ẩn danh hoá).")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Gọi" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Duyệt muộn" })).toBeNull();
    expect(screen.getByText("Tài khoản học sinh đã xoá nên không duyệt muộn được.")).toBeInTheDocument();
  });

  it("404 → không tìm thấy; giáo viên → 403 và không gọi API", async () => {
    vi.mocked(api.getOrder).mockRejectedValue(apiErr(404, "NOT_FOUND"));
    const { unmount } = renderScreen();
    expect(await screen.findByText("Không tìm thấy đơn hàng")).toBeInTheDocument();
    unmount();
    vi.mocked(api.getOrder).mockClear();
    setUser("giao_vien");
    renderScreen();
    expect(screen.getByTestId("forbidden-view")).toBeInTheDocument();
    expect(api.getOrder).not.toHaveBeenCalled();
  });

  it("lỗi mạng/lệch hợp đồng: báo lỗi và cho tải lại", async () => {
    vi.mocked(api.getOrder).mockRejectedValueOnce(new api.ContractError("đơn hàng"));
    renderScreen();
    expect(await screen.findByText("Không tải được đơn hàng")).toBeInTheDocument();
    vi.mocked(api.getOrder).mockResolvedValueOnce(detailFixture());
    await userEvent.click(screen.getByRole("button", { name: "Tải lại" }));
    expect(await screen.findByRole("button", { name: "Duyệt: đã nhận tiền" })).toBeInTheDocument();
  });
});
