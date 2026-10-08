import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import * as api from "@/lib/quiz/api";
import type { QuizQuestion } from "@/lib/quiz/types";
import { QuestionEditor, type QuestionEditorProps } from "./QuestionEditor";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
vi.mock("@/lib/quiz/api");

const question = (over: Partial<QuizQuestion> = {}): QuizQuestion => ({
  id: 90,
  quiz_id: 5,
  content: "Câu gốc $x$",
  explanation: "Giải thích",
  position: 1,
  options: [
    { id: 1, content: "A1", is_correct: false, position: 1 },
    { id: 2, content: "B2", is_correct: true, position: 2 },
    { id: 3, content: "C3", is_correct: false, position: 3 },
    { id: 4, content: "D4", is_correct: false, position: 4 },
  ],
  ...over,
});

function setup(over: Partial<QuestionEditorProps> = {}) {
  const props: QuestionEditorProps = {
    courseId: 1,
    quizId: 5,
    question: question(),
    number: 1,
    listHref: "/l",
    onSaved: vi.fn(),
    onDeleted: vi.fn(),
    onGone: vi.fn(),
    onDirtyChange: vi.fn(),
    ...over,
  };
  render(
    <ToastProvider>
      <QuestionEditor {...props} />
    </ToastProvider>,
  );
  return props;
}

const err422 = (errors: Record<string, string[]>) => new ApiError(422, { message: "x", code: "VALIDATION_FAILED", errors });

beforeEach(() => {
  vi.resetAllMocks();
});
afterEach(() => {
  vi.useRealTimers();
});

describe("QuestionEditor", () => {
  it("xem trước cập nhật sau debounce, công thức render bằng KaTeX", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    setup({ question: null, number: 3 });
    const preview = screen.getByTestId("question-preview");
    expect(within(preview).getByText("Nội dung câu hỏi sẽ hiện ở đây.")).toBeInTheDocument();
    fireEvent.change(screen.getByRole("textbox", { name: /Nội dung câu hỏi/ }), { target: { value: "Tính $x^2$" } });
    expect(preview.querySelector(".katex")).toBeNull(); // chưa qua debounce
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300);
    });
    expect(preview.querySelector(".katex")).not.toBeNull();
    expect(within(preview).getByText("Câu 3")).toBeInTheDocument();
  });

  it("thiếu $ chỉ là lưu ý (hint tông warning), không phải lỗi ô và không chặn lưu", async () => {
    vi.mocked(api.updateQuestion).mockResolvedValue(question());
    setup();
    const box = screen.getByRole("textbox", { name: /Nội dung câu hỏi/ });
    await userEvent.type(box, " giá 5$");
    const note = screen.getByTestId("dollar-warning");
    expect(note).toHaveTextContent(/Lưu ý: Thiếu một dấu \$/);
    expect(box).not.toHaveAttribute("aria-invalid");
    await userEvent.click(screen.getByRole("button", { name: "Lưu câu hỏi" }));
    await waitFor(() => expect(api.updateQuestion).toHaveBeenCalled());
  });

  it("chèn nhanh vào ô đang soạn", async () => {
    setup({ question: null });
    const content = screen.getByRole("textbox", { name: /Nội dung câu hỏi/ });
    await userEvent.click(content);
    await userEvent.click(screen.getByRole("button", { name: "Phân số" }));
    expect(content).toHaveValue("\\dfrac{}{}");
  });

  it("kiểm tại chỗ: thiếu ô → không gọi API, hộp tóm tắt có liên kết tới ô", async () => {
    setup({ question: null });
    await userEvent.click(screen.getByRole("button", { name: "Thêm câu hỏi" }));
    expect(api.createQuestion).not.toHaveBeenCalled();
    const summary = await screen.findByText(/còn 6 chỗ cần sửa/);
    expect(summary).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Đáp án đúng" })).toBeInTheDocument();
    expect(screen.getByText("Vui lòng nhập nội dung đáp án D.")).toBeInTheDocument();
  });

  it("dấu < sát chữ bị chặn trước khi gửi", async () => {
    setup();
    const content = screen.getByRole("textbox", { name: /Nội dung câu hỏi/ });
    await userEvent.clear(content);
    await userEvent.type(content, "a <b");
    await userEvent.click(screen.getByRole("button", { name: "Lưu câu hỏi" }));
    expect(api.updateQuestion).not.toHaveBeenCalled();
    expect((await screen.findAllByText(/giống thẻ HTML/)).length).toBeGreaterThan(0);
  });

  it("422 hiện dưới đúng ô và giữ nguyên dữ liệu", async () => {
    vi.mocked(api.updateQuestion).mockRejectedValue(err422({ "options.2.content": ["Đáp án C quá dài."], explanation: ["Lời giải sai."] }));
    setup();
    await userEvent.click(screen.getByRole("button", { name: "Lưu câu hỏi" }));
    expect(await screen.findAllByText("Đáp án C quá dài.")).not.toHaveLength(0);
    expect(screen.getAllByText("Lời giải sai.").length).toBeGreaterThan(0);
    expect(screen.getByRole("textbox", { name: /Nội dung câu hỏi/ })).toHaveValue("Câu gốc $x$");
  });

  it("lưu bình thường: gửi đúng thân PUT, hiện 'Đã lưu lúc'", async () => {
    vi.mocked(api.updateQuestion).mockResolvedValue(question({ content: "Mới" }));
    const props = setup();
    const content = screen.getByRole("textbox", { name: /Nội dung câu hỏi/ });
    await userEvent.clear(content);
    await userEvent.type(content, "Mới");
    await userEvent.click(screen.getByRole("button", { name: "Lưu câu hỏi" }));
    await waitFor(() => expect(props.onSaved).toHaveBeenCalled());
    expect(api.updateQuestion).toHaveBeenCalledWith(1, 5, 90, {
      content: "Mới",
      explanation: "Giải thích",
      options: [
        { content: "A1", is_correct: false },
        { content: "B2", is_correct: true },
        { content: "C3", is_correct: false },
        { content: "D4", is_correct: false },
      ],
    });
    expect(props.onSaved).toHaveBeenCalledWith(expect.objectContaining({ id: 90 }), { created: false, replacedId: null });
    expect(await screen.findByTestId("saved-at")).toHaveTextContent(/Đã lưu lúc \d{2}:\d{2}/);
  });

  it("copy-on-write: id trả về khác → báo replacedId + cảnh báo bản mới", async () => {
    vi.mocked(api.updateQuestion).mockResolvedValue(question({ id: 777 }));
    const props = setup();
    await userEvent.type(screen.getByRole("textbox", { name: /Nội dung câu hỏi/ }), " thêm");
    await userEvent.click(screen.getByRole("button", { name: "Lưu câu hỏi" }));
    await waitFor(() => expect(props.onSaved).toHaveBeenCalledWith(expect.objectContaining({ id: 777 }), { created: false, replacedId: 90 }));
    expect(await screen.findByText("Đã lưu thành bản mới của câu hỏi")).toBeInTheDocument();
  });

  it("bấm lưu hai lần liên tiếp chỉ gửi một request", async () => {
    let resolve!: (q: QuizQuestion) => void;
    vi.mocked(api.updateQuestion).mockReturnValue(new Promise((r) => (resolve = r)));
    setup();
    const form = screen.getByTestId("question-form");
    fireEvent.submit(form);
    fireEvent.submit(form);
    expect(api.updateQuestion).toHaveBeenCalledTimes(1);
    await act(async () => resolve(question()));
  });

  it("câu mới: POST; 404 khi sửa → onGone; báo dirty", async () => {
    vi.mocked(api.createQuestion).mockResolvedValue(question({ id: 11 }));
    const props = setup({ question: null });
    await userEvent.type(screen.getByRole("textbox", { name: /Nội dung câu hỏi/ }), "Q");
    await waitFor(() => expect(props.onDirtyChange).toHaveBeenLastCalledWith(true));
    const boxes = screen.getAllByRole("textbox");
    for (const [i, name] of ["A", "B", "C", "D"].entries()) await userEvent.type(boxes.find((b) => b.id === `q-o${i}`)!, name);
    await userEvent.click(screen.getByRole("radio", { name: /đáp án C/ }));
    await userEvent.click(screen.getByRole("button", { name: "Thêm câu hỏi" }));
    await waitFor(() => expect(api.createQuestion).toHaveBeenCalledWith(1, 5, expect.objectContaining({ options: expect.arrayContaining([{ content: "C", is_correct: true }]) })));
    await waitFor(() => expect(props.onSaved).toHaveBeenCalledWith(expect.anything(), { created: true, replacedId: null }));
  });

  it("sửa câu đã bị xoá nơi khác (404) → onGone", async () => {
    vi.mocked(api.updateQuestion).mockRejectedValue(new ApiError(404, { message: "x" }));
    const props = setup();
    await userEvent.type(screen.getByRole("textbox", { name: /Nội dung câu hỏi/ }), "x");
    await userEvent.click(screen.getByRole("button", { name: "Lưu câu hỏi" }));
    await waitFor(() => expect(props.onGone).toHaveBeenCalled());
  });

  it("xoá câu có xác nhận; 403 hiện lỗi", async () => {
    vi.mocked(api.deleteQuestion).mockRejectedValueOnce(new ApiError(403, { message: "x" })).mockResolvedValueOnce(undefined);
    const props = setup();
    await userEvent.click(screen.getByRole("button", { name: "Xoá câu" }));
    const dlg = await screen.findByRole("dialog");
    expect(api.deleteQuestion).not.toHaveBeenCalled();
    await userEvent.click(within(dlg).getByRole("button", { name: "Xoá câu" }));
    expect(await screen.findByText(/không có quyền/)).toBeInTheDocument();
    expect(props.onDeleted).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole("button", { name: "Xoá câu" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "Xoá câu" }));
    await waitFor(() => expect(props.onDeleted).toHaveBeenCalledWith(90));
  });
});
