import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/courses/api";
import * as curriculumApi from "@/lib/curriculum/api";
import type { CourseAbilities, CourseDetail } from "@/lib/courses/types";
import { UploadProvider } from "@/components/curriculum/useUploadManager";
import { CourseEditScreen } from "./CourseEditScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const push = vi.fn();
const nav = vi.hoisted(() => ({ search: "" }));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push }),
  usePathname: () => "/quan-tri/khoa-hoc/5/sua",
  useSearchParams: () => new URLSearchParams(nav.search),
}));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/courses/api");
vi.mock("@/lib/curriculum/api");

const STAFF_ABILITIES: CourseAbilities = { update: true, delete: true, publish: true, manage_teachers: true, edit_price: true, edit_grade_level: true };
const TEACHER_ABILITIES: CourseAbilities = { update: true, delete: false, publish: false, manage_teachers: false, edit_price: false, edit_grade_level: false };

const detail = (over: Partial<CourseDetail> = {}, abilities: CourseAbilities = STAFF_ABILITIES): CourseDetail => ({
  id: 5,
  title: "Hình học 9",
  slug: "hinh-hoc-9",
  short_description: null,
  grade_level: 9,
  price: 100000,
  thumbnail_url: null,
  status: "draft",
  published_at: null,
  manual_order: null,
  enrollments_count: 0,
  subjects: [{ id: 1, name: "Đại số", slug: "dai-so" }],
  teachers: [{ id: 7, name: "Cô Lan" }],
  created_by: 1,
  created_at: null,
  updated_at: "2026-10-05T09:40:00+00:00",
  description: "<p>Mô tả</p>",
  chapters_count: 0,
  lessons_count: 0,
  abilities,
  ...over,
});

function setUser(role: StaffUser["role"]) {
  const user: StaffUser = { id: 1, name: "N", email: null, role, permissions: null, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}
const renderScreen = () =>
  render(
    <ToastProvider>
      <UploadProvider>
        <CourseEditScreen id={5} />
      </UploadProvider>
    </ToastProvider>,
  );

beforeEach(() => {
  vi.resetAllMocks();
  nav.search = "";
  push.mockReset();
  setUser("quan_ly_trang");
  vi.mocked(api.listActiveSubjects).mockResolvedValue([{ id: 1, name: "Đại số" }]);
  vi.mocked(api.listTeachers).mockResolvedValue([
    { id: 7, name: "Cô Lan" },
    { id: 8, name: "Thầy Minh" },
  ]);
});

describe("CourseEditScreen", () => {
  it("staff: tab Chương & bài và Bài tập (link thật); nút Xuất bản; thông tin trạng thái", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail());
    renderScreen();
    expect(await screen.findByRole("heading", { name: "Hình học 9", level: 1 })).toBeInTheDocument();
    const tabs = screen.getByRole("navigation", { name: "Phần của khóa học" });
    expect(within(tabs).getByRole("link", { name: "Thông tin chung" })).toHaveAttribute("aria-current", "page");
    expect(within(tabs).queryByText("Sắp có")).toBeNull();
    expect(within(tabs).getByRole("link", { name: "Bài tập" })).toHaveAttribute("href", "/quan-tri/khoa-hoc/5/sua?tab=bai-tap");
    expect(within(tabs).getAllByRole("link")).toHaveLength(3);
    expect(screen.getByRole("button", { name: "Xuất bản" })).toBeInTheDocument();
    expect(screen.getByText(/Chưa có trang công khai/)).toBeInTheDocument();
  });

  it("xuất bản khi chưa có chương/bài: hộp thoại COURSE_NOT_PUBLISHABLE (AC3)", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail());
    vi.mocked(api.publishCourse).mockRejectedValue(new ApiError(422, { message: "x", code: "COURSE_NOT_PUBLISHABLE" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Xuất bản" }));
    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("Khóa học cần có ít nhất 1 chương và 1 bài học để xuất bản.");
    await userEvent.click(within(dialog).getByRole("button", { name: "Đã hiểu" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
  });

  it("xuất bản thành công: toast và tải lại", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail());
    vi.mocked(api.publishCourse).mockResolvedValue({} as never);
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Xuất bản" }));
    expect(await screen.findByText("Đã xuất bản khóa học")).toBeInTheDocument();
    await waitFor(() => expect(api.getCourse).toHaveBeenCalledTimes(2));
  });

  it("ngừng bán: hộp xác nhận nêu học sinh đã mua vẫn giữ quyền (AC4)", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail({ status: "published", enrollments_count: 2 }));
    vi.mocked(api.unpublishCourse).mockResolvedValue({} as never);
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Ngừng bán" }));
    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("học sinh đã mua vẫn giữ quyền truy cập");
    await userEvent.click(within(dialog).getByRole("button", { name: "Ngừng bán" }));
    expect(await screen.findByText("Đã ngừng bán khóa học")).toBeInTheDocument();
  });

  it("có học sinh: nút Xoá bị khóa kèm lý do", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail({ enrollments_count: 3 }));
    renderScreen();
    expect(await screen.findByText(/Không thể xoá vì đã có 3 học sinh đăng ký/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Xoá khóa học" })).toBeDisabled();
  });

  it("xoá (chưa có học sinh): xác nhận rồi về danh sách (AC5)", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail());
    vi.mocked(api.deleteCourse).mockResolvedValue(undefined);
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Xoá khóa học" }));
    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("Hành động này không thể hoàn tác");
    await userEvent.click(within(dialog).getByRole("button", { name: "Xoá" }));
    await waitFor(() => expect(push).toHaveBeenCalledWith("/quan-tri/khoa-hoc"));
    expect(api.deleteCourse).toHaveBeenCalledWith(5);
  });

  it("xoá gặp 409 COURSE_HAS_ENROLLMENTS (số liệu cũ): chuyển sang hộp thoại chặn, gợi ý Ngừng bán", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail({ status: "published" }));
    vi.mocked(api.deleteCourse).mockRejectedValue(new ApiError(409, { message: "x", code: "COURSE_HAS_ENROLLMENTS" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Xoá khóa học" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "Xoá" }));
    await waitFor(() => expect(screen.getByRole("dialog")).toHaveTextContent("Không thể xoá"));
    expect(within(screen.getByRole("dialog")).getByRole("button", { name: "Ngừng bán" })).toBeInTheDocument();
    await waitFor(() => expect(api.getCourse).toHaveBeenCalledTimes(2));
  });

  it("409 trạng thái đã đổi khi xuất bản: báo và tải lại", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail());
    vi.mocked(api.publishCourse).mockRejectedValue(new ApiError(409, { message: "x", code: "ALREADY_PROCESSED" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Xuất bản" }));
    expect(await screen.findByText(/Trạng thái khóa học đã thay đổi/)).toBeInTheDocument();
    await waitFor(() => expect(api.getCourse).toHaveBeenCalledTimes(2));
  });

  it("thứ tự nổi bật: số không hợp lệ bị chặn, hợp lệ gọi PATCH; Enter không gửi form thông tin", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail());
    vi.mocked(api.setManualOrder).mockResolvedValue({} as never);
    renderScreen();
    const input = await screen.findByLabelText(/Thứ tự nổi bật/);
    await userEvent.type(input, "-3{Enter}");
    expect(await screen.findByText(/từ 0 đến 1\.000\.000/)).toBeInTheDocument();
    expect(api.setManualOrder).not.toHaveBeenCalled();
    expect(api.updateCourse).not.toHaveBeenCalled();
    await userEvent.clear(input);
    await userEvent.type(input, "5");
    await userEvent.click(screen.getByRole("button", { name: "Lưu thứ tự" }));
    await waitFor(() => expect(api.setManualOrder).toHaveBeenCalledWith(5, 5));
    expect(await screen.findByText("Đã lưu thứ tự nổi bật")).toBeInTheDocument();
  });

  it("gán giáo viên: chặn bỏ người cuối; thêm người mới và lưu PUT /teachers; lỗi 422 hiện dưới nhóm", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail());
    vi.mocked(api.setCourseTeachers)
      .mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { teacher_ids: ["Chỉ được gán tài khoản giáo viên đang hoạt động."] } }))
      .mockResolvedValueOnce(detail({ teachers: [{ id: 7, name: "Cô Lan" }, { id: 8, name: "Thầy Minh" }] }));
    renderScreen();
    const group = await screen.findByTestId("teachers-group");
    await userEvent.click(within(group).getByRole("button", { name: "Bỏ Cô Lan" }));
    expect(await within(group).findByText("Khóa học cần tối thiểu 1 giáo viên phụ trách.")).toBeInTheDocument();
    await within(group).findByRole("option", { name: "Thầy Minh" });
    await userEvent.selectOptions(within(group).getByLabelText("Thêm giáo viên"), "8");
    await userEvent.click(screen.getByRole("button", { name: "Lưu giáo viên" }));
    expect(await within(group).findByText("Chỉ được gán tài khoản giáo viên đang hoạt động.")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Lưu giáo viên" }));
    await waitFor(() => expect(api.setCourseTeachers).toHaveBeenLastCalledWith(5, [7, 8]));
    expect(await screen.findByText("Đã cập nhật giáo viên phụ trách")).toBeInTheDocument();
  });

  it("giáo viên: không có xuất bản/xoá/gán GV/thứ tự; thấy lời nhắn chờ Admin; không gọi /admin/teachers", async () => {
    setUser("giao_vien");
    vi.mocked(api.getCourse).mockResolvedValue(detail({ status: "published", published_at: "2026-01-01T00:00:00+07:00" }, TEACHER_ABILITIES));
    renderScreen();
    await screen.findByRole("heading", { name: "Hình học 9", level: 1 });
    expect(screen.queryByRole("button", { name: /Xuất bản|Ngừng bán|Xoá khóa học|Lưu giáo viên/ })).toBeNull();
    expect(screen.queryByTestId("teachers-group")).toBeNull();
    expect(screen.queryByLabelText(/Thứ tự nổi bật/)).toBeNull();
    expect(screen.getByText("Khóa học sẽ được Admin xem xét và xuất bản.")).toBeInTheDocument();
    expect(screen.getByLabelText(/^Lớp/)).toBeDisabled();
    expect(screen.getByLabelText(/Học phí/)).toBeDisabled();
    expect(api.listTeachers).not.toHaveBeenCalled();
  });

  it("403: màn không có quyền; 404: không tìm thấy", async () => {
    vi.mocked(api.getCourse).mockRejectedValueOnce(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    const { unmount } = renderScreen();
    expect(await screen.findByTestId("course-forbidden")).toHaveTextContent("Bạn không có quyền truy cập khóa học này.");
    unmount();
    vi.mocked(api.getCourse).mockRejectedValueOnce(new ApiError(404, { message: "x" }));
    renderScreen();
    expect(await screen.findByTestId("course-not-found")).toBeInTheDocument();
  });

  it("lưu form không làm mất danh sách giáo viên đang chọn dở (R1)", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail());
    vi.mocked(api.updateCourse).mockResolvedValue(detail({ title: "Hình học 9B" }));
    renderScreen();
    const group = await screen.findByTestId("teachers-group");
    await within(group).findByRole("option", { name: "Thầy Minh" });
    await userEvent.selectOptions(within(group).getByLabelText("Thêm giáo viên"), "8");
    const title = screen.getByLabelText(/Tên khóa học/);
    await userEvent.clear(title);
    await userEvent.type(title, "Hình học 9B");
    await userEvent.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    expect(await screen.findByText("Đã lưu thay đổi")).toBeInTheDocument();
    expect(within(screen.getByTestId("teachers-group")).getByRole("button", { name: "Bỏ Thầy Minh" })).toBeInTheDocument();
    expect(screen.getByText("Có thay đổi chưa lưu.")).toBeInTheDocument();
  });

  it("xuất bản khi form còn thay đổi chưa lưu: hỏi xác nhận trước (R3)", async () => {
    vi.mocked(api.getCourse).mockResolvedValue(detail());
    vi.mocked(api.publishCourse).mockResolvedValue({} as never);
    renderScreen();
    await userEvent.type(await screen.findByLabelText(/Tên khóa học/), " mới");
    await userEvent.click(screen.getByRole("button", { name: "Xuất bản" }));
    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("Còn thay đổi chưa lưu");
    expect(api.publishCourse).not.toHaveBeenCalled();
    await userEvent.click(within(dialog).getByRole("button", { name: "Tiếp tục" }));
    await waitFor(() => expect(api.publishCourse).toHaveBeenCalledWith(5));
  });

  it("R7: đổi sang tab Thông tin chung khi form bài còn thay đổi chưa lưu thì hỏi xác nhận", async () => {
    nav.search = "tab=chuong-bai&bai=1";
    vi.mocked(api.getCourse).mockResolvedValue(detail());
    vi.mocked(curriculumApi.getCurriculum).mockResolvedValue({
      course_id: 5,
      chapters: [
        {
          id: 10,
          course_id: 5,
          title: "Chương 1",
          position: 1,
          lessons: [
            { id: 1, course_id: 5, chapter_id: 10, title: "Bài 1", position: 1, is_preview: false, video_source: "none", duration_seconds: null, external_provider: null, external_video_id: null, external_embed_url: null, has_video_asset: false, video_status: null },
          ],
        },
      ],
    });
    renderScreen();
    const name = await screen.findByLabelText(/Tên bài học/);
    await userEvent.type(name, " sửa dở");
    const tabs = screen.getByRole("navigation", { name: "Phần của khóa học" });
    await userEvent.click(within(tabs).getByRole("link", { name: "Thông tin chung" }));
    const dlg = await screen.findByRole("dialog");
    expect(dlg).toHaveTextContent("Còn thay đổi chưa lưu");
    await userEvent.click(within(dlg).getByRole("button", { name: "Ở lại để lưu" }));
    expect(push).not.toHaveBeenCalled();
    await userEvent.click(within(tabs).getByRole("link", { name: "Thông tin chung" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "Bỏ thay đổi" }));
    expect(push).toHaveBeenCalledWith("/quan-tri/khoa-hoc/5/sua");
  });
});
