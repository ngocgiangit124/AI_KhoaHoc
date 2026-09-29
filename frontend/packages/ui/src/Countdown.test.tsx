import { act, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { Countdown } from "./Countdown";

describe("Countdown", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-09-28T10:00:00.000Z"));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("đếm ngược mm:ss tới targetTime rồi biến mất khi hết hạn, gọi onExpire đúng 1 lần", async () => {
    const onExpire = vi.fn();
    render(<Countdown targetTime="2026-09-28T10:00:47.000Z" onExpire={onExpire} />);

    // Flush effect đầu tiên (tính lần đầu) chạy dưới fake timers — không dùng
    // `findByText`/`waitFor` (poll bằng timer thật, treo vô hạn khi timers đã bị fake).
    await act(async () => {
      await Promise.resolve();
    });
    expect(screen.getByText("00:47")).toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(1000);
    });
    expect(screen.getByText("00:46")).toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(46_000);
    });
    expect(screen.queryByText(/00:00/)).not.toBeInTheDocument();
    expect(onExpire).toHaveBeenCalledTimes(1);
  });

  it("targetTime đã ở quá khứ -> không render gì và gọi onExpire ngay", async () => {
    const onExpire = vi.fn();
    const { container } = render(
      <Countdown targetTime="2026-09-28T09:59:00.000Z" onExpire={onExpire} />,
    );

    await act(async () => {
      await Promise.resolve();
    });

    expect(container).toBeEmptyDOMElement();
    expect(onExpire).toHaveBeenCalledTimes(1);
  });
});
