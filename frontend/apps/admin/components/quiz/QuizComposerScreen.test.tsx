import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as courses from "@/lib/courses/api";
import * as api from "@/lib/quiz/api";
import type { QuizDetail, QuizQuestion } from "@/lib/quiz/types";
import { QuizComposerScreen, parseCau } from "./QuizComposerScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const nav = vi.hoisted(() => ({ search: "", replace: vi.fn(), push: vi.fn() }));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: nav.replace, push: nav.push }),
  usePathname: () => "/quan-tri/khoa-hoc/3/bai-tap/5",
  useSearchParams: () => new URLSearchParams(nav.search),
}));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/quiz/api");
vi.mock("@/lib/courses/api");
vi.mock("@/lib/curriculum/api");

const q = (id: number, position: number, over: Partial<QuizQuestion> = {}): QuizQuestion => ({
  id,
  quiz_id: 5,
  content: `Nội dung câu ${id}`,
  explanation: null,
  position,
  options: ["a", "b", "c", "d"].map((c, i) => ({ id: id * 10 + i, content: c, is_correct: i === 2, position: i + 1 })),
  ...over,
});
const quizOf = (questions: QuizQuestion[]): QuizDetail => ({
  id: 5,
  course_id: 3,
  chapter_id: 10,
  lesson_id: null,
  parent_type: "chapter",
  parent_title: "Chương 1",
  title: "Luyện tập",
  time_limit_minutes: null,
  position: 1,
  questions_count: questions.length,
  questions,
});
const setup = () =>
  render(
    <ToastProvider>
      <QuizComposerScreen courseId={3} quizId={5} />
    </ToastProvider>,
  );

beforeEach(() => {
  vi.resetAllMocks();
  nav.search = "";
  const user: StaffUser = { id: 1, name: "N", email: null, role: "giao_vien", permissions: null, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
  vi.mocked(courses.getCourse).mockResolvedValue({ id: 3, title: "Hình 9" } as never);
});

describe("parseCau", () => {
  it("chỉ nhận 'moi' hoặc id dương", () => {
    expect(parseCau("moi")).toBe("moi");
    expect(parseCau("12")).toBe(12);
    for (const bad of [null, "", "0", "-1", "abc", "1.5", "12345678901"]) expect(parseCau(bad)).toBeNull();
  });
});

describe("QuizComposerScreen", () => {
  it("danh sách đánh số theo thứ tự (không theo position), hiện đáp án đúng và đếm câu", async () => {
    vi.mocked(api.getQuiz).mockResolvedValue(quizOf([q(1, 1), q(2, 4, { explanation: "gs" })]));
    setup();
    const list = await screen.findByTestId("question-list");
    const items = within(list).getAllByRole("listitem");
    expect(items).toHaveLength(2);
    expect(items[1]).toHaveTextContent("Đáp án đúng C:");
    expect(items[1]).toHaveTextContent("Có lời giải");
    expect(items[0]).toHaveTextContent("Chưa có lời giải");
    expect(screen.getByTestId("question-count")).toHaveTextContent("2/200 câu");
    expect(screen.getByText("Khóa học của tôi")).toBeInTheDocument();
  });

  it("quiz chưa có câu: trạng thái rỗng", async () => {
    vi.mocked(api.getQuiz).mockResolvedValue(quizOf([]));
    setup();
    expect(await screen.findByText("Bài tập chưa có câu hỏi")).toBeInTheDocument();
  });

  it("đủ 200 câu: khoá nút thêm và giải thích", async () => {
    vi.mocked(api.getQuiz).mockResolvedValue(quizOf(Array.from({ length: 200 }, (_, i) => q(i + 1, i + 1))));
    setup();
    // 200 dòng render chậm khi cả bộ test chạy song song → nới thời gian chờ.
    expect(await screen.findByRole("button", { name: "Thêm câu hỏi" }, { timeout: 10_000 })).toBeDisabled();
    expect(screen.getByText(/tối đa 200 câu/)).toBeInTheDocument();
  }, 20_000);

  it("?cau= không tồn tại → thông báo, không ném lỗi", async () => {
    nav.search = "cau=999";
    vi.mocked(api.getQuiz).mockResolvedValue(quizOf([q(1, 1)]));
    setup();
    expect(await screen.findByTestId("question-missing")).toBeInTheDocument();
  });

  it("403 và 404 có trạng thái riêng", async () => {
    vi.mocked(api.getQuiz).mockRejectedValueOnce(new ApiError(403, { message: "x" }));
    const { unmount } = setup();
    expect(await screen.findByTestId("quiz-forbidden")).toBeInTheDocument();
    unmount();
    vi.mocked(api.getQuiz).mockRejectedValueOnce(new ApiError(404, { message: "x" }));
    setup();
    expect(await screen.findByTestId("quiz-not-found")).toBeInTheDocument();
  });

  it("copy-on-write: lưu trả id mới → thay câu trong danh sách và chuyển URL sang id mới", async () => {
    nav.search = "cau=1";
    vi.mocked(api.getQuiz).mockResolvedValue(quizOf([q(1, 1), q(2, 2)]));
    vi.mocked(api.updateQuestion).mockResolvedValue(q(50, 1, { content: "Đã sửa" }));
    setup();
    const box = await screen.findByRole("textbox", { name: /Nội dung câu hỏi/ });
    await userEvent.type(box, "!");
    await userEvent.click(screen.getByRole("button", { name: "Lưu câu hỏi" }));
    await waitFor(() => expect(nav.replace).toHaveBeenCalledWith("/quan-tri/khoa-hoc/3/bai-tap/5?cau=50", { scroll: false }));
    expect(api.updateQuestion).toHaveBeenCalledWith(3, 5, 1, expect.anything());
  });

  it("đổi thứ tự bằng nút Xuống: gửi đủ id theo thứ tự mới, cập nhật danh sách", async () => {
    vi.mocked(api.getQuiz).mockResolvedValueOnce(quizOf([q(1, 1), q(2, 2), q(3, 3)])).mockResolvedValue(quizOf([q(2, 1), q(1, 2), q(3, 3)]));
    vi.mocked(api.reorderQuestions).mockResolvedValue([q(2, 1), q(1, 2), q(3, 3)]);
    setup();
    await screen.findByTestId("question-list");
    expect(screen.getByRole("button", { name: "Chuyển câu 1 lên" })).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByRole("button", { name: "Chuyển câu 3 xuống" })).toHaveAttribute("aria-disabled", "true");
    await userEvent.click(screen.getByRole("button", { name: "Chuyển câu 1 xuống" }));
    await waitFor(() => expect(api.reorderQuestions).toHaveBeenCalledWith(3, 5, [2, 1, 3]));
    const items = within(screen.getByTestId("question-list")).getAllByRole("listitem");
    expect(items[0]).toHaveTextContent("Nội dung câu 2");
    expect(screen.queryByText(/Chưa đổi được thứ tự/)).toBeNull();
  });

  it("422 QUIZ_QUESTIONS_MISMATCH: hoàn lại, tải lại danh sách, báo người dùng", async () => {
    vi.mocked(api.getQuiz).mockResolvedValueOnce(quizOf([q(1, 1), q(2, 2)])).mockResolvedValueOnce(quizOf([q(1, 1), q(2, 2), q(7, 3)]));
    vi.mocked(api.reorderQuestions).mockRejectedValue(new ApiError(422, { message: "x", code: "QUIZ_QUESTIONS_MISMATCH" }));
    setup();
    await screen.findByTestId("question-list");
    await userEvent.click(screen.getByRole("button", { name: "Chuyển câu 2 lên" }));
    expect(await screen.findByText(/Đã tải lại danh sách mới/)).toBeInTheDocument();
    await waitFor(() => expect(within(screen.getByTestId("question-list")).getAllByRole("listitem")).toHaveLength(3));
  });

  it("BUG-1: tải lại gặp 404 (quiz bị xoá ở tab khác) → màn 'Không tìm thấy bài tập', bỏ dữ liệu cũ", async () => {
    nav.search = "cau=moi";
    vi.mocked(api.getQuiz).mockResolvedValueOnce(quizOf([])).mockRejectedValueOnce(new ApiError(404, { message: "x" }));
    vi.mocked(api.createQuestion).mockRejectedValue(new ApiError(404, { message: "x" }));
    setup();
    await userEvent.type(await screen.findByRole("textbox", { name: /Nội dung câu hỏi/ }), "Q");
    for (let i = 0; i < 4; i++) await userEvent.type(document.getElementById(`q-o${i}`)!, `a${i}`);
    await userEvent.click(screen.getByRole("radio", { name: /đáp án A/ }));
    await userEvent.click(screen.getByRole("button", { name: "Thêm câu hỏi" }));
    expect(await screen.findByTestId("quiz-not-found")).toBeInTheDocument();
  });

  it("R6: trong lúc PUT order chưa trả về thì khoá 'Sửa' và 'Thêm câu hỏi'; xong thì tải lại từ server", async () => {
    vi.mocked(api.getQuiz).mockResolvedValue(quizOf([q(1, 1), q(2, 2)]));
    let resolve!: (v: QuizQuestion[]) => void;
    vi.mocked(api.reorderQuestions).mockReturnValue(new Promise((r) => (resolve = r)));
    setup();
    await screen.findByTestId("question-list");
    await userEvent.click(screen.getByRole("button", { name: "Chuyển câu 1 xuống" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Thêm câu hỏi" })).toBeDisabled());
    expect(screen.queryByRole("link", { name: /^Sửa/ })).toBeNull();
    expect(screen.queryByRole("link", { name: "Thêm câu hỏi" })).toBeNull();
    expect(api.getQuiz).toHaveBeenCalledTimes(1);
    resolve([q(2, 1), q(1, 2)]);
    await waitFor(() => expect(api.getQuiz).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.getAllByRole("link", { name: /^Sửa/ })).toHaveLength(2));
    expect(screen.getByRole("link", { name: "Thêm câu hỏi" })).toBeInTheDocument();
  });

  it("R8: bấm nhanh nhiều lần chỉ một toast 'Đã lưu thứ tự câu'", async () => {
    vi.mocked(api.getQuiz).mockResolvedValue(quizOf([q(1, 1), q(2, 2), q(3, 3)]));
    vi.mocked(api.reorderQuestions).mockResolvedValue([q(2, 1), q(1, 2), q(3, 3)]);
    setup();
    await screen.findByTestId("question-list");
    await userEvent.click(screen.getByRole("button", { name: "Chuyển câu 1 xuống" }));
    await waitFor(() => expect(api.reorderQuestions).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.getByRole("button", { name: "Chuyển câu 2 xuống" })).not.toHaveAttribute("aria-disabled"));
    await userEvent.click(screen.getByRole("button", { name: "Chuyển câu 2 xuống" }));
    await waitFor(() => expect(api.reorderQuestions).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.getByRole("button", { name: "Chuyển câu 2 xuống" })).not.toHaveAttribute("aria-disabled"));
    expect(screen.getAllByText("Đã lưu thứ tự câu")).toHaveLength(1);
  });

  it("BUG-3: nút Lên/Xuống không bị disabled nên giữ focus sau khi bấm bằng bàn phím", async () => {
    vi.mocked(api.getQuiz).mockResolvedValue(quizOf([q(1, 1), q(2, 2)]));
    vi.mocked(api.reorderQuestions).mockResolvedValue([q(2, 1), q(1, 2)]);
    setup();
    await screen.findByTestId("question-list");
    const down = screen.getByRole("button", { name: "Chuyển câu 1 xuống" });
    down.focus();
    await userEvent.keyboard("{Enter}");
    await waitFor(() => expect(api.reorderQuestions).toHaveBeenCalled());
    expect(down).not.toBeDisabled();
    expect(document.activeElement).toBe(down);
    // Bấm lại trong lúc đang khoá hoặc ở cuối không gửi thêm yêu cầu.
    await waitFor(() => expect(api.getQuiz).toHaveBeenCalledTimes(2));
    expect(api.reorderQuestions).toHaveBeenCalledTimes(1);
  });
});
