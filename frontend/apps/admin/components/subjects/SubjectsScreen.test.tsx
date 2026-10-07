import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/subjects/api";
import type { Subject, SubjectPage } from "@/lib/subjects/types";
import { SubjectsScreen } from "./SubjectsScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const replace = vi.fn();
let search = "";
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace }),
  usePathname: () => "/quan-tri/chuyen-de",
  useSearchParams: () => new URLSearchParams(search),
}));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/subjects/api");

const subject = (id: number, name: string, extra: Partial<Subject> = {}): Subject => ({
  id,
  name,
  slug: `s-${id}`,
  status: "active",
  courses_count: 0,
  created_at: null,
  updated_at: null,
  ...extra,
});
const pageOf = (data: Subject[], lastPage = 1): SubjectPage => ({
  data,
  meta: { current_page: 1, per_page: 25, total: data.length, last_page: lastPage },
  links: { next: null, prev: null },
});
function setUser(role: StaffUser["role"]) {
  const user: StaffUser = { id: 1, name: "N", email: null, role, permissions: null, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}
const renderScreen = () =>
  render(
    <ToastProvider>
      <SubjectsScreen />
    </ToastProvider>,
  );

beforeEach(() => {
  vi.resetAllMocks();
  search = "";
  setUser("admin");
});

describe("SubjectsScreen", () => {
  it("hiển thị tên như văn bản thuần (không diễn giải HTML)", async () => {
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([subject(1, "<img src=x onerror=alert(1)>")]));
    const { container } = renderScreen();
    expect(await screen.findByText("<img src=x onerror=alert(1)>")).toBeInTheDocument();
    expect(container.querySelector("img")).toBeNull();
  });

  it("rỗng: hiện trạng thái rỗng + nút tạo đầu tiên", async () => {
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([]));
    renderScreen();
    expect(await screen.findByText("Chưa có chuyên đề nào")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Tạo chuyên đề đầu tiên" })).toBeInTheDocument();
  });

  it("lỗi tải: báo lỗi và thử lại được", async () => {
    vi.mocked(api.listSubjects).mockRejectedValueOnce(new ApiError(500, { message: "Lỗi máy chủ" })).mockResolvedValueOnce(pageOf([subject(1, "Đại số")]));
    renderScreen();
    expect(await screen.findByText("Không tải được danh sách chuyên đề")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Thử lại" }));
    expect(await screen.findByText("Đại số")).toBeInTheDocument();
  });

  it("giáo viên: chỉ xem, không có nút ghi/cột quản lý", async () => {
    setUser("giao_vien");
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([subject(1, "Đại số", { courses_count: undefined })]));
    renderScreen();
    expect(await screen.findByText("Đại số")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Tạo chuyên đề/ })).toBeNull();
    expect(screen.queryByRole("button", { name: /Xoá/ })).toBeNull();
    expect(screen.queryByRole("switch")).toBeNull();
    expect(vi.mocked(api.listSubjects).mock.calls[0]?.[1].includeStatus).toBe(false);
  });

  it("tạo chuyên đề: lỗi 422 hiện dưới ô tên và giữ dữ liệu; sửa lại thì lưu được", async () => {
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([subject(1, "Đại số")]));
    vi.mocked(api.createSubject)
      .mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { name: ["Chuyên đề đã tồn tại."] } }))
      .mockResolvedValueOnce(subject(2, "Hình học"));
    renderScreen();
    await screen.findByText("Đại số");
    await userEvent.click(screen.getByRole("button", { name: "Tạo chuyên đề" }));
    const dialog = screen.getByRole("dialog");
    const input = within(dialog).getByLabelText(/Tên chuyên đề/);
    await userEvent.type(input, "đại số");
    await userEvent.click(within(dialog).getByRole("button", { name: "Lưu" }));
    expect(await within(dialog).findByText("Chuyên đề đã tồn tại.")).toBeInTheDocument();
    expect(input).toHaveValue("đại số");
    await userEvent.clear(input);
    await userEvent.type(input, "Hình học");
    await userEvent.click(within(dialog).getByRole("button", { name: "Lưu" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    expect(api.createSubject).toHaveBeenLastCalledWith("Hình học");
    expect(await screen.findByText("Đã lưu chuyên đề")).toBeInTheDocument();
  });

  it("tên rỗng: báo lỗi client, không gọi API", async () => {
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([subject(1, "Đại số")]));
    renderScreen();
    await screen.findByText("Đại số");
    await userEvent.click(screen.getByRole("button", { name: "Tạo chuyên đề" }));
    await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Lưu" }));
    expect(await screen.findByText("Vui lòng nhập tên chuyên đề")).toBeInTheDocument();
    expect(api.createSubject).not.toHaveBeenCalled();
  });

  it("xoá chuyên đề đang gán khóa học: chỉ hộp thoại chặn, không gọi DELETE, có gợi ý Ẩn", async () => {
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([subject(1, "Đại số", { courses_count: 3 })]));
    vi.mocked(api.setSubjectStatus).mockResolvedValue(subject(1, "Đại số", { status: "hidden" }));
    renderScreen();
    await screen.findByText("Đại số");
    await userEvent.click(screen.getByRole("button", { name: "Xoá chuyên đề Đại số" }));
    const dialog = screen.getByRole("dialog");
    expect(dialog).toHaveTextContent("đang được gán cho 3 khóa học");
    expect(within(dialog).queryByRole("button", { name: "Xoá" })).toBeNull();
    await userEvent.click(within(dialog).getByRole("button", { name: "Ẩn chuyên đề này" }));
    await waitFor(() => expect(api.setSubjectStatus).toHaveBeenCalledWith(1, "hidden"));
    expect(api.deleteSubject).not.toHaveBeenCalled();
  });

  it("xoá chưa gán: xác nhận rồi gọi DELETE; 409 giữa chừng chuyển sang hộp thoại chặn", async () => {
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([subject(1, "Số học")]));
    vi.mocked(api.deleteSubject).mockRejectedValueOnce(new ApiError(409, { message: "m", code: "SUBJECT_IN_USE" }));
    renderScreen();
    await screen.findByText("Số học");
    await userEvent.click(screen.getByRole("button", { name: "Xoá chuyên đề Số học" }));
    expect(screen.getByRole("dialog")).toHaveTextContent("Xoá chuyên đề 'Số học'? Hành động này không thể hoàn tác.");
    await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Xoá" }));
    expect(await screen.findByText("Không thể xoá chuyên đề")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Đã hiểu" })).toBeInTheDocument();
  });

  it("công tắc ẩn/hiện gọi PATCH và báo thành công", async () => {
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([subject(1, "Đại số")]));
    vi.mocked(api.setSubjectStatus).mockResolvedValue(subject(1, "Đại số", { status: "hidden" }));
    renderScreen();
    await screen.findByText("Đại số");
    const sw = screen.getByRole("switch", { name: "Hiển thị chuyên đề Đại số" });
    expect(sw).toHaveAttribute("aria-checked", "true");
    await userEvent.click(sw);
    expect(api.setSubjectStatus).toHaveBeenCalledWith(1, "hidden");
    expect(await screen.findByText("Đã ẩn chuyên đề khỏi bộ lọc công khai")).toBeInTheDocument();
  });

  it("URL đổi từ bên ngoài (link sidebar/back): ô tìm theo URL, không đẩy giá trị cũ lên lại", async () => {
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([subject(1, "Đại số")]));
    search = "q=abc";
    const ui = (
      <ToastProvider>
        <SubjectsScreen />
      </ToastProvider>
    );
    const { rerender } = render(ui);
    const box = await screen.findByLabelText("Tìm theo tên");
    expect(box).toHaveValue("abc");
    search = "";
    rerender(<ToastProvider><SubjectsScreen /></ToastProvider>);
    await waitFor(() => expect(box).toHaveValue(""));
    await new Promise((r) => setTimeout(r, 450));
    expect(replace).not.toHaveBeenCalled();
  });

  it("Ẩn thất bại trong hộp thoại chặn xoá: giữ hộp thoại, báo lỗi", async () => {
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([subject(1, "Đại số", { courses_count: 2 })]));
    vi.mocked(api.setSubjectStatus).mockRejectedValue(new ApiError(500, { message: "Lỗi máy chủ" }));
    renderScreen();
    await screen.findByText("Đại số");
    await userEvent.click(screen.getByRole("button", { name: "Xoá chuyên đề Đại số" }));
    await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Ẩn chuyên đề này" }));
    expect(await screen.findByText("Lỗi máy chủ")).toBeInTheDocument();
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });

  it("Esc khi đang xoá không đóng hộp xác nhận", async () => {
    vi.mocked(api.listSubjects).mockResolvedValue(pageOf([subject(1, "Số học")]));
    let release: () => void = () => undefined;
    vi.mocked(api.deleteSubject).mockReturnValue(new Promise<void>((r) => (release = r)));
    renderScreen();
    await screen.findByText("Số học");
    await userEvent.click(screen.getByRole("button", { name: "Xoá chuyên đề Số học" }));
    await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Xoá" }));
    await userEvent.keyboard("{Escape}");
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    release();
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
  });

  it("Ẩn/hiện gặp 404: báo lỗi và tải lại danh sách để dòng chết biến mất (QA FA2 BUG-1)", async () => {
    vi.mocked(api.listSubjects).mockResolvedValueOnce(pageOf([subject(1, "Đại số")])).mockResolvedValue(pageOf([]));
    vi.mocked(api.setSubjectStatus).mockRejectedValue(new ApiError(404, { message: "Không tìm thấy" }));
    renderScreen();
    await screen.findByText("Đại số");
    await userEvent.click(screen.getByRole("switch", { name: "Hiển thị chuyên đề Đại số" }));
    expect(await screen.findByText("Chuyên đề không còn tồn tại.")).toBeInTheDocument();
    await waitFor(() => expect(api.listSubjects).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.queryByText("Đại số")).toBeNull());
  });

  it("Sửa gặp 404: hộp thoại báo lỗi và danh sách phía sau được tải lại", async () => {
    vi.mocked(api.listSubjects).mockResolvedValueOnce(pageOf([subject(1, "Đại số")])).mockResolvedValue(pageOf([]));
    vi.mocked(api.renameSubject).mockRejectedValue(new ApiError(404, { message: "Không tìm thấy" }));
    renderScreen();
    await screen.findByText("Đại số");
    await userEvent.click(screen.getByRole("button", { name: "Sửa chuyên đề Đại số" }));
    const dialog = screen.getByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText(/Tên chuyên đề/), " x");
    await userEvent.click(within(dialog).getByRole("button", { name: "Lưu" }));
    expect(await within(dialog).findByText(/không còn tồn tại/)).toBeInTheDocument();
    await waitFor(() => expect(api.listSubjects).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.queryByRole("cell", { name: "Đại số" })).toBeNull());
  });
});
