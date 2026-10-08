import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { AnswerSaver } from "./autosave";
import { classifySaveError } from "./errors";

type Put = (q: number, o: number, opts: { keepalive?: boolean }) => Promise<void>;

function make(put: Put, initial: Record<string, number> = {}) {
  return new AnswerSaver({ put, classify: classifySaveError, debounceMs: 400, retryDelaysMs: [1000, 2000] }, initial);
}

describe("AnswerSaver", () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it("debounce: nhiều lần chọn nhanh một câu chỉ gửi giá trị cuối, một lần", async () => {
    const put = vi.fn<Put>().mockResolvedValue(undefined);
    const s = make(put);
    s.choose(1, 10);
    s.choose(1, 11);
    s.choose(1, 12);
    expect(s.getSnapshot().questions[1]).toBe("saving");
    expect(put).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(400);
    expect(put).toHaveBeenCalledTimes(1);
    expect(put).toHaveBeenCalledWith(1, 12, {});
    expect(s.getSnapshot().questions[1]).toBe("saved");
    expect(s.getSnapshot().unsaved).toBe(0);
  });

  it("đổi ý khi request đang bay: không gửi trùng song song, gửi giá trị mới ngay sau đó", async () => {
    let release: () => void = () => undefined;
    const calls: number[] = [];
    const put = vi.fn<Put>((_q, o) => {
      calls.push(o);
      return new Promise<void>((res) => {
        release = res;
      });
    });
    const s = make(put);
    s.choose(1, 10);
    await vi.advanceTimersByTimeAsync(400);
    expect(calls).toEqual([10]);
    s.choose(1, 11);
    await vi.advanceTimersByTimeAsync(400);
    expect(calls).toEqual([10]); // vẫn chờ request đầu
    release();
    await vi.advanceTimersByTimeAsync(0);
    expect(calls).toEqual([10, 11]);
    release();
    await vi.advanceTimersByTimeAsync(0);
    expect(s.getSnapshot().unsaved).toBe(0);
    expect(s.getSnapshot().questions[1]).toBe("saved");
  });

  it("chọn lại đúng đáp án server đã có thì không gửi", async () => {
    const put = vi.fn<Put>().mockResolvedValue(undefined);
    const s = make(put, { "5": 50 });
    s.choose(5, 50);
    await vi.advanceTimersByTimeAsync(1000);
    expect(put).not.toHaveBeenCalled();
    expect(s.getSnapshot().questions[5]).toBe("saved");
  });

  it("mất mạng: giữ đáp án, báo offline, gửi lại theo lịch rồi thành công", async () => {
    const put = vi.fn<Put>().mockRejectedValueOnce(new NetworkError(new Error("x"))).mockRejectedValueOnce(new ApiError(503, { message: "x" })).mockResolvedValue(undefined);
    const s = make(put);
    s.choose(2, 20);
    await vi.advanceTimersByTimeAsync(400);
    expect(s.getSnapshot()).toMatchObject({ offline: true, unsaved: 1 });
    expect(s.getSnapshot().questions[2]).toBe("error");
    await vi.advanceTimersByTimeAsync(1000); // lần thử lại 1 (503)
    expect(put).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(2000); // lần thử lại 2 (ok)
    expect(put).toHaveBeenCalledTimes(3);
    expect(s.getSnapshot()).toMatchObject({ offline: false, unsaved: 0 });
    expect(s.getSnapshot().questions[2]).toBe("saved");
  });

  it("retryNow (sự kiện online) gửi lại ngay, không chờ lịch", async () => {
    const put = vi.fn<Put>().mockRejectedValueOnce(new NetworkError(new Error("x"))).mockResolvedValue(undefined);
    const s = make(put);
    s.choose(2, 20);
    await vi.advanceTimersByTimeAsync(400);
    s.retryNow();
    await vi.advanceTimersByTimeAsync(0);
    expect(put).toHaveBeenCalledTimes(2);
    expect(s.getSnapshot().unsaved).toBe(0);
  });

  it("409 (đã nộp/hết hạn): đánh dấu closed, bỏ đáp án chờ gửi, không thử lại", async () => {
    const put = vi.fn<Put>().mockRejectedValue(new ApiError(409, { message: "x", code: "QUIZ_ATTEMPT_EXPIRED" }));
    const s = make(put);
    s.choose(1, 10);
    await vi.advanceTimersByTimeAsync(400);
    expect(s.getSnapshot()).toMatchObject({ closed: true, unsaved: 0 });
    await vi.advanceTimersByTimeAsync(60_000);
    expect(put).toHaveBeenCalledTimes(1);
    s.choose(1, 11);
    await vi.advanceTimersByTimeAsync(1000);
    expect(put).toHaveBeenCalledTimes(1);
  });

  it("403/404 → revoked; 422 → chỉ bỏ câu đó; 401 → sessionLost, giữ đáp án và ngừng gửi", async () => {
    const a = make(vi.fn<Put>().mockRejectedValue(new ApiError(403, { message: "x" })));
    a.choose(1, 1);
    await vi.advanceTimersByTimeAsync(400);
    expect(a.getSnapshot().revoked).toBe(true);

    const b = make(vi.fn<Put>().mockRejectedValue(new ApiError(422, { message: "x", code: "QUIZ_OPTION_INVALID" })));
    b.choose(1, 1);
    await vi.advanceTimersByTimeAsync(400);
    expect(b.getSnapshot().questions[1]).toBe("rejected");
    expect(b.getSnapshot().unsaved).toBe(0);

    const put = vi.fn<Put>().mockRejectedValue(new ApiError(401, { message: "x" }));
    const c = make(put);
    c.choose(1, 1);
    await vi.advanceTimersByTimeAsync(400);
    expect(c.getSnapshot()).toMatchObject({ sessionLost: true, unsaved: 1 });
    await vi.advanceTimersByTimeAsync(60_000);
    expect(put).toHaveBeenCalledTimes(1);
  });

  it("flushNow gửi ngay mọi đáp án còn chờ debounce và trả true khi xong", async () => {
    const put = vi.fn<Put>().mockResolvedValue(undefined);
    const s = make(put);
    s.choose(1, 10);
    s.choose(2, 20);
    const ok = await s.flushNow();
    expect(ok).toBe(true);
    expect(put).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1000);
    expect(put).toHaveBeenCalledTimes(2); // debounce đã bị huỷ, không gửi lại
  });

  it("flushNow trả false khi mạng lỗi (để nơi gọi chặn nộp)", async () => {
    const s = make(vi.fn<Put>().mockRejectedValue(new NetworkError(new Error("x"))));
    s.choose(1, 10);
    expect(await s.flushNow()).toBe(false);
    expect(s.getSnapshot().unsaved).toBe(1);
    s.dispose();
  });

  it("flushKeepalive gửi đáp án chưa lưu với keepalive", async () => {
    const put = vi.fn<Put>().mockResolvedValue(undefined);
    const s = make(put);
    s.choose(3, 30);
    s.flushKeepalive();
    await vi.advanceTimersByTimeAsync(0);
    expect(put).toHaveBeenCalledWith(3, 30, { keepalive: true });
  });
});
