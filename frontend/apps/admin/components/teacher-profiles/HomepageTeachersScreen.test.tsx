import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/teacher-profiles/api";
import type { TeacherProfile, TeacherProfilePage } from "@/lib/teacher-profiles/types";
import { profileFixture } from "./fixtures";
import { HomepageTeachersScreen } from "./HomepageTeachersScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const replace = vi.fn();
let search = "";
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
  usePathname: () => "/quan-tri/giao-vien",
  useSearchParams: () => new URLSearchParams(search),
}));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/teacher-profiles/api");

const pageOf = (data: TeacherProfile[], enabled = data.filter((t) => t.show_on_homepage).length): TeacherProfilePage => ({
  data,
  meta: { current_page: 1, per_page: 25, total: data.length, last_page: 1, homepage: { enabled_count: enabled, max: 6 } },
  links: { next: null, prev: null },
});
const on = (id: number, name: string, order: number, over: Partial<TeacherProfile> = {}) =>
  profileFixture({ id, name, show_on_homepage: true, homepage_order: order, homepage_status: { visible: true, reasons: [] }, ...over });
const off = (id: number, name: string, over: Partial<TeacherProfile> = {}) => profileFixture({ id, name, ...over });

function setUser(role: StaffUser["role"]) {
  const user: StaffUser = { id: 1, name: "N", email: null, role, permissions: null, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}

beforeEach(() => {
  vi.resetAllMocks();
  search = "";
  setUser("quan_ly_trang");
});

describe("HomepageTeachersScreen", () => {
  it("hiện bộ đếm X/6, nhãn 'Chưa hiện: lý do', người khoá/đổi vai trò, và đồng ý chỉ để xem", async () => {
    vi.mocked(api.listProfiles).mockResolvedValue(
      pageOf([
        on(1, "Cô Một", 1),
        on(2, "Thầy Hai", 2, { homepage_status: { visible: false, reasons: ["account_locked", "no_avatar"] }, user: { id: 2, name: "Thầy Hai", role: "giao_vien", status: "locked" } }),
        on(3, "Cô Cũ", 3, { user: { id: 3, name: "Cô Cũ", role: "quan_ly_trang", status: "active" }, homepage_status: { visible: false, reasons: ["not_teacher", "no_consent"] } }),
        off(4, "Thầy Bốn"),
      ]),
    );
    render(<HomepageTeachersScreen />);
    expect(await screen.findByTestId("enabled-counter")).toHaveTextContent("Đang bật 3/6");
    expect(screen.getByText("Chưa hiện: tài khoản bị khoá, chưa có ảnh")).toBeInTheDocument();
    expect(screen.getByText("Chưa hiện: không còn là giáo viên, chưa đồng ý công khai")).toBeInTheDocument();
    expect(screen.getByText("Không còn là giáo viên")).toBeInTheDocument();
    expect(screen.getByText("Tài khoản bị khoá")).toBeInTheDocument();
    expect(screen.getByText("Thiếu: chưa đồng ý công khai, chưa có ảnh")).toBeInTheDocument();
    // Không có thao tác đồng ý thay giáo viên.
    expect(screen.queryByRole("button", { name: /đồng ý/i })).toBeNull();
    expect(screen.getByRole("link", { name: "Sửa hồ sơ Cô Một" })).toHaveAttribute("href", "/quan-tri/giao-vien/1");
    // Người không còn là giáo viên đang bật thì tắt được; người không còn là giáo viên đã tắt thì không bật được (UI).
    expect(screen.getByRole("switch", { name: "Hiển thị Cô Cũ trên trang chủ" })).toBeEnabled();
  });

  it("bật giáo viên gửi kèm thứ tự cuối, rồi tải lại danh sách", async () => {
    vi.mocked(api.listProfiles).mockResolvedValue(pageOf([on(1, "Cô Một", 1), off(4, "Thầy Bốn")]));
    vi.mocked(api.patchHomepage).mockResolvedValue(on(4, "Thầy Bốn", 2));
    render(<HomepageTeachersScreen />);
    await userEvent.click(await screen.findByRole("switch", { name: "Hiển thị Thầy Bốn trên trang chủ" }));
    await waitFor(() => expect(api.patchHomepage).toHaveBeenCalledWith(4, { show_on_homepage: true, homepage_order: 2 }));
    await waitFor(() => expect(api.listProfiles).toHaveBeenCalledTimes(2));
  });

  it("409 TEACHER_HOMEPAGE_LIMIT hiện đúng thông điệp (AC10) và giữ trạng thái cũ", async () => {
    const six = [1, 2, 3, 4, 5, 6].map((i) => on(i, `GV ${i}`, i));
    vi.mocked(api.listProfiles).mockResolvedValue(pageOf([...six, off(7, "Người thứ bảy")]));
    vi.mocked(api.patchHomepage).mockRejectedValue(new ApiError(409, { message: "x", code: "TEACHER_HOMEPAGE_LIMIT" }));
    render(<HomepageTeachersScreen />);
    expect(await screen.findByTestId("enabled-counter")).toHaveTextContent("Đang bật 6/6");
    await userEvent.click(screen.getByRole("switch", { name: "Hiển thị Người thứ bảy trên trang chủ" }));
    expect(await screen.findByText("Trang chủ chỉ hiển thị tối đa 6 giáo viên. Hãy tắt bớt một người trước.")).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "Hiển thị Người thứ bảy trên trang chủ" })).toHaveAttribute("aria-checked", "false");
  });

  it("đổi thứ tự: Xuống đổi chỗ và chỉ PATCH những người có thứ tự đổi", async () => {
    vi.mocked(api.listProfiles).mockResolvedValue(pageOf([on(1, "Cô Một", 1), on(2, "Thầy Hai", 2), on(3, "Cô Ba", 3)]));
    vi.mocked(api.patchHomepage).mockResolvedValue(on(1, "Cô Một", 2));
    render(<HomepageTeachersScreen />);
    await userEvent.click(await screen.findByRole("button", { name: "Đưa Cô Một xuống" }));
    await waitFor(() => expect(api.patchHomepage).toHaveBeenCalledTimes(2));
    expect(api.patchHomepage).toHaveBeenCalledWith(2, { homepage_order: 1 });
    expect(api.patchHomepage).toHaveBeenCalledWith(1, { homepage_order: 2 });
    const first = screen.getByRole("button", { name: "Đưa Cô Một lên" });
    expect(first).toBeDisabled();
  });

  it("tắt gửi show_on_homepage=false", async () => {
    vi.mocked(api.listProfiles).mockResolvedValue(pageOf([on(1, "Cô Một", 1)]));
    vi.mocked(api.patchHomepage).mockResolvedValue(off(1, "Cô Một"));
    render(<HomepageTeachersScreen />);
    await userEvent.click(await screen.findByRole("switch", { name: "Hiển thị Cô Một trên trang chủ" }));
    await waitFor(() => expect(api.patchHomepage).toHaveBeenCalledWith(1, { show_on_homepage: false, homepage_order: null }));
  });

  it("đang lọc theo tên: thứ tự cuối tính trên MỌI người đang bật, không trên danh sách lọc (QA FA11 BUG-1)", async () => {
    search = "q=GV%20M%E1%BB%99t";
    vi.mocked(api.listProfiles).mockImplementation(async (q) =>
      q.onlyEnabled && q.q === "" ? pageOf([on(1, "Cô Một", 1), on(2, "Thầy Hai", 2), on(3, "Cô Ba", 3)]) : pageOf([off(9, "GV Một")]),
    );
    vi.mocked(api.patchHomepage).mockResolvedValue(on(9, "GV Một", 4));
    render(<HomepageTeachersScreen />);
    await userEvent.click(await screen.findByRole("switch", { name: "Hiển thị GV Một trên trang chủ" }));
    await waitFor(() => expect(api.patchHomepage).toHaveBeenCalledWith(9, { show_on_homepage: true, homepage_order: 4 }));
  });

  it("bộ lọc từ URL được gửi lên API; rỗng có nút xoá bộ lọc", async () => {
    search = "q=Lan&homepage=1";
    vi.mocked(api.listProfiles).mockResolvedValue(pageOf([]));
    render(<HomepageTeachersScreen />);
    expect(await screen.findByText("Không có giáo viên phù hợp với bộ lọc.")).toBeInTheDocument();
    expect(vi.mocked(api.listProfiles).mock.calls[0]?.[0]).toMatchObject({ q: "Lan", onlyEnabled: true });
    expect(screen.getByRole("link", { name: "Xoá bộ lọc" })).toBeInTheDocument();
  });

  it("403 → màn không có quyền; lỗi mạng → thử lại", async () => {
    vi.mocked(api.listProfiles).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    const { unmount } = render(<HomepageTeachersScreen />);
    expect(await screen.findByTestId("forbidden-view")).toBeInTheDocument();
    unmount();
    vi.mocked(api.listProfiles).mockRejectedValue(new ApiError(500, { message: "Lỗi máy chủ" }));
    render(<HomepageTeachersScreen />);
    expect(await screen.findByText("Không tải được danh sách giáo viên")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Thử lại" })).toBeInTheDocument();
  });
});

describe("HomepageTeachersScreen — thứ tự", () => {
  it("bật người mới lấy thứ tự lớn nhất + 1 khi có khoảng trống (R1)", async () => {
    vi.mocked(api.listProfiles).mockResolvedValue(pageOf([on(1, "Cô Một", 1), on(3, "Cô Ba", 3), on(4, "Cô Bốn", 4), off(9, "Người mới")]));
    vi.mocked(api.patchHomepage).mockResolvedValue(on(9, "Người mới", 5));
    render(<HomepageTeachersScreen />);
    await userEvent.click(await screen.findByRole("switch", { name: "Hiển thị Người mới trên trang chủ" }));
    await waitFor(() => expect(api.patchHomepage).toHaveBeenCalledWith(9, { show_on_homepage: true, homepage_order: 5 }));
  });

  it("thứ tự trùng nhau: đánh số lại liền mạch, bỏ qua người không đổi", async () => {
    vi.mocked(api.listProfiles).mockResolvedValue(pageOf([on(1, "Cô Một", 1), on(2, "Thầy Hai", 1), on(3, "Cô Ba", 1)]));
    vi.mocked(api.patchHomepage).mockResolvedValue(on(1, "Cô Một", 2));
    render(<HomepageTeachersScreen />);
    await userEvent.click(await screen.findByRole("button", { name: "Đưa Cô Một xuống" }));
    await waitFor(() => expect(api.patchHomepage).toHaveBeenCalledTimes(2));
    expect(api.patchHomepage).toHaveBeenCalledWith(1, { homepage_order: 2 });
    expect(api.patchHomepage).toHaveBeenCalledWith(3, { homepage_order: 3 });
    expect(api.patchHomepage).not.toHaveBeenCalledWith(2, expect.anything());
  });

  it("lỗi ở PATCH thứ hai: hiện thông điệp, dừng, tải lại danh sách theo server", async () => {
    vi.mocked(api.listProfiles).mockResolvedValue(pageOf([on(1, "Cô Một", 1), on(2, "Thầy Hai", 2)]));
    vi.mocked(api.patchHomepage).mockResolvedValueOnce(on(2, "Thầy Hai", 1)).mockRejectedValueOnce(new ApiError(429, { message: "x" }));
    render(<HomepageTeachersScreen />);
    await userEvent.click(await screen.findByRole("button", { name: "Đưa Cô Một xuống" }));
    expect(await screen.findByText(/thao tác quá nhanh/)).toBeInTheDocument();
    expect(api.patchHomepage).toHaveBeenCalledTimes(2);
    await waitFor(() => expect(api.listProfiles).toHaveBeenCalledTimes(2));
  });

  it("422 NOT_TEACHER khi người khác vừa đổi vai trò: thông điệp riêng, tải lại", async () => {
    vi.mocked(api.listProfiles).mockResolvedValue(pageOf([on(1, "Cô Một", 1), off(5, "Thầy Năm")]));
    vi.mocked(api.patchHomepage).mockRejectedValue(new ApiError(422, { message: "x", code: "NOT_TEACHER" }));
    render(<HomepageTeachersScreen />);
    await userEvent.click(await screen.findByRole("switch", { name: "Hiển thị Thầy Năm trên trang chủ" }));
    expect(await screen.findByText(/không còn là giáo viên nên không bật hiển thị được/)).toBeInTheDocument();
    await waitFor(() => expect(api.listProfiles).toHaveBeenCalledTimes(2));
  });

  it("bấm kép công tắc trong cùng một tick chỉ gửi một lần (R6)", async () => {
    vi.mocked(api.listProfiles).mockResolvedValue(pageOf([on(1, "Cô Một", 1)]));
    vi.mocked(api.patchHomepage).mockResolvedValue(off(1, "Cô Một"));
    render(<HomepageTeachersScreen />);
    const sw = await screen.findByRole("switch", { name: "Hiển thị Cô Một trên trang chủ" });
    sw.click();
    sw.click();
    await waitFor(() => expect(api.patchHomepage).toHaveBeenCalledTimes(1));
  });
});
