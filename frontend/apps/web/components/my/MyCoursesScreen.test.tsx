import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { MyCourses } from "@/lib/my/schemas";

const mocks = vi.hoisted(() => ({
  fetchMyCourses: vi.fn(),
  replace: vi.fn(),
  auth: { state: { status: "user" } as { status: string }, refresh: vi.fn() },
}));
vi.mock("@/lib/my/api", () => ({ fetchMyCourses: mocks.fetchMyCourses, fetchCourseProgress: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: mocks.replace }), usePathname: () => "/tai-khoan/khoa-hoc-cua-toi" }));
vi.mock("@/lib/auth/AuthProvider", () => ({ useAuth: () => mocks.auth }));

import { MyCoursesScreen } from "./MyCoursesScreen";

const ref = (id: number, title: string, extra = {}) => ({ id, title, slug: `khoa-${id}`, grade_level: 9, thumbnail_url: null, is_published: true, ...extra });
const base: MyCourses = {
  data: [
    {
      course: ref(1, "Hình học 9"),
      enrollment: { id: 10, status: "active", activated_at: "2026-09-20T10:00:00+07:00", last_accessed_at: "2026-10-06T19:28:00+07:00" },
      progress: { percent: 30, completed_lessons: 6, total_lessons: 20, is_completed: false, has_content: true },
      resume_lesson_id: 77,
      best_quiz_score: 7.5,
    },
    {
      course: ref(2, "Căn bậc hai", { is_published: false }),
      enrollment: { id: 11, status: "active", activated_at: null, last_accessed_at: "2026-10-01T10:00:00+07:00" },
      progress: { percent: 100, completed_lessons: 5, total_lessons: 5, is_completed: true, has_content: true },
      resume_lesson_id: 88,
      best_quiz_score: null,
    },
    {
      course: ref(3, "Khóa rỗng"),
      enrollment: { id: 12, status: "active", activated_at: null, last_accessed_at: null },
      progress: { percent: 0, completed_lessons: 0, total_lessons: 0, is_completed: false, has_content: false },
      resume_lesson_id: null,
      best_quiz_score: null,
    },
  ],
  meta: { current_page: 1, per_page: 12, total: 3, last_page: 1 },
  pending: [{ enrollment_id: 20, status: "pending_approval", course: ref(4, "Số học 6"), requested_at: "2026-10-05T16:20:00+07:00" }],
  rejected: [{ enrollment_id: 21, status: "rejected", course: ref(5, "Bồi dưỡng HSG"), requested_at: "2026-09-28T10:00:00+07:00", rejection_reason: "Khóa dành cho lớp chọn." }],
};

describe("MyCoursesScreen", () => {
  beforeEach(() => {
    mocks.fetchMyCourses.mockReset();
    mocks.replace.mockReset();
    mocks.auth.state = { status: "user" };
  });

  it("hiện tiến độ đúng (AC1), trạng thái bằng chữ, Học tiếp tới bài resume", async () => {
    mocks.fetchMyCourses.mockResolvedValue(base);
    render(<MyCoursesScreen page={1} />);
    expect(await screen.findAllByText("6/20 bài · 30%")).toHaveLength(2); // banner + thẻ
    expect(screen.getByText("Đã hoàn thành")).toBeInTheDocument(); // AC2
    expect(screen.getByText("Chưa có nội dung")).toBeInTheDocument(); // 0 bài
    expect(screen.getByText("Điểm trắc nghiệm cao nhất: 7,5/10")).toBeInTheDocument();
    expect(screen.getByText(/Khóa đã ngừng bán/)).toBeInTheDocument();
    const resume = screen.getAllByRole("link", { name: "Tiếp tục học" });
    expect(resume.every((a) => a.getAttribute("href") === "/hoc/1/bai/77")).toBe(true);
    expect(screen.getByRole("link", { name: "Xem lại" })).toHaveAttribute("href", "/hoc/2/bai/88");
    expect(mocks.fetchMyCourses).toHaveBeenCalledWith(1, expect.anything());
  });

  it("danh sách rỗng (AC3): gợi ý + liên kết danh mục", async () => {
    mocks.fetchMyCourses.mockResolvedValue({ ...base, data: [], pending: [], rejected: [], meta: { current_page: 1, per_page: 12, total: 0, last_page: 1 } });
    render(<MyCoursesScreen page={1} />);
    expect(await screen.findByText("Bạn chưa có khóa học nào")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Khám phá khóa học" })).toHaveAttribute("href", "/khoa-hoc");
  });

  it("chỉ có yêu cầu chờ duyệt: mở thẳng tab Chờ duyệt", async () => {
    mocks.fetchMyCourses.mockResolvedValue({ ...base, data: [], rejected: [], meta: { current_page: 1, per_page: 12, total: 0, last_page: 1 } });
    render(<MyCoursesScreen page={1} />);
    expect(await screen.findByText("Đang chờ duyệt")).toBeInTheDocument();
    expect(screen.getByText(/Bạn sẽ nhận email khi được duyệt/)).toBeInTheDocument();
  });

  it("tab Không được duyệt hiện lý do", async () => {
    mocks.fetchMyCourses.mockResolvedValue(base);
    render(<MyCoursesScreen page={1} />);
    fireEvent.click(await screen.findByRole("tab", { name: /Không được duyệt/ }));
    expect(await screen.findByText("Khóa dành cho lớp chọn.")).toBeInTheDocument();
  });

  it("lỗi mạng/5xx: báo + Thử lại gọi lại API", async () => {
    mocks.fetchMyCourses.mockRejectedValueOnce(new ApiError(500, { message: "x" })).mockResolvedValueOnce(base);
    render(<MyCoursesScreen page={1} />);
    expect(await screen.findByText("Không tải được danh sách khóa học của bạn")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Thử lại" }));
    await waitFor(() => expect(screen.getAllByText("Hình học 9").length).toBeGreaterThan(0));
    expect(mocks.fetchMyCourses).toHaveBeenCalledTimes(2);
  });

  it("429 báo thao tác nhanh; 401 chỉ báo ngắn (hộp thoại phiên do SessionEndedGate)", async () => {
    mocks.fetchMyCourses.mockRejectedValueOnce(new ApiError(429, { message: "x" }));
    const { unmount } = render(<MyCoursesScreen page={1} />);
    expect(await screen.findByText("Bạn thao tác hơi nhanh")).toBeInTheDocument();
    unmount();
    mocks.fetchMyCourses.mockRejectedValueOnce(new ApiError(401, { message: "x" }));
    render(<MyCoursesScreen page={1} />);
    expect(await screen.findByText("Phiên đăng nhập đã kết thúc")).toBeInTheDocument();
  });

  it("khách: chuyển tới đăng nhập, không gọi API", async () => {
    mocks.auth.state = { status: "guest" };
    render(<MyCoursesScreen page={1} />);
    await waitFor(() => expect(mocks.replace).toHaveBeenCalledWith(expect.stringContaining("/dang-nhap")));
    expect(mocks.fetchMyCourses).not.toHaveBeenCalled();
  });

  it("phân trang: trang 2 gọi API với page=2 và có liên kết Trước", async () => {
    mocks.fetchMyCourses.mockResolvedValue({ ...base, meta: { current_page: 2, per_page: 12, total: 20, last_page: 2 } });
    render(<MyCoursesScreen page={2} />);
    await screen.findAllByText("Hình học 9");
    expect(mocks.fetchMyCourses).toHaveBeenCalledWith(2, expect.anything());
    expect(screen.getByRole("link", { name: /Trước/ })).toHaveAttribute("href", "/tai-khoan/khoa-hoc-cua-toi");
    expect(screen.queryByText("Học tiếp")).toBeNull(); // banner chỉ ở trang đầu
  });

  it("trang vượt last_page nhưng còn chờ duyệt/bị từ chối: tab Đang học vẫn mặc định, báo trang trống + Về trang đầu", async () => {
    mocks.fetchMyCourses.mockResolvedValue({ ...base, data: [], meta: { current_page: 99, per_page: 12, total: 13, last_page: 2 } });
    render(<MyCoursesScreen page={99} />);
    expect(await screen.findByText("Trang này không có khóa học")).toBeInTheDocument();
    expect(screen.queryByText("Chưa có khóa nào đang học")).toBeNull();
    expect(screen.getByRole("link", { name: "Về trang đầu" })).toHaveAttribute("href", "/tai-khoan/khoa-hoc-cua-toi");
    expect(screen.getByRole("tab", { name: /Đang học/ })).toHaveAttribute("aria-selected", "true");
  });
});
