import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/audit/api";
import * as staffApi from "@/lib/staff/api";
import type { AuditLog } from "@/lib/audit/schemas";
import { AuditLogScreen } from "./AuditLogScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const replace = vi.fn();
let search = "";
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
  usePathname: () => "/quan-tri/nhat-ky",
  useSearchParams: () => new URLSearchParams(search),
}));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/audit/api", async (orig) => ({ ...(await orig<typeof import("@/lib/audit/api")>()), listAuditLogs: vi.fn() }));
vi.mock("@/lib/staff/api");

const log = (id: number, over: Partial<AuditLog> = {}): AuditLog => ({
  id,
  action: "order.manual_approve",
  actor_id: 3,
  actor_role: "admin",
  actor_name: "Trần Bình",
  subject_type: "App\\Models\\Order",
  subject_id: 9,
  changes: { late: false, note: "x".repeat(300) },
  ip: "10.0.0.1",
  user_agent: "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/141.0.0.0 Safari/537.36",
  created_at: "2026-10-09T01:02:03Z",
  ...over,
});

function setUser(role: StaffUser["role"], permissions: StaffUser["permissions"] = null) {
  const user: StaffUser = { id: 1, name: "N", email: null, role, permissions, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}

beforeEach(() => {
  vi.resetAllMocks();
  replace.mockReset();
  search = "";
  setUser("admin");
  vi.mocked(staffApi.listStaff).mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1 }, links: { next: null, prev: null } });
});

describe("AuditLogScreen", () => {
  it("hiện giờ VN, người làm + vai trò, nhãn hành động, đối tượng, IP; chỉ đọc (không nút sửa/xoá)", async () => {
    vi.mocked(api.listAuditLogs).mockResolvedValue({ data: [log(1), log(2, { action: "foo.bar", actor_id: null, actor_role: "cli", actor_name: null, subject_type: null, subject_id: null })], hasNext: false });
    render(<AuditLogScreen />);
    const table = await screen.findByRole("table");
    expect(await within(table).findAllByText("Duyệt đơn")).not.toHaveLength(0);
    expect(within(table).getAllByText("09/10/2026")).toHaveLength(2);
    expect(within(table).getAllByText("08:02:03")).toHaveLength(2);
    expect(within(table).getAllByText("Lệnh hệ thống").length).toBeGreaterThan(0);
    expect(within(table).getByText("foo.bar")).toBeInTheDocument();
    expect(within(table).getAllByText("10.0.0.1").length).toBeGreaterThan(0);
    expect(screen.queryByRole("button", { name: /sửa|xoá|xóa/i })).toBeNull();
    // mặc định gọi API với khoảng 7 ngày, trang 1
    expect(vi.mocked(api.listAuditLogs).mock.calls[0]?.[0]).toMatchObject({ page: 1, perPage: 25, from: null });
  });

  it("hộp chi tiết: key–value dạng text, giá trị dài cắt + Xem thêm, đóng được", async () => {
    vi.mocked(api.listAuditLogs).mockResolvedValue({ data: [log(1, { changes: { note: "<img src=x onerror=alert(1)>" + "y".repeat(300) } })], hasNext: false });
    render(<AuditLogScreen />);
    await userEvent.click(await screen.findByRole("button", { name: /Xem chi tiết/ }));
    const dlg = await screen.findByRole("dialog");
    const changes = within(dlg).getByTestId("audit-changes");
    expect(changes.querySelector("img")).toBeNull();
    expect(changes.textContent).toContain("<img src=x");
    expect(changes.textContent).toContain("…");
    await userEvent.click(within(dlg).getByRole("button", { name: "Xem thêm" }));
    expect(within(dlg).getByRole("button", { name: "Thu gọn" })).toBeInTheDocument();
    expect(within(dlg).getByText("Chrome 141 · macOS")).toBeInTheDocument();
  });

  it("đóng hộp chi tiết (nút Đóng) trả focus về nút Chi tiết đã mở (WCAG 2.4.3)", async () => {
    vi.mocked(api.listAuditLogs).mockResolvedValue({ data: [log(1)], hasNext: false });
    render(<AuditLogScreen />);
    const btn = await screen.findByRole("button", { name: /Xem chi tiết/ });
    await userEvent.click(btn);
    const dlg = await screen.findByRole("dialog");
    await userEvent.click(within(dlg).getAllByRole("button", { name: "Đóng" }).at(-1)!);
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    await waitFor(() => expect(btn).toHaveFocus());
  });

  it("phân trang theo URL: Trang sau chỉ khi hasNext; Trang trước từ trang 2", async () => {
    search = "page=2";
    vi.mocked(api.listAuditLogs).mockResolvedValue({ data: [log(1)], hasNext: true });
    render(<AuditLogScreen />);
    const nav = await screen.findByRole("navigation", { name: "Phân trang nhật ký" });
    await waitFor(() => expect(within(nav).getByRole("link", { name: /Trang sau/ })).toHaveAttribute("href", "/quan-tri/nhat-ky?page=3"));
    expect(within(nav).getByRole("link", { name: /Trang trước/ })).toHaveAttribute("href", "/quan-tri/nhat-ky");
  });

  it("áp bộ lọc: ghi lên URL (action, actor_id, loại + id đối tượng, khoảng ngày)", async () => {
    vi.mocked(api.listAuditLogs).mockResolvedValue({ data: [], hasNext: false });
    vi.mocked(staffApi.listStaff).mockRejectedValue(new Error("x"));
    render(<AuditLogScreen />);
    await screen.findByText(/Chưa có nhật ký/);
    await userEvent.type(screen.getByLabelText("Hoặc nhập mã hành động"), "order.refund");
    await userEvent.selectOptions(screen.getByLabelText("Loại đối tượng"), "App\\Models\\Order");
    await userEvent.type(screen.getByLabelText("Mã số đối tượng"), "9");
    await userEvent.type(screen.getByLabelText("Người làm"), "3");
    await userEvent.click(screen.getByRole("button", { name: "Lọc" }));
    const url = replace.mock.calls.at(-1)?.[0] as string;
    const p = new URLSearchParams(url.split("?")[1]);
    expect(p.get("action")).toBe("order.refund");
    expect(p.get("subject_type")).toBe("App\\Models\\Order");
    expect(p.get("subject_id")).toBe("9");
    expect(p.get("actor_id")).toBe("3");
  });

  it("khoảng ngày ngược: báo lỗi, không đổi URL", async () => {
    search = "from=2026-10-05&to=2026-10-08";
    vi.mocked(api.listAuditLogs).mockResolvedValue({ data: [], hasNext: false });
    render(<AuditLogScreen />);
    const from = await screen.findByLabelText("Từ ngày");
    await userEvent.clear(from);
    await userEvent.type(from, "2026-10-09");
    await userEvent.click(screen.getByRole("button", { name: "Lọc" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(/trước hoặc bằng/);
    expect(replace).not.toHaveBeenCalled();
  });

  it("thiếu Từ ngày hoặc Đến ngày: báo chọn đủ, không đổi URL", async () => {
    vi.mocked(api.listAuditLogs).mockResolvedValue({ data: [], hasNext: false });
    render(<AuditLogScreen />);
    await userEvent.clear(await screen.findByLabelText("Đến ngày"));
    await userEvent.click(screen.getByRole("button", { name: "Lọc" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Hãy chọn đủ Từ ngày và Đến ngày.");
    expect(replace).not.toHaveBeenCalled();
  });

  it("rỗng do lọc có nút Xoá bộ lọc", async () => {
    search = "action=order.refund";
    vi.mocked(api.listAuditLogs).mockResolvedValue({ data: [], hasNext: false });
    render(<AuditLogScreen />);
    expect(await screen.findByText("Không có nhật ký khớp bộ lọc")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Xoá bộ lọc" })).toHaveAttribute("href", "/quan-tri/nhat-ky");
  });

  it("vai trò không phải admin: trang không có quyền, không gọi API", () => {
    setUser("quan_ly_trang");
    render(<AuditLogScreen />);
    expect(screen.getByTestId("forbidden-view")).toBeInTheDocument();
    expect(api.listAuditLogs).not.toHaveBeenCalled();
  });

  it("API trả 403 -> trang không có quyền", async () => {
    vi.mocked(api.listAuditLogs).mockRejectedValue(new ApiError(403, { message: "Không có quyền", code: "FORBIDDEN" }));
    render(<AuditLogScreen />);
    expect(await screen.findByTestId("forbidden-view")).toBeInTheDocument();
  });

  it("422 hiện lời server, 429 báo thao tác nhanh, có Thử lại", async () => {
    vi.mocked(api.listAuditLogs).mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { to: ["Ngày kết thúc phải sau hoặc bằng ngày bắt đầu."] } }));
    render(<AuditLogScreen />);
    expect(await screen.findByText("Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.")).toBeInTheDocument();
    vi.mocked(api.listAuditLogs).mockRejectedValueOnce(new ApiError(429, { message: "x" }, 5));
    await userEvent.click(screen.getByRole("button", { name: /Thử lại/ }));
    expect(await screen.findByText(/quá nhanh/)).toBeInTheDocument();
    vi.mocked(api.listAuditLogs).mockResolvedValueOnce({ data: [log(1)], hasNext: false });
    await userEvent.click(screen.getByRole("button", { name: /Thử lại/ }));
    expect(await screen.findByRole("table")).toBeInTheDocument();
  });

  it("danh sách staff làm ô Người làm; thất bại thì dùng ô nhập số", async () => {
    vi.mocked(api.listAuditLogs).mockResolvedValue({ data: [], hasNext: false });
    vi.mocked(staffApi.listStaff).mockResolvedValue({
      data: [{ id: 3, name: "Trần Bình", email: "b@x.vn", role: "admin", status: "active", must_change_password: false, last_login_at: null, password_changed_at: null, created_at: "2026-01-01T00:00:00Z", is_self: false }],
      meta: { current_page: 1, per_page: 50, total: 1, last_page: 1 },
      links: { next: null, prev: null },
    });
    render(<AuditLogScreen />);
    expect(await screen.findByRole("option", { name: /Trần Bình/ })).toBeInTheDocument();
  });
});
