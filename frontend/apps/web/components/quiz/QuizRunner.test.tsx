import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import type { AttemptInProgress } from "@/lib/quiz/schemas";

const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: nav.replace, push: vi.fn() }) }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));
const learn = vi.hoisted(() => ({ warmCsrf: vi.fn().mockResolvedValue(undefined) }));
vi.mock("@/lib/learn/api", () => learn);
const api = vi.hoisted(() => ({ putAnswer: vi.fn(), submitAttempt: vi.fn(), fetchAttempt: vi.fn() }));
vi.mock("@/lib/quiz/api", () => api);

import { QuizRunner } from "./QuizRunner";

const attempt: AttemptInProgress = {
  id: 77,
  quiz_id: 5,
  status: "in_progress",
  started_at: "2026-10-07T10:00:00+07:00",
  expires_at: null,
  server_now: "2026-10-07T10:00:00+07:00",
  remaining_seconds: null,
  total_questions: 2,
  answers: {},
  questions: [
    {
      id: 1,
      position: 1,
      content: "Tính $\\dfrac{1}{2}+\\dfrac{1}{2}$",
      options: [
        { id: 11, position: 1, content: "$1$" },
        { id: 12, position: 2, content: "$2$" },
        { id: 13, position: 3, content: "$3$" },
        { id: 14, position: 4, content: "$4$" },
      ],
    },
    {
      id: 2,
      position: 2,
      content: "Câu hai",
      options: [
        { id: 21, position: 1, content: "a" },
        { id: 22, position: 2, content: "b" },
        { id: 23, position: 3, content: "c" },
        { id: 24, position: 4, content: "d" },
      ],
    },
  ],
};

function renderRunner(a: AttemptInProgress = attempt) {
  return render(<QuizRunner attempt={a} courseId={3} quizId={5} title="Kiểm tra 15 phút" exitHref="/hoc/3/bai/9" />);
}

describe("QuizRunner", () => {
  beforeAll(() => {
    // jsdom chưa có <dialog>.showModal/close.
    HTMLDialogElement.prototype.showModal ??= function (this: HTMLDialogElement) {
      this.setAttribute("open", "");
    };
    HTMLDialogElement.prototype.close ??= function (this: HTMLDialogElement) {
      this.removeAttribute("open");
    };
  });
  beforeEach(() => {
    api.putAnswer.mockReset().mockResolvedValue(undefined);
    api.submitAttempt.mockReset().mockResolvedValue({});
    api.fetchAttempt.mockReset();
    nav.replace.mockReset();
  });

  it("render công thức bằng KaTeX và có radio thật cho mỗi đáp án (A–D)", () => {
    const { container } = renderRunner();
    expect(container.querySelectorAll(".katex").length).toBeGreaterThan(0);
    expect(screen.getAllByRole("radio")).toHaveLength(8);
    expect(screen.getByText("Đã trả lời 0/2")).toBeInTheDocument();
    expect(screen.queryByText(/Thời gian còn lại/)).toBeNull(); // không giới hạn giờ → không đồng hồ
  });

  it("chọn đáp án → 'Đang lưu…' rồi 'Đã lưu', PUT đúng câu/đáp án một lần", async () => {
    renderRunner();
    fireEvent.click(screen.getAllByRole("radio")[1]!);
    expect(screen.getByText("Đã trả lời 1/2")).toBeInTheDocument();
    expect(screen.getByText("Đang lưu…")).toBeInTheDocument();
    await waitFor(() => expect(api.putAnswer).toHaveBeenCalledTimes(1), { timeout: 2000 });
    expect(api.putAnswer).toHaveBeenCalledWith(77, 1, 12, {});
    expect(await screen.findByText("Đã lưu")).toBeInTheDocument();
  });

  it("nộp khi còn câu trống → hỏi xác nhận, rồi gửi nốt đáp án, nộp và chuyển sang kết quả", async () => {
    renderRunner();
    fireEvent.click(screen.getAllByRole("radio")[0]!);
    fireEvent.click(screen.getAllByRole("button", { name: "Nộp bài" })[0]!);
    expect(await screen.findByText("Bạn còn 1 câu chưa trả lời")).toBeInTheDocument();
    expect(api.submitAttempt).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Vẫn nộp bài" }));
    await waitFor(() => expect(api.submitAttempt).toHaveBeenCalledWith(77));
    expect(api.putAnswer).toHaveBeenCalledWith(77, 1, 11, {}); // đáp án đang chờ debounce được gửi TRƯỚC khi nộp
    expect(api.putAnswer.mock.invocationCallOrder[0]).toBeLessThan(api.submitAttempt.mock.invocationCallOrder[0]!);
    await waitFor(() => expect(nav.replace).toHaveBeenCalledWith("/hoc/3/quiz/5/ket-qua?lan=77"));
  });

  it("mất mạng: banner cảnh báo, câu trả lời vẫn hiển thị; nộp thủ công bị chặn khi chưa lưu được", async () => {
    api.putAnswer.mockRejectedValue(new NetworkError(new Error("offline")));
    renderRunner();
    fireEvent.click(screen.getAllByRole("radio")[0]!);
    fireEvent.click(screen.getAllByRole("radio")[4]!);
    await waitFor(() => expect(screen.getByText("Mất kết nối mạng")).toBeInTheDocument(), { timeout: 2000 });
    expect(screen.getAllByRole("radio")[0]).toBeChecked();
    fireEvent.click(screen.getAllByRole("button", { name: "Nộp bài" })[0]!);
    expect(await screen.findByText(/Chưa lưu được một số câu trả lời/)).toBeInTheDocument();
    expect(api.submitAttempt).not.toHaveBeenCalled();
  });

  it("hết giờ → hộp thoại 'Đã hết giờ làm bài', tự nộp không cần xác nhận", async () => {
    renderRunner({ ...attempt, remaining_seconds: 1, expires_at: "2026-10-07T10:00:01+07:00" });
    expect(screen.getByText(/Thời gian còn lại/)).toBeInTheDocument();
    expect(await screen.findByText("Đã hết giờ làm bài", {}, { timeout: 4000 })).toBeInTheDocument();
    await waitFor(() => expect(api.submitAttempt).toHaveBeenCalledWith(77), { timeout: 3000 });
    await waitFor(() => expect(nav.replace).toHaveBeenCalledWith("/hoc/3/quiz/5/ket-qua?lan=77"));
  });

  it("PUT bị 422: bỏ tô đáp án, không tính là đã trả lời, báo lỗi ở câu", async () => {
    api.putAnswer.mockRejectedValue(new ApiError(422, { message: "x", code: "QUIZ_OPTION_INVALID" }));
    renderRunner();
    fireEvent.click(screen.getAllByRole("radio")[0]!);
    expect(await screen.findByText("Không lưu được câu này, hãy chọn lại", {}, { timeout: 3000 })).toBeInTheDocument();
    expect(screen.getAllByRole("radio")[0]).not.toBeChecked();
    expect(screen.getByText("Đã trả lời 0/2", { exact: true })).toBeInTheDocument();
  });

  it("Thoát khi còn đáp án chưa lưu (mất mạng): hỏi xác nhận, ở lại thì không chuyển trang", async () => {
    api.putAnswer.mockRejectedValue(new NetworkError(new Error("offline")));
    renderRunner();
    fireEvent.click(screen.getAllByRole("radio")[0]!);
    await waitFor(() => expect(screen.getByText("Mất kết nối mạng")).toBeInTheDocument(), { timeout: 3000 });
    fireEvent.click(screen.getByRole("link", { name: /Thoát, quay lại bài học/ }));
    expect(await screen.findByText("Còn 1 câu trả lời chưa lưu được")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Ở lại" }));
    expect(screen.getAllByRole("radio")[0]).toBeChecked();
  });

  it("mất phiên (401): khoá chọn đáp án, báo rõ, không kẹt 'Đang lưu…'", async () => {
    api.putAnswer.mockRejectedValue(new ApiError(401, { message: "x", code: "UNAUTHENTICATED" }));
    renderRunner();
    fireEvent.click(screen.getAllByRole("radio")[0]!);
    expect(await screen.findByText("Mất phiên: đăng nhập lại để tiếp tục", {}, { timeout: 3000 })).toBeInTheDocument();
    expect(screen.getByText("Chưa lưu: mất phiên")).toBeInTheDocument();
    expect(screen.queryByText("Đang lưu…")).toBeNull();
    expect(screen.getAllByRole("radio")[1]).toBeDisabled();
  });
});
