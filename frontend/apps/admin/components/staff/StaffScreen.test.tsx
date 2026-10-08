import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/staff/api";
import type { StaffAccount, StaffPage } from "@/lib/staff/types";
import { StaffScreen } from "./StaffScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const replace = vi.fn();
let search = "";
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
  usePathname: () => "/quan-tri/tai-khoan",
  useSearchParams: () => new URLSearchParams(search),
}));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/staff/api");

const acc = (id: number, name: string, over: Partial<StaffAccount> = {}): StaffAccount => ({
  id,
  name,
  email: `u${id}@example.com`,
  role: "giao_vien",
  status: "active",
  must_change_password: false,
  last_login_at: null,
  password_changed_at: null,
  created_at: "2026-09-01T00:00:00+07:00",
  is_self: false,
  ...over,
});
const pageOf = (data: StaffAccount[], last = 1): StaffPage => ({ data, meta: { current_page: 1, per_page: 25, total: data.length, last_page: last }, links: { next: null, prev: null } });

function setUser(role: StaffUser["role"], permissions: StaffUser["permissions"] = null) {
  const user: StaffUser = { id: 1, name: "N", email: null, role, permissions, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}
const renderScreen = () =>
  render(
    <ToastProvider>
      <StaffScreen />
    </ToastProvider>,
  );

beforeEach(() => {
  vi.resetAllMocks();
  replace.mockReset();
  search = "";
  setUser("admin");
});

describe("StaffScreen", () => {
  it("hiện tên, email, vai trò, trạng thái bằng chữ; dòng của mình không có nút khóa", async () => {
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(1, "Tôi Admin", { role: "admin", is_self: true }), acc(2, "Cô Lan", { status: "locked", last_login_at: "2026-10-01T03:00:00Z" })]));
    renderScreen();
    expect(await screen.findByText("Cô Lan")).toBeInTheDocument();
    expect(screen.getAllByText("Đã khóa").length).toBeGreaterThan(0);
    expect(screen.getByText(/không thể tự khóa/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Khóa tài khoản Tôi Admin" })).toBeNull();
    expect(screen.getByRole("button", { name: "Mở khóa tài khoản Cô Lan" })).toBeInTheDocument();
    expect(vi.mocked(api.listStaff).mock.calls[0]?.[0]).toMatchObject({ q: "", role: "", status: "", page: 1, perPage: 25 });
  });

  it("lọc từ URL gửi lên API; đổi dropdown ghi lên URL, về trang 1", async () => {
    search = "role=giao_vien&status=locked&page=2";
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(2, "Cô Lan")], 3));
    renderScreen();
    await screen.findByText("Cô Lan");
    expect(vi.mocked(api.listStaff).mock.calls[0]?.[0]).toMatchObject({ role: "giao_vien", status: "locked", page: 2 });
    await userEvent.selectOptions(screen.getByLabelText("Vai trò"), "admin");
    expect(replace).toHaveBeenCalledWith("/quan-tri/tai-khoan?role=admin&status=locked", { scroll: false });
  });

  it("đổi hai bộ lọc liên tiếp trước khi URL kịp đổi: không mất thay đổi đầu", async () => {
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(2, "Cô Lan")]));
    renderScreen();
    await screen.findByText("Cô Lan");
    await userEvent.selectOptions(screen.getByLabelText("Vai trò"), "giao_vien");
    await userEvent.selectOptions(screen.getByLabelText("Trạng thái"), "locked");
    expect(replace).toHaveBeenLastCalledWith("/quan-tri/tai-khoan?role=giao_vien&status=locked", { scroll: false });
  });

  it("rỗng do lọc: 'không khớp bộ lọc'", async () => {
    search = "status=locked";
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([]));
    renderScreen();
    expect(await screen.findByText("Không có tài khoản nào khớp bộ lọc")).toBeInTheDocument();
  });

  it("lỗi tải: thông báo + Thử lại", async () => {
    vi.mocked(api.listStaff).mockRejectedValueOnce(new ApiError(500, { message: "Lỗi máy chủ." })).mockResolvedValue(pageOf([acc(2, "Cô Lan")]));
    renderScreen();
    expect(await screen.findByText("Không tải được danh sách tài khoản")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Thử lại" }));
    expect(await screen.findByText("Cô Lan")).toBeInTheDocument();
  });

  it("quản lý trang / giáo viên: 403, không gọi API; API trả 403 cũng ra 403", async () => {
    setUser("quan_ly_trang");
    const { unmount } = renderScreen();
    expect(screen.getByTestId("forbidden-view")).toBeInTheDocument();
    expect(api.listStaff).not.toHaveBeenCalled();
    unmount();
    setUser("admin");
    vi.mocked(api.listStaff).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    renderScreen();
    expect(await screen.findByTestId("forbidden-view")).toBeInTheDocument();
  });

  it("khóa: hộp xác nhận, chặn bấm kép, gọi API một lần rồi tải lại", async () => {
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(2, "Cô Lan")]));
    let done!: (v: StaffAccount) => void;
    vi.mocked(api.lockStaff).mockReturnValue(new Promise((r) => (done = r)));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Khóa tài khoản Cô Lan" }));
    const dlg = await screen.findByRole("dialog", { name: "Khóa tài khoản Cô Lan?" });
    const ok = within(dlg).getByRole("button", { name: "Khóa tài khoản" });
    await userEvent.dblClick(ok);
    expect(api.lockStaff).toHaveBeenCalledTimes(1);
    done(acc(2, "Cô Lan", { status: "locked" }));
    await waitFor(() => expect(api.listStaff).toHaveBeenCalledTimes(2));
  });

  it("khóa Admin cuối: lỗi LAST_ADMIN của server hiện trong hộp, hộp không đóng", async () => {
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(3, "Admin Hai", { role: "admin" })]));
    vi.mocked(api.lockStaff).mockRejectedValue(new ApiError(409, { message: "Không thể khóa tài khoản quản trị viên đang hoạt động cuối cùng.", code: "LAST_ADMIN" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Khóa tài khoản Admin Hai" }));
    const dlg = await screen.findByRole("dialog", { name: "Khóa tài khoản Admin Hai?" });
    await userEvent.click(within(dlg).getByRole("button", { name: "Khóa tài khoản" }));
    expect(await within(dlg).findByText(/hoạt động cuối cùng/)).toBeInTheDocument();
  });

  it("đặt lại mật khẩu: hiện mật khẩu một lần, không đóng bằng nút X, đóng xong biến mất", async () => {
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(2, "Cô Lan")]));
    vi.mocked(api.resetStaffPassword).mockResolvedValue({ ...acc(2, "Cô Lan"), initial_password: "Abcd1234efgh5678WXYZ" });
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Đặt lại mật khẩu của Cô Lan" }));
    const dlg = await screen.findByRole("dialog", { name: "Đặt lại mật khẩu cho Cô Lan?" });
    await userEvent.click(within(dlg).getByRole("button", { name: "Đặt lại mật khẩu" }));
    expect(await screen.findByTestId("initial-password")).toHaveTextContent("Abcd1234efgh5678WXYZ");
    expect(screen.getByText(/chỉ hiển thị 1 lần/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Đóng" })).toBeNull();
    await userEvent.click(screen.getByRole("button", { name: "Đã lưu mật khẩu, đóng" }));
    expect(screen.queryByTestId("initial-password")).toBeNull();
  });

  it("tạo: kiểm sơ bộ, 422 email trùng về đúng ô + hộp tóm tắt, rồi thành công hiện mật khẩu", async () => {
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(2, "Cô Lan")]));
    vi.mocked(api.createStaff)
      .mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { email: ["Email đã được sử dụng."] } }))
      .mockResolvedValue({ ...acc(9, "Thầy Minh"), initial_password: "Zzzz1111yyyy2222XXXX" });
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Tạo tài khoản" }));
    const dlg = await screen.findByRole("dialog", { name: "Tạo tài khoản staff mới" });
    await userEvent.click(within(dlg).getByRole("button", { name: "Tạo tài khoản" }));
    expect(await within(dlg).findByText(/còn 3 chỗ cần sửa/)).toBeInTheDocument();
    expect(api.createStaff).not.toHaveBeenCalled();
    expect(within(dlg).queryByRole("option", { name: /Học sinh/ })).toBeNull();
    await userEvent.type(within(dlg).getByLabelText(/Họ và tên/), "Thầy Minh");
    await userEvent.type(within(dlg).getByLabelText(/Email/), "minh@example.com");
    await userEvent.selectOptions(within(dlg).getByLabelText(/Vai trò/), "giao_vien");
    await userEvent.click(within(dlg).getByRole("button", { name: "Tạo tài khoản" }));
    expect(await within(dlg).findByText(/còn 1 chỗ cần sửa/)).toBeInTheDocument();
    expect(within(dlg).getByText("Email đã được sử dụng.")).toBeInTheDocument();
    expect(within(dlg).getByLabelText(/Email/)).toHaveAttribute("aria-invalid", "true");
    expect(within(dlg).getByLabelText(/Họ và tên/)).toHaveValue("Thầy Minh");
    await userEvent.click(within(dlg).getByRole("button", { name: "Tạo tài khoản" }));
    expect(await screen.findByTestId("initial-password")).toHaveTextContent("Zzzz1111yyyy2222XXXX");
    expect(vi.mocked(api.createStaff).mock.calls[1]?.[0]).toEqual({ name: "Thầy Minh", email: "minh@example.com", role: "giao_vien" });
  });

  it("đổi vai trò giáo viên: cảnh báo trước; sau đó Alert N khóa kèm liên kết", async () => {
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(2, "Cô Lan")]));
    vi.mocked(api.changeStaffRole).mockResolvedValue({ ...acc(2, "Cô Lan", { role: "quan_ly_trang" }), released_course_ids: [11, 12] });
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Đổi vai trò của Cô Lan" }));
    const dlg = await screen.findByRole("dialog", { name: "Đổi vai trò của Cô Lan" });
    await userEvent.click(within(dlg).getByRole("button", { name: "Đổi vai trò" }));
    expect(await within(dlg).findByText("Hãy chọn vai trò khác với vai trò hiện tại.")).toBeInTheDocument();
    await userEvent.selectOptions(within(dlg).getByLabelText(/Vai trò mới/), "quan_ly_trang");
    expect(within(dlg).getByText("Các khóa đang phụ trách sẽ bị gỡ")).toBeInTheDocument();
    await userEvent.click(within(dlg).getByRole("button", { name: "Đổi vai trò" }));
    expect(await screen.findByText("2 khóa không còn giáo viên phụ trách, hãy gán lại")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Khóa #11" })).toHaveAttribute("href", "/quan-tri/khoa-hoc/11/sua");
    expect(api.changeStaffRole).toHaveBeenCalledWith(2, "quan_ly_trang");
  });

  it("đổi vai trò: lỗi server (Admin cuối) hiện trong hộp; không có released → không có Alert khóa", async () => {
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(3, "Admin Hai", { role: "admin" })]));
    vi.mocked(api.changeStaffRole).mockRejectedValue(new ApiError(409, { message: "Không thể đổi vai trò tài khoản quản trị viên đang hoạt động cuối cùng.", code: "LAST_ADMIN" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Đổi vai trò của Admin Hai" }));
    const dlg = await screen.findByRole("dialog", { name: "Đổi vai trò của Admin Hai" });
    await userEvent.selectOptions(within(dlg).getByLabelText(/Vai trò mới/), "giao_vien");
    await userEvent.click(within(dlg).getByRole("button", { name: "Đổi vai trò" }));
    expect(await within(dlg).findByText(/cuối cùng/)).toBeInTheDocument();
    expect(screen.queryByText(/không còn giáo viên phụ trách/)).toBeNull();
  });

  it("R1: tạo gặp lỗi 5xx -> hướng dẫn phục hồi + nút Tải lại danh sách", async () => {
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(2, "Cô Lan")]));
    vi.mocked(api.createStaff).mockRejectedValue(new ApiError(500, { message: "Lỗi máy chủ." }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Tạo tài khoản" }));
    const dlg = await screen.findByRole("dialog", { name: "Tạo tài khoản staff mới" });
    await userEvent.type(within(dlg).getByLabelText(/Họ và tên/), "Thầy Minh");
    await userEvent.type(within(dlg).getByLabelText(/Email/), "minh@example.com");
    await userEvent.selectOptions(within(dlg).getByLabelText(/Vai trò/), "giao_vien");
    await userEvent.click(within(dlg).getByRole("button", { name: "Tạo tài khoản" }));
    expect(await within(dlg).findByText(/Có thể tài khoản đã được tạo/)).toBeInTheDocument();
    await userEvent.click(within(dlg).getByRole("button", { name: "Tải lại danh sách" }));
    await waitFor(() => expect(api.listStaff).toHaveBeenCalledTimes(2));
  });

  it("R2: đổi lên/xuống Admin -> cảnh báo toàn quyền hệ thống", async () => {
    vi.mocked(api.listStaff).mockResolvedValue(pageOf([acc(2, "Cô Lan")]));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Đổi vai trò của Cô Lan" }));
    const dlg = await screen.findByRole("dialog", { name: "Đổi vai trò của Cô Lan" });
    await userEvent.selectOptions(within(dlg).getByLabelText(/Vai trò mới/), "admin");
    expect(within(dlg).getByText("Vai trò Admin có toàn quyền hệ thống")).toBeInTheDocument();
  });
});
