import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/courses/api";
import type { CourseListItem, CoursePage } from "@/lib/courses/types";
import { CoursesScreen } from "./CoursesScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const replace = vi.fn();
const push = vi.fn();
let search = "";
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push }),
  usePathname: () => "/quan-tri/khoa-hoc",
  useSearchParams: () => new URLSearchParams(search),
}));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/courses/api");

const course = (id: number, title: string, extra: Partial<CourseListItem> = {}): CourseListItem => ({
  id,
  title,
  slug: `c-${id}`,
  short_description: null,
  grade_level: 9,
  price: 0,
  thumbnail_url: null,
  status: "draft",
  published_at: null,
  manual_order: null,
  enrollments_count: 0,
  subjects: [{ id: 1, name: "Đại số", slug: "dai-so" }],
  teachers: [{ id: 7, name: "Cô Lan" }],
  created_by: 1,
  created_at: null,
  updated_at: null,
  ...extra,
});
const pageOf = (data: CourseListItem[], lastPage = 1): CoursePage => ({
  data,
  meta: { current_page: 1, per_page: 25, total: data.length, last_page: lastPage },
  links: { next: null, prev: null },
});
function setUser(role: StaffUser["role"]) {
  const user: StaffUser = { id: 1, name: "N", email: null, role, permissions: null, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}
const wrap = (ui: ReactNode) => <ToastProvider>{ui}</ToastProvider>;
const renderScreen = () => render(wrap(<CoursesScreen />));

beforeEach(() => {
  vi.resetAllMocks();
  search = "";
  setUser("quan_ly_trang");
  vi.mocked(api.listActiveSubjects).mockResolvedValue([{ id: 1, name: "Đại số" }]);
  vi.mocked(api.listTeachers).mockResolvedValue([{ id: 7, name: "Cô Lan" }]);
});

describe("CoursesScreen", () => {
  it("tên hiển thị như văn bản thuần; giá 0 là Miễn phí; trạng thái bằng chữ", async () => {
    vi.mocked(api.listCourses).mockResolvedValue(pageOf([course(1, "<img src=x onerror=alert(1)>", { status: "published" })]));
    const { container } = renderScreen();
    expect(await screen.findByText("<img src=x onerror=alert(1)>")).toBeInTheDocument();
    expect(container.querySelector("img")).toBeNull();
    // Giá/trạng thái có ở cột riêng (≥ md) và ở dòng phụ trong ô tên (< md); CSS chọn cái hiện.
    expect(screen.getAllByText("Miễn phí").length).toBeGreaterThan(0);
    expect(within(screen.getByRole("table")).getAllByText("Đã xuất bản").length).toBeGreaterThan(0);
  });

  it("staff thấy bộ lọc giáo viên và cột giáo viên; gọi API kèm teacher_id từ URL", async () => {
    search = "teacher_id=7&grade_level=9";
    vi.mocked(api.listCourses).mockResolvedValue(pageOf([course(1, "Hình học", { manual_order: 3 })]));
    renderScreen();
    await screen.findByText("Hình học");
    expect(screen.getByLabelText("Giáo viên")).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: "Giáo viên" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Khóa học", level: 1 })).toBeInTheDocument();
    // Thao tác ghi (xuất bản/xoá/thứ tự) nằm ở trang sửa, không còn trong danh sách.
    expect(screen.queryByRole("button", { name: /Xuất bản|Xoá|Thứ tự|Ngừng bán/ })).toBeNull();
    expect(screen.getByRole("link", { name: "Sửa khóa học Hình học" })).toHaveAttribute("href", "/quan-tri/khoa-hoc/1/sua");
    expect(vi.mocked(api.listCourses).mock.calls[0]?.[0]).toMatchObject({ teacherId: 7, gradeLevel: 9 });
    expect(vi.mocked(api.listCourses).mock.calls[0]?.[1].isStaff).toBe(true);
  });

  it("giáo viên: tiêu đề 'Khóa học của tôi', không lọc/cột giáo viên, không gọi /admin/teachers", async () => {
    setUser("giao_vien");
    vi.mocked(api.listCourses).mockResolvedValue(pageOf([course(1, "Hình học")]));
    renderScreen();
    await screen.findByText("Hình học");
    expect(screen.getByRole("heading", { name: "Khóa học của tôi", level: 1 })).toBeInTheDocument();
    expect(screen.queryByLabelText("Giáo viên")).toBeNull();
    expect(screen.queryByRole("columnheader", { name: "Giáo viên" })).toBeNull();
    expect(screen.getByRole("link", { name: "Tạo khóa học" })).toHaveAttribute("href", "/quan-tri/khoa-hoc/tao");
    expect(api.listTeachers).not.toHaveBeenCalled();
    expect(vi.mocked(api.listCourses).mock.calls[0]?.[1].isStaff).toBe(false);
  });

  it("giáo viên chưa có khóa nào: trạng thái rỗng riêng", async () => {
    setUser("giao_vien");
    vi.mocked(api.listCourses).mockResolvedValue(pageOf([]));
    renderScreen();
    expect(await screen.findByText("Bạn chưa phụ trách khóa học nào")).toBeInTheDocument();
  });

  it("có bộ lọc mà không có kết quả: nói rõ và có nút xoá bộ lọc", async () => {
    search = "q=zzz";
    vi.mocked(api.listCourses).mockResolvedValue(pageOf([]));
    renderScreen();
    expect(await screen.findByText("Không có khóa học phù hợp với bộ lọc.")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Xoá bộ lọc" })).toHaveAttribute("href", "/quan-tri/khoa-hoc");
  });

  it("đổi bộ lọc lớp: về trang 1 và đẩy lên URL", async () => {
    search = "page=2";
    vi.mocked(api.listCourses).mockResolvedValue(pageOf([course(1, "Hình học")], 3));
    renderScreen();
    await screen.findByText("Hình học");
    await userEvent.selectOptions(screen.getByLabelText("Lớp"), "9");
    expect(replace).toHaveBeenCalledWith("/quan-tri/khoa-hoc?grade_level=9", { scroll: false });
  });

  it("phân trang theo liên kết giữ bộ lọc trên URL", async () => {
    search = "status=draft";
    vi.mocked(api.listCourses).mockResolvedValue(pageOf([course(1, "Hình học")], 3));
    renderScreen();
    await screen.findByText("Hình học");
    const nav = screen.getByRole("navigation", { name: "Phân trang" });
    expect(within(nav).getByRole("link", { name: /Sau/ })).toHaveAttribute("href", "/quan-tri/khoa-hoc?status=draft&page=2");
  });

  it("lỗi tải: báo lỗi và có nút Thử lại gọi lại API", async () => {
    vi.mocked(api.listCourses).mockRejectedValueOnce(new ApiError(500, { message: "Đã có lỗi xảy ra." })).mockResolvedValue(pageOf([course(1, "Hình học")]));
    renderScreen();
    expect(await screen.findByText("Không tải được danh sách khóa học")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Thử lại" }));
    expect(await screen.findByText("Hình học")).toBeInTheDocument();
    expect(api.listCourses).toHaveBeenCalledTimes(2);
  });

  it("lỗi 403 khi tải: trang không có quyền", async () => {
    vi.mocked(api.listCourses).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    renderScreen();
    expect(await screen.findByTestId("forbidden-view")).toBeInTheDocument();
  });

  it("URL đổi từ bên ngoài: ô tìm theo URL, không đẩy giá trị cũ lên lại (R1)", async () => {
    vi.mocked(api.listCourses).mockResolvedValue(pageOf([course(1, "Hình học")]));
    search = "q=abc";
    const { rerender } = renderScreen();
    const box = await screen.findByLabelText("Tìm theo tên");
    expect(box).toHaveValue("abc");
    search = "";
    rerender(wrap(<CoursesScreen />));
    await waitFor(() => expect(box).toHaveValue(""));
    await new Promise((r) => setTimeout(r, 450));
    expect(replace).not.toHaveBeenCalled();
  });
});
