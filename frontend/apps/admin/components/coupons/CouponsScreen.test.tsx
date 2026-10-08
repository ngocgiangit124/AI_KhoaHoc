import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/coupons/api";
import type { Coupon, CouponPage } from "@/lib/coupons/types";
import { CouponsScreen } from "./CouponsScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const replace = vi.fn();
let search = "";
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
  usePathname: () => "/quan-tri/ma-giam-gia",
  useSearchParams: () => new URLSearchParams(search),
}));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/coupons/api");

const c = (id: number, code: string, over: Partial<Coupon> = {}): Coupon => ({
  id,
  code,
  name: null,
  discount_type: "percent",
  discount_value: 20,
  max_uses: 100,
  max_uses_per_user: 1,
  used_count: 45,
  valid_from: "2026-10-01T00:00:00+07:00",
  valid_until: "2026-12-31T23:59:00+07:00",
  status: "active",
  state: "active",
  is_restricted: false,
  courses_count: 0,
  subjects_count: 0,
  created_at: "2026-09-01T00:00:00+07:00",
  ...over,
});
const pageOf = (data: Coupon[], last = 1): CouponPage => ({ data, meta: { current_page: 1, per_page: 25, total: data.length, last_page: last }, links: { next: null, prev: null } });

function setUser(role: StaffUser["role"], permissions: StaffUser["permissions"] = null) {
  const user: StaffUser = { id: 1, name: "N", email: null, role, permissions, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}

beforeEach(() => {
  vi.resetAllMocks();
  replace.mockReset();
  search = "";
  setUser("quan_ly_trang");
});

describe("CouponsScreen", () => {
  it("hiện mã, giảm, phạm vi, lượt dùng 45/100 và trạng thái bằng chữ", async () => {
    vi.mocked(api.listCoupons).mockResolvedValue(
      pageOf([c(1, "TOAN2026", { name: "Mã toán" }), c(2, "ONTHI", { discount_type: "fixed_amount", discount_value: 100000, is_restricted: true, subjects_count: 1, state: "exhausted", max_uses: 45 })]),
    );
    render(<CouponsScreen />);
    expect(await screen.findByRole("link", { name: "TOAN2026" })).toHaveAttribute("href", "/quan-tri/ma-giam-gia/1");
    expect(screen.getByText("Mã toán")).toBeInTheDocument();
    expect(screen.getAllByText("100.000đ").length).toBeGreaterThan(0);
    expect(screen.getAllByText("1 chuyên đề").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Hết lượt").length).toBeGreaterThan(0);
    expect(screen.getByRole("progressbar", { name: "Lượt dùng mã TOAN2026" })).toHaveAttribute("aria-valuetext", "45/100");
    expect(vi.mocked(api.listCoupons).mock.calls[0]?.[0]).toMatchObject({ state: "", q: "", page: 1, perPage: 25 });
  });

  it("phạm vi trống (giới hạn nhưng 0 khóa, 0 chuyên đề) hiện nhãn 'Phạm vi trống'", async () => {
    vi.mocked(api.listCoupons).mockResolvedValue(pageOf([c(4, "TRONG", { is_restricted: true, courses_count: 0, subjects_count: 0 }), c(5, "DAY", { is_restricted: true, courses_count: 2, subjects_count: 0 })]));
    render(<CouponsScreen />);
    await screen.findByRole("link", { name: "TRONG" });
    expect(screen.getAllByText("Phạm vi trống")).toHaveLength(1);
    expect(screen.getAllByText("2 khóa").length).toBeGreaterThan(0);
  });

  it("AC6: tab trạng thái là liên kết trên URL; lọc từ URL gửi state lên API", async () => {
    search = "state=expired&q=he";
    vi.mocked(api.listCoupons).mockResolvedValue(pageOf([c(3, "HE2026", { state: "expired" })]));
    render(<CouponsScreen />);
    await screen.findByRole("link", { name: "HE2026" });
    expect(vi.mocked(api.listCoupons).mock.calls[0]?.[0]).toMatchObject({ state: "expired", q: "he" });
    expect(screen.getByRole("link", { name: "Hết hạn" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: "Hết lượt" })).toHaveAttribute("href", "/quan-tri/ma-giam-gia?q=he&state=exhausted");
  });

  it("rỗng hoàn toàn → gợi ý tạo mã đầu tiên; rỗng do lọc → 'không khớp bộ lọc'", async () => {
    vi.mocked(api.listCoupons).mockResolvedValue(pageOf([]));
    const { unmount } = render(<CouponsScreen />);
    expect(await screen.findByText("Chưa có mã giảm giá nào")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Tạo mã đầu tiên/ })).toHaveAttribute("href", "/quan-tri/ma-giam-gia/tao");
    unmount();
    search = "state=inactive";
    render(<CouponsScreen />);
    expect(await screen.findByText("Không có mã nào khớp bộ lọc")).toBeInTheDocument();
  });

  it("tìm kiếm: gõ xong chuyển sang URL ?q= (viết hoa không bắt buộc), về trang 1", async () => {
    vi.mocked(api.listCoupons).mockResolvedValue(pageOf([c(1, "TOAN2026")]));
    render(<CouponsScreen />);
    await screen.findByRole("link", { name: "TOAN2026" });
    await userEvent.type(screen.getByRole("searchbox", { name: "Tìm theo mã hoặc tên" }), "toan");
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/quan-tri/ma-giam-gia?q=toan", { scroll: false }));
  });

  it("lỗi tải: thông báo + Thử lại gọi lại API", async () => {
    vi.mocked(api.listCoupons).mockRejectedValueOnce(new ApiError(500, { message: "Lỗi máy chủ." })).mockResolvedValue(pageOf([c(1, "TOAN2026")]));
    render(<CouponsScreen />);
    expect(await screen.findByText("Không tải được danh sách mã giảm giá")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Thử lại" }));
    expect(await screen.findByRole("link", { name: "TOAN2026" })).toBeInTheDocument();
  });

  it("giáo viên: trang 403, không gọi API; API trả 403 cũng ra trang 403", async () => {
    setUser("giao_vien");
    const { unmount } = render(<CouponsScreen />);
    expect(screen.getByTestId("forbidden-view")).toBeInTheDocument();
    expect(api.listCoupons).not.toHaveBeenCalled();
    unmount();
    setUser("admin", { manage_coupons: true });
    vi.mocked(api.listCoupons).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    render(<CouponsScreen />);
    expect(await screen.findByTestId("forbidden-view")).toBeInTheDocument();
  });

  it("permission manage_coupons=false ghi đè vai trò", () => {
    setUser("quan_ly_trang", { manage_coupons: false });
    render(<CouponsScreen />);
    expect(screen.getByTestId("forbidden-view")).toBeInTheDocument();
  });
});
