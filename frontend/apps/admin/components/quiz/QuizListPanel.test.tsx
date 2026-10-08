import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import * as curriculum from "@/lib/curriculum/api";
import * as api from "@/lib/quiz/api";
import type { QuizItem } from "@/lib/quiz/types";
import { QuizListPanel } from "./QuizListPanel";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
vi.mock("@/lib/quiz/api");
vi.mock("@/lib/curriculum/api");

const quiz = (id: number, over: Partial<QuizItem> = {}): QuizItem => ({
  id,
  course_id: 3,
  chapter_id: 10,
  lesson_id: null,
  parent_type: "chapter",
  parent_title: "Chương 1",
  title: `Bài tập ${id}`,
  time_limit_minutes: 15,
  position: id,
  questions_count: 4,
  ...over,
});
const lessonRow = { id: 1, course_id: 3, chapter_id: 10, title: "Bài 1", position: 1, is_preview: false, video_source: "none" as const, duration_seconds: null, external_provider: null, external_video_id: null, external_embed_url: null, has_video_asset: false, video_status: null };
const setup = () => render(<ToastProvider><QuizListPanel courseId={3} /></ToastProvider>);

beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(curriculum.getCurriculum).mockResolvedValue({ course_id: 3, chapters: [{ id: 10, course_id: 3, title: "Chương 1", position: 1, lessons: [lessonRow] }] });
});

describe("QuizListPanel", () => {
  it("liệt kê quiz, cảnh báo quiz chưa có câu", async () => {
    vi.mocked(api.listQuizzes).mockResolvedValue([quiz(1), quiz(2, { questions_count: 0, time_limit_minutes: null, title: "Rỗng" })]);
    setup();
    const link = await screen.findByRole("link", { name: "Bài tập 1" });
    expect(link).toHaveAttribute("href", "/quan-tri/khoa-hoc/3/bai-tap/1");
    expect(screen.getByText("1 bài tập chưa có câu hỏi")).toBeInTheDocument();
    expect(screen.getByText("Không giới hạn")).toBeInTheDocument();
  });

  it("rỗng: trạng thái rỗng có nút tạo", async () => {
    vi.mocked(api.listQuizzes).mockResolvedValue([]);
    setup();
    expect(await screen.findByText("Khóa học chưa có bài tập")).toBeInTheDocument();
  });

  it("403 và lỗi tải khác có trạng thái riêng, thử lại được", async () => {
    vi.mocked(api.listQuizzes).mockRejectedValueOnce(new ApiError(500, { message: "Lỗi máy chủ" })).mockResolvedValueOnce([quiz(1)]);
    setup();
    await userEvent.click(await screen.findByRole("button", { name: "Thử lại" }));
    expect(await screen.findByRole("link", { name: "Bài tập 1" })).toBeInTheDocument();
  });

  it("403: không có quyền", async () => {
    vi.mocked(api.listQuizzes).mockRejectedValue(new ApiError(403, { message: "x" }));
    setup();
    expect(await screen.findByTestId("quiz-forbidden")).toBeInTheDocument();
  });

  it("tạo bài tập: 422 dưới ô, rồi thành công thêm vào bảng", async () => {
    vi.mocked(api.listQuizzes).mockResolvedValue([]);
    vi.mocked(api.createQuiz)
      .mockRejectedValueOnce(new ApiError(422, { message: "x", code: "VALIDATION_FAILED", errors: { title: ["Tên đã dùng."] } }))
      .mockResolvedValueOnce(quiz(9, { title: "Mới tạo", time_limit_minutes: 20 }));
    setup();
    await userEvent.click((await screen.findAllByRole("button", { name: /Tạo bài tập/ }))[0]!);
    const dlg = await screen.findByRole("dialog");
    await userEvent.type(within(dlg).getByRole("textbox", { name: /Tên bài tập/ }), "Mới tạo");
    await waitFor(() => expect(within(dlg).getByRole("combobox")).toBeInTheDocument());
    await userEvent.selectOptions(within(dlg).getByRole("combobox"), "chapter:10");
    const timeInput = within(dlg).getByRole("spinbutton");
    await userEvent.clear(timeInput);
    await userEvent.type(timeInput, "20");
    await userEvent.click(within(dlg).getByRole("button", { name: "Tạo bài tập" }));
    expect(await within(dlg).findByText("Tên đã dùng.")).toBeInTheDocument();
    expect(api.createQuiz).toHaveBeenCalledWith(3, { title: "Mới tạo", chapter_id: 10, time_limit_minutes: 20 });
    await userEvent.click(within(dlg).getByRole("button", { name: "Tạo bài tập" }));
    expect(await screen.findByRole("link", { name: "Mới tạo" })).toBeInTheDocument();
  });

  it("kiểm tại chỗ: thiếu 'Gắn với' và thời gian ngoài 1–300", async () => {
    vi.mocked(api.listQuizzes).mockResolvedValue([]);
    setup();
    await userEvent.click((await screen.findAllByRole("button", { name: /Tạo bài tập/ }))[0]!);
    const dlg = await screen.findByRole("dialog");
    await waitFor(() => expect(within(dlg).getByRole("combobox")).toBeInTheDocument());
    const timeInput = within(dlg).getByRole("spinbutton");
    await userEvent.clear(timeInput);
    await userEvent.type(timeInput, "301");
    await userEvent.click(within(dlg).getByRole("button", { name: "Tạo bài tập" }));
    expect(await within(dlg).findByText("Vui lòng nhập tên bài tập.")).toBeInTheDocument();
    expect(within(dlg).getByText(/Chọn chương hoặc bài học/)).toBeInTheDocument();
    expect(within(dlg).getByText("Thời gian từ 1 đến 300 phút.")).toBeInTheDocument();
    expect(api.createQuiz).not.toHaveBeenCalled();
  });

  it("xoá bài tập có xác nhận", async () => {
    vi.mocked(api.listQuizzes).mockResolvedValue([quiz(1), quiz(2)]);
    vi.mocked(api.deleteQuiz).mockResolvedValue(undefined);
    setup();
    await userEvent.click(await screen.findByRole("button", { name: "Xoá bài tập Bài tập 1" }));
    const dlg = await screen.findByRole("dialog");
    expect(api.deleteQuiz).not.toHaveBeenCalled();
    await userEvent.click(within(dlg).getByRole("button", { name: "Xoá bài tập" }));
    await waitFor(() => expect(screen.queryByRole("link", { name: "Bài tập 1" })).toBeNull());
    expect(api.deleteQuiz).toHaveBeenCalledWith(3, 1);
  });
});
