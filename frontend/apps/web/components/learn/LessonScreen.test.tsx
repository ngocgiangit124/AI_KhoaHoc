import { act, fireEvent, render, screen } from "@testing-library/react";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { beforeEach, describe, expect, it, vi } from "vitest";

const router = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/hoc/1/bai/2" }));
const api = vi.hoisted(() => ({ fetchLesson: vi.fn(), fetchLearnCourse: vi.fn() }));
vi.mock("@/lib/learn/api", () => api);
vi.mock("./VideoPlayer", () => ({
  VideoPlayer: ({ onRevoked }: { onRevoked?: (c: { slug: string; title: string } | null) => void }) => (
    <button onClick={() => onRevoked?.(revokedCourse)}>giả lập thu hồi</button>
  ),
}));
let revokedCourse: { slug: string; title: string } | null = null;
vi.mock("@/lib/auth/AuthProvider", () => ({ useOptionalAuth: () => null }));

import { LessonScreen } from "./LessonScreen";

async function mount() {
  render(
    <ToastProvider>
      <LessonScreen courseId={1} lessonId={2} />
    </ToastProvider>,
  );
  await act(async () => {});
}

describe("LessonScreen — chưa sở hữu khóa (403 COURSE_NOT_OWNED)", () => {
  beforeEach(() => {
    router.replace.mockReset();
    api.fetchLearnCourse.mockRejectedValue(new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED" }));
  });

  it("có errors.course (khóa published) → chuyển về /khoa-hoc/{slug}", async () => {
    api.fetchLesson.mockRejectedValue(
      new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED", errors: { course: { id: 1, slug: "hinh-hoc-9", title: "Hình học 9" } } as never }),
    );
    await mount();
    expect(router.replace).toHaveBeenCalledWith("/khoa-hoc/hinh-hoc-9");
    expect(screen.getByRole("link", { name: "Tới trang khóa học" })).toHaveAttribute("href", "/khoa-hoc/hinh-hoc-9");
  });

  it("không có errors.course (khóa nháp/ẩn) → ở lại màn thông báo + nút về danh mục, không chuyển hướng", async () => {
    api.fetchLesson.mockRejectedValue(new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED" }));
    await mount();
    expect(router.replace).not.toHaveBeenCalled();
    expect(screen.getByRole("heading", { level: 1, name: "Bạn chưa sở hữu khóa học này" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Xem danh sách khóa học" })).toHaveAttribute("href", "/khoa-hoc");
  });

  it("slug sai định dạng (cố chèn đường dẫn lạ) bị bỏ qua", async () => {
    api.fetchLesson.mockRejectedValue(
      new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED", errors: { course: { id: 1, slug: "../dang-nhap", title: "x" } } as never }),
    );
    await mount();
    expect(router.replace).not.toHaveBeenCalled();
  });
});

describe("LessonScreen — thu hồi quyền giữa phiên", () => {
  const lesson = {
    lesson: { id: 2, course_id: 1, chapter_id: 1, chapter_title: "C1", title: "Bài 1", position: 1, is_preview: false, duration_seconds: 24, video_ready: true },
    course: { id: 1, title: "K", slug: "k" },
    can_track: true,
    prev: null,
    next: null,
    quizzes: [],
    progress: null,
  };
  beforeEach(() => {
    router.replace.mockReset();
    api.fetchLesson.mockResolvedValue(lesson);
    api.fetchLearnCourse.mockRejectedValue(new ApiError(500, { message: "x" }));
  });

  it("có errors.course: hiện thông báo + nút 'Xem khóa học' tới /khoa-hoc/{slug}, KHÔNG tự chuyển trang", async () => {
    revokedCourse = { slug: "hinh-hoc-9", title: "H9" };
    await mount();
    fireEvent.click(screen.getByText("giả lập thu hồi"));
    expect(screen.getByText("Quyền học khóa này đã bị thu hồi")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Xem khóa học" })).toHaveAttribute("href", "/khoa-hoc/hinh-hoc-9");
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("không có errors.course: chỉ thông báo, không có nút", async () => {
    revokedCourse = null;
    await mount();
    fireEvent.click(screen.getByText("giả lập thu hồi"));
    expect(screen.getByText("Quyền học khóa này đã bị thu hồi")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Xem khóa học" })).toBeNull();
  });
});
