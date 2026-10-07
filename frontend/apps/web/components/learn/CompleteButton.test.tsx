import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";

const api = vi.hoisted(() => ({ completeLesson: vi.fn() }));
vi.mock("@/lib/learn/api", () => api);

import { CompleteButton } from "./CompleteButton";

describe("CompleteButton (Đánh dấu đã học — bài link ngoài)", () => {
  beforeEach(() => {
    api.completeLesson.mockReset();
  });

  it("bấm → gọi POST complete đúng bài, báo kết quả lên trang (mục lục/tiến độ)", async () => {
    api.completeLesson.mockResolvedValue({ status: "completed", completed: true, course_percent: 25 });
    const onDone = vi.fn();
    render(<CompleteButton lessonId={9} completed={false} onDone={onDone} />);
    fireEvent.click(screen.getByRole("button", { name: "Đánh dấu đã học" }));
    await waitFor(() => expect(onDone).toHaveBeenCalledWith({ status: "completed", completed: true, course_percent: 25 }));
    expect(api.completeLesson).toHaveBeenCalledWith(9);
  });

  it("đã học → chỉ hiện 'Đã học', không còn nút", () => {
    render(<CompleteButton lessonId={9} completed onDone={vi.fn()} />);
    expect(screen.getByText("Đã học")).toBeInTheDocument();
    expect(screen.queryByRole("button")).toBeNull();
  });

  const failures: Array<[string, () => ApiError, RegExp]> = [
    ["422 không phải bài thủ công", () => new ApiError(422, { message: "x", code: "LESSON_COMPLETION_NOT_MANUAL" }), /tự ghi tiến độ/],
    ["429", () => new ApiError(429, { message: "x" }), /thao tác hơi nhanh/],
    ["500", () => new ApiError(500, { message: "x" }), /Chưa lưu được/],
  ];
  it.each(failures)("lỗi %s hiện thông báo dưới nút và giữ nút để thử lại", async (_name, make, text) => {
    api.completeLesson.mockImplementation(async () => {
      throw make();
    });
    const onDone = vi.fn();
    render(<CompleteButton lessonId={9} completed={false} onDone={onDone} />);
    fireEvent.click(screen.getByRole("button", { name: "Đánh dấu đã học" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(text);
    expect(onDone).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Đánh dấu đã học" })).toBeEnabled();
  });

  it("403 (thu hồi quyền) → onRevoked", async () => {
    api.completeLesson.mockImplementation(async () => {
      throw new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED" });
    });
    const onRevoked = vi.fn();
    render(<CompleteButton lessonId={9} completed={false} onDone={vi.fn()} onRevoked={onRevoked} />);
    fireEvent.click(screen.getByRole("button", { name: "Đánh dấu đã học" }));
    await waitFor(() => expect(onRevoked).toHaveBeenCalled());
  });
});
