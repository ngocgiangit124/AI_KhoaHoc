import { render, screen, waitFor, within } from "@testing-library/react";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { CourseProgress } from "@/lib/my/schemas";

const mocks = vi.hoisted(() => ({ fetchCourseProgress: vi.fn(), replace: vi.fn() }));
vi.mock("@/lib/my/api", () => ({ fetchCourseProgress: mocks.fetchCourseProgress, fetchMyCourses: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: mocks.replace }), usePathname: () => "/" }));
vi.mock("@/lib/auth/AuthProvider", () => ({ useAuth: () => ({ state: { status: "user" }, refresh: vi.fn() }) }));

import { CourseProgressScreen } from "./CourseProgressScreen";

const data: CourseProgress = {
  course: { id: 9, title: "Hình học 9", slug: "hinh-hoc-9", grade_level: 9, thumbnail_url: null, is_published: true },
  enrollment: { activated_at: null, last_accessed_at: "2026-10-06T19:28:00+07:00" },
  progress: { percent: 50, completed_lessons: 1, total_lessons: 2, is_completed: false, has_content: true },
  resume_lesson_id: 22,
  chapters: [
    {
      id: 1,
      title: "Chương 1",
      position: 1,
      completed_lessons: 1,
      total_lessons: 2,
      lessons: [
        { id: 21, title: "Bài 1", position: 1, duration_seconds: 600, status: "completed", watched_seconds: 600, completed_at: "2026-10-05T10:00:00+07:00" },
        { id: 22, title: "Bài 2", position: 2, duration_seconds: null, status: "in_progress", watched_seconds: 30, completed_at: null },
      ],
    },
  ],
  quizzes: [
    { id: 501, title: "Trắc nghiệm A", chapter_id: null, lesson_id: 21, question_count: 6, attempted: true, attempts_count: 2, best_score: 8.33 },
    { id: 502, title: "Đề B", chapter_id: 1, lesson_id: null, question_count: 20, attempted: false, attempts_count: 0, best_score: null },
  ],
};

describe("CourseProgressScreen", () => {
  beforeEach(() => mocks.fetchCourseProgress.mockReset());

  it("hiện tiến độ, điểm cao nhất thang 10, số lượt, liên kết quiz và kết quả (AC5)", async () => {
    mocks.fetchCourseProgress.mockResolvedValue(data);
    render(<CourseProgressScreen courseId={9} />);
    expect(await screen.findByRole("heading", { level: 1, name: "Hình học 9" })).toBeInTheDocument();
    expect(screen.getByText("1/2 bài · 50%")).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: /Tiếp tục học/ })[0]).toHaveAttribute("href", "/hoc/9/bai/22");
    const table = screen.getByRole("table"); // bảng (md+); bản thẻ (dưới md) cùng dữ liệu nằm ở danh sách riêng
    const cards = within(screen.getByRole("list", { name: "Điểm các bài kiểm tra của khóa" }));
    expect(cards.getAllByText("Điểm cao nhất:")).toHaveLength(2);
    expect(cards.getByText("6 câu · 2 lượt đã làm")).toBeInTheDocument();
    const rowA = within(table).getByText("Trắc nghiệm A").closest("tr") as HTMLElement;
    expect(within(rowA).getByText("8,33/10")).toBeInTheDocument();
    expect(within(rowA).getByRole("link", { name: /Làm lại/ })).toHaveAttribute("href", "/hoc/9/quiz/501");
    expect(within(rowA).getByRole("link", { name: /Xem kết quả/ })).toHaveAttribute("href", "/hoc/9/quiz/501/ket-qua");
    const rowB = within(table).getByText("Đề B").closest("tr") as HTMLElement;
    expect(within(rowB).getByText("Chưa làm")).toBeInTheDocument();
    expect(within(rowB).queryByRole("link", { name: /Xem kết quả/ })).toBeNull();
    expect(within(rowB).getByRole("link", { name: /Làm bài/ })).toHaveAttribute("href", "/hoc/9/quiz/502");
    expect(mocks.fetchCourseProgress).toHaveBeenCalledWith(9, expect.anything());
  });

  it("khóa đã hoàn thành: nhãn 'Đã hoàn thành'", async () => {
    mocks.fetchCourseProgress.mockResolvedValue({ ...data, progress: { ...data.progress, percent: 100, completed_lessons: 2, is_completed: true } });
    render(<CourseProgressScreen courseId={9} />);
    expect((await screen.findAllByText("Đã hoàn thành")).length).toBeGreaterThan(0);
    expect(screen.getByRole("link", { name: /Xem lại bài học/ })).toBeInTheDocument();
  });

  it("khóa chưa có quiz: trạng thái rỗng", async () => {
    mocks.fetchCourseProgress.mockResolvedValue({ ...data, quizzes: [] });
    render(<CourseProgressScreen courseId={9} />);
    expect(await screen.findByText("Khóa học chưa có bài kiểm tra")).toBeInTheDocument();
    expect(screen.queryByRole("table")).toBeNull();
  });

  it("403 COURSE_NOT_OWNED kèm khóa: liên kết tới trang khóa học", async () => {
    mocks.fetchCourseProgress.mockRejectedValueOnce(new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED", errors: { course: { id: 9, slug: "hinh-hoc-9", title: "H" } } as unknown as Record<string, string[]> }));
    render(<CourseProgressScreen courseId={9} />);
    expect(await screen.findByText("Bạn chưa sở hữu khóa học này")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Tới trang khóa học" })).toHaveAttribute("href", "/khoa-hoc/hinh-hoc-9");
  });

  it("404 và lỗi chung", async () => {
    mocks.fetchCourseProgress.mockRejectedValueOnce(new ApiError(404, { message: "x" }));
    const { unmount } = render(<CourseProgressScreen courseId={9} />);
    expect(await screen.findByText("Không tìm thấy khóa học")).toBeInTheDocument();
    unmount();
    mocks.fetchCourseProgress.mockRejectedValueOnce(new ApiError(503, { message: "x" }));
    render(<CourseProgressScreen courseId={9} />);
    await waitFor(() => expect(screen.getByText("Không tải được tiến độ khóa học")).toBeInTheDocument());
  });
});
