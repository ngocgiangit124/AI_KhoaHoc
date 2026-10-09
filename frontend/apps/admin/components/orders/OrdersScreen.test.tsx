import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/orders/api";
import { orderPageSchema, type OrderPage } from "@/lib/orders/schemas";
import { OrdersScreen } from "./OrdersScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const replace = vi.fn();
let search = "";
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
  usePathname: () => "/quan-tri/don-hang",
  useSearchParams: () => new URLSearchParams(search),
}));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/orders/PendingOrders", () => ({ usePendingOrders: () => ({ count: 6, expiringSoon: 1, refresh: vi.fn() }) }));
vi.mock("@/lib/orders/api", async (orig) => ({ ...(await orig<typeof import("@/lib/orders/api")>()), listOrders: vi.fn() }));

const row = (code: string, over: Record<string, unknown> = {}) => ({
  code,
  status: "pending",
  payment_method: "manual",
  items_count: 2,
  first_item_title: "Toán 9 nâng cao",
  subtotal: 550000,
  discount: 50000,
  total: 500000,
  created_at: "2026-10-08T10:15:00+07:00",
  expires_at: new Date(Date.now() + 5 * 3_600_000).toISOString(),
  expiring_soon: true,
  student: { id: 1, name: "Nguyễn Văn An", email_masked: "n***@gmail.com", phone_masked: "******4123", is_deleted: false },
  ...over,
});
const page = (data: unknown[], meta: Record<string, unknown> = {}): OrderPage =>
  orderPageSchema.parse({ data, meta: { per_page: 25, next_cursor: null, prev_cursor: null, total: data.length, ...meta } });

function setUser(role: StaffUser["role"]) {
  const user: StaffUser = { id: 1, name: "N", email: null, role, permissions: null, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}

beforeEach(() => {
  vi.resetAllMocks();
  search = "";
  setUser("quan_ly_trang");
});

describe("OrdersScreen", () => {
  it("mặc định tab Chờ duyệt: gọi API với query của tab, hiện 'Sắp hết hạn' và số đơn chờ ở tab", async () => {
    vi.mocked(api.listOrders).mockResolvedValue(page([row("VV1")]));
    render(<OrdersScreen />);
    expect(await screen.findByText("Nguyễn Văn An")).toBeInTheDocument();
    expect(vi.mocked(api.listOrders).mock.calls[0]?.[0]).toMatchObject({ tab: "cho-duyet", cursor: "" });
    expect(screen.getAllByText("Sắp hết hạn").length).toBeGreaterThan(0);
    expect(screen.getAllByText(/Còn [45] giờ/).length).toBeGreaterThan(0);
    expect(screen.getByText(/n\*\*\*@gmail\.com/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Chờ duyệt/ })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: /Chờ duyệt/ })).toHaveTextContent("6");
    expect(screen.getByRole("link", { name: "Xử lý đơn VV1" })).toHaveAttribute("href", "/quan-tri/don-hang/VV1");
    expect(screen.queryByLabelText(/Từ ngày/)).toBeNull(); // không cần khoảng ngày
  });

  it("đơn chưa sắp hết hạn không có nhãn; rỗng → thông báo riêng", async () => {
    vi.mocked(api.listOrders).mockResolvedValue(page([row("VV1", { expiring_soon: false })]));
    const { unmount } = render(<OrdersScreen />);
    await screen.findByText("Nguyễn Văn An");
    expect(screen.queryByText("Sắp hết hạn")).toBeNull();
    unmount();
    vi.mocked(api.listOrders).mockResolvedValue(page([]));
    render(<OrdersScreen />);
    expect(await screen.findByText("Không có đơn nào đang chờ duyệt")).toBeInTheDocument();
  });

  it("tab khác: bắt buộc khoảng ngày, có phân trang cursor Trước/Sau theo URL, tổng 'Khoảng N đơn'", async () => {
    search = "tab=da-thanh-toan&from=2026-09-01&to=2026-10-01&cursor=CUR1";
    vi.mocked(api.listOrders).mockResolvedValue(
      page([row("VV2", { status: "paid", status_reason: "manual_confirmed", expires_at: null, expiring_soon: false, confirmed_by: { id: 2, name: "Mai" } })], { next_cursor: "CUR2", prev_cursor: "CUR0", total: 41 }),
    );
    render(<OrdersScreen />);
    expect(await screen.findByText("Khoảng 41 đơn khớp bộ lọc")).toBeInTheDocument();
    expect(vi.mocked(api.listOrders).mock.calls[0]?.[0]).toMatchObject({ tab: "da-thanh-toan", from: "2026-09-01", to: "2026-10-01", cursor: "CUR1" });
    const nav = screen.getByRole("navigation", { name: "Phân trang đơn hàng" });
    expect(within(nav).getByRole("link", { name: /Trang sau/ }).getAttribute("href")).toContain("cursor=CUR2");
    expect(within(nav).getByRole("link", { name: /Trang trước/ }).getAttribute("href")).toContain("cursor=CUR0");
    expect(screen.getAllByText("Đã duyệt").length).toBeGreaterThan(0);
  });

  it("khoảng ngày quá 366 ngày: báo lỗi, KHÔNG gọi API", async () => {
    search = "tab=tat-ca&from=2024-01-01&to=2026-01-01";
    render(<OrdersScreen />);
    expect(await screen.findByText(/Khoảng ngày tối đa 366 ngày\.$/, { selector: "p[role=alert]" })).toBeInTheDocument();
    expect(api.listOrders).not.toHaveBeenCalled();
  });

  it("lọc: gửi form tab Tất cả → URL mới (reset cursor), không tự gọi API theo từng phím", async () => {
    search = "tab=tat-ca&from=2026-09-01&to=2026-10-01&cursor=OLD";
    vi.mocked(api.listOrders).mockResolvedValue(page([]));
    render(<OrdersScreen />);
    await screen.findByText("Không tìm thấy đơn phù hợp với bộ lọc");
    await userEvent.type(screen.getByLabelText(/Mã đơn, tên, email hoặc SĐT/), "a@b.vn");
    await userEvent.selectOptions(screen.getByLabelText("Trạng thái"), "paid");
    await userEvent.click(screen.getByRole("checkbox", { name: /Cần xem lại/ }));
    expect(api.listOrders).toHaveBeenCalledTimes(1);
    await userEvent.click(screen.getByRole("button", { name: "Lọc" }));
    const url = replace.mock.calls[0]?.[0] as string;
    expect(url).toContain("tab=tat-ca");
    expect(url).toContain("q=a%40b.vn");
    expect(url).toContain("status=paid");
    expect(url).toContain("review=1");
    expect(url).not.toContain("cursor");
  });

  it("429 khi tìm theo email/SĐT: báo giới hạn kèm số giây, có Tải lại", async () => {
    search = "q=a%40b.vn";
    vi.mocked(api.listOrders).mockRejectedValue(new ApiError(429, { message: "x", code: "TOO_MANY_ATTEMPTS" }, 20));
    render(<OrdersScreen />);
    expect(await screen.findByText(/30 lần\/phút.*20 giây/)).toBeInTheDocument();
    vi.mocked(api.listOrders).mockResolvedValue(page([row("VV1")]));
    await userEvent.click(screen.getByRole("button", { name: "Tải lại" }));
    await waitFor(() => expect(api.listOrders).toHaveBeenCalledTimes(2));
  });

  it("giáo viên → 403, không gọi API; API trả 403 cũng ra trang không có quyền", async () => {
    setUser("giao_vien");
    const { unmount } = render(<OrdersScreen />);
    expect(screen.getByTestId("forbidden-view")).toBeInTheDocument();
    expect(api.listOrders).not.toHaveBeenCalled();
    unmount();
    setUser("admin");
    vi.mocked(api.listOrders).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    render(<OrdersScreen />);
    expect(await screen.findByTestId("forbidden-view")).toBeInTheDocument();
  });
});
