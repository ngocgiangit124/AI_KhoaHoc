import { afterEach, describe, expect, it, vi } from "vitest";
import { settle } from "./settle";

afterEach(() => vi.useRealTimers());

describe("settle", () => {
  it("trả giá trị khi thành công", async () => {
    expect(await settle(Promise.resolve(5))).toEqual({ ok: true, value: 5 });
  });

  it("lỗi -> ok:false, không ném", async () => {
    expect(await settle(Promise.reject(new Error("x")))).toEqual({ ok: false });
  });

  it("quá hạn -> ok:false rồi bỏ qua kết quả đến muộn, không unhandled rejection", async () => {
    vi.useFakeTimers();
    const late = new Promise<number>((_, reject) => setTimeout(() => reject(new Error("muộn")), 10_000));
    const p = settle(late, 1000);
    await vi.advanceTimersByTimeAsync(1000);
    expect(await p).toEqual({ ok: false });
    await vi.advanceTimersByTimeAsync(10_000);
  });
});
