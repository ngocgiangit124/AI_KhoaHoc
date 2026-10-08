import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/coupons/api";
import * as options from "@/lib/coupons/options";
import type { Coupon } from "@/lib/coupons/types";
import { CouponEditScreen } from "./CouponEditScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push, replace: vi.fn() }) }));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/coupons/api");
vi.mock("@/lib/coupons/options");

const coupon = (over: Partial<Coupon> = {}): Coupon => ({
  id: 7,
  code: "TOAN2026",
  name: null,
  discount_type: "percent",
  discount_value: 20,
  max_uses: 100,
  max_uses_per_user: 1,
  used_count: 0,
  valid_from: "2026-10-01T00:00:00+07:00",
  valid_until: null,
  status: "active",
  state: "active",
  is_restricted: true,
  courses: [{ id: 11, title: "Hình học 9" }],
  subjects: [{ id: 1, name: "Đại số" }],
  created_at: "2026-09-01T00:00:00+07:00",
  ...over,
});

function setUser(role: StaffUser["role"]) {
  const user: StaffUser = { id: 1, name: "N", email: null, role, permissions: null, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}
const renderScreen = () =>
  render(
    <ToastProvider>
      <CouponEditScreen id={7} />
    </ToastProvider>,
  );

beforeEach(() => {
  vi.resetAllMocks();
  push.mockReset();
  setUser("admin");
  vi.mocked(options.listAllSubjects).mockResolvedValue([{ id: 1, name: "Đại số", hidden: false }]);
  vi.mocked(options.searchCourses).mockResolvedValue([{ id: 11, title: "Hình học 9", status: "published" }]);
  vi.mocked(options.cheapestPublishedPrice).mockResolvedValue(null);
});

describe("CouponEditScreen", () => {
  it("AC4/AC8: hiện lượt dùng, trạng thái 'Hết lượt', danh sách phạm vi đã lưu", async () => {
    vi.mocked(api.getCoupon).mockResolvedValue(coupon({ used_count: 100, state: "exhausted" }));
    renderScreen();
    expect(await screen.findByRole("heading", { level: 1, name: /TOAN2026/ })).toBeInTheDocument();
    expect(screen.getAllByText("Hết lượt").length).toBeGreaterThan(0);
    expect(screen.getByRole("progressbar", { name: "Lượt đã dùng" })).toHaveAttribute("aria-valuetext", "100/100");
    expect(screen.getByText("Chuyên đề (1)")).toBeInTheDocument();
    expect(screen.getByText("Khóa học (1)")).toBeInTheDocument();
    // Mã cũ có cả khóa lẫn chuyên đề: form hiện lựa chọn kết hợp để không mất dữ liệu.
    expect(screen.getByRole("radio", { name: "Kết hợp chuyên đề và khóa học" })).toBeChecked();
    expect(screen.getByRole("button", { name: "Xoá mã" })).toBeDisabled();
  });

  it("AC3: vô hiệu hoá có hộp xác nhận, gọi một lần dù bấm kép, huy hiệu đổi ngay", async () => {
    vi.mocked(api.getCoupon).mockResolvedValue(coupon());
    let done: (c: Coupon) => void = () => {};
    vi.mocked(api.setCouponActive).mockReturnValue(new Promise((r) => (done = r)));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Vô hiệu hoá" }));
    expect(await screen.findByText("Vô hiệu hoá mã TOAN2026?")).toBeInTheDocument();
    const confirm = screen.getAllByRole("button", { name: "Vô hiệu hoá" }).at(-1)!;
    await userEvent.dblClick(confirm);
    expect(api.setCouponActive).toHaveBeenCalledTimes(1);
    expect(api.setCouponActive).toHaveBeenCalledWith(7, false);
    done(coupon({ status: "inactive", state: "inactive" }));
    expect(await screen.findByText("Đã vô hiệu hoá mã giảm giá")).toBeInTheDocument();
    expect(screen.getAllByText("Đã tắt").length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: "Bật lại mã" })).toBeInTheDocument();
  });

  it("mã giới hạn phạm vi nhưng phạm vi rỗng: cảnh báo; mã có phạm vi thì không", async () => {
    vi.mocked(api.getCoupon).mockResolvedValue(coupon({ courses: [], subjects: [] }));
    const a = renderScreen();
    expect(await screen.findByText("Mã không còn áp dụng cho khóa nào vì phạm vi đã bị xoá. Hãy chọn lại phạm vi.")).toBeInTheDocument();
    a.unmount();
    vi.mocked(api.getCoupon).mockResolvedValue(coupon());
    renderScreen();
    await screen.findByRole("heading", { level: 1, name: /TOAN2026/ });
    expect(screen.queryByText(/phạm vi đã bị xoá/)).not.toBeInTheDocument();
  });

  it("xoá mã chưa dùng: xác nhận rồi về danh sách", async () => {
    vi.mocked(api.getCoupon).mockResolvedValue(coupon());
    vi.mocked(api.deleteCoupon).mockResolvedValue();
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Xoá mã" }));
    await userEvent.click(screen.getAllByRole("button", { name: "Xoá mã" }).at(-1)!);
    await waitFor(() => expect(api.deleteCoupon).toHaveBeenCalledWith(7));
    await waitFor(() => expect(push).toHaveBeenCalledWith("/quan-tri/ma-giam-gia"));
  });

  it("409 COUPON_IN_USE khi xoá: hộp thoại giải thích, tải lại dữ liệu, không rời trang", async () => {
    vi.mocked(api.getCoupon).mockResolvedValueOnce(coupon()).mockResolvedValue(coupon({ used_count: 1 }));
    vi.mocked(api.deleteCoupon).mockRejectedValue(new ApiError(409, { message: "x", code: "COUPON_IN_USE" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Xoá mã" }));
    await userEvent.click(screen.getAllByRole("button", { name: "Xoá mã" }).at(-1)!);
    expect(await screen.findByText("Không thể xoá mã giảm giá")).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
    await waitFor(() => expect(api.getCoupon).toHaveBeenCalledTimes(2));
  });

  it("404 khi mở: trang không tìm thấy; 403: trang không có quyền; lỗi khác: Thử lại", async () => {
    vi.mocked(api.getCoupon).mockRejectedValue(new ApiError(404, { message: "x" }));
    const a = renderScreen();
    expect(await screen.findByTestId("coupon-not-found")).toBeInTheDocument();
    a.unmount();
    vi.mocked(api.getCoupon).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    const b = renderScreen();
    expect(await screen.findByTestId("forbidden-view")).toBeInTheDocument();
    b.unmount();
    vi.mocked(api.getCoupon).mockRejectedValueOnce(new ApiError(500, { message: "Lỗi máy chủ." })).mockResolvedValue(coupon());
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Thử lại" }));
    expect(await screen.findByRole("heading", { level: 1, name: /TOAN2026/ })).toBeInTheDocument();
  });

  it("giáo viên: 403, không gọi API", () => {
    setUser("giao_vien");
    renderScreen();
    expect(screen.getByTestId("forbidden-view")).toBeInTheDocument();
    expect(api.getCoupon).not.toHaveBeenCalled();
  });
});
