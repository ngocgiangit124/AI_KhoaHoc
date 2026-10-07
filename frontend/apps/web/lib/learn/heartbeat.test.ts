import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { describe, expect, it } from "vitest";
import { classifyHeartbeatFailure, HeartbeatTracker } from "./heartbeat";

/** Mô phỏng `timeupdate` mỗi 0,25 giây ở tốc độ `rate` trong `seconds` giây tường. */
function play(t: HeartbeatTracker, from: number, wallSeconds: number, rate = 1): number {
  let pos = from;
  for (let i = 0; i < wallSeconds * 4; i++) {
    pos += 0.25 * rate;
    t.observe(pos);
  }
  return pos;
}

describe("HeartbeatTracker", () => {
  it("20 giây xem liên tục → delta 20, body toàn số NGUYÊN", () => {
    const t = new HeartbeatTracker();
    t.reset(10.4);
    const end = play(t, 10.4, 20);
    const body = t.take(1_000);
    expect(body).toEqual({ position_seconds: Math.floor(end), watched_delta_seconds: 20 });
    expect(Number.isInteger(body!.position_seconds) && Number.isInteger(body!.watched_delta_seconds)).toBe(true);
  });

  it("tua (nhảy xa) không được tính là đã xem", () => {
    const t = new HeartbeatTracker();
    t.reset(0);
    play(t, 0, 5);
    t.observe(600); // nhảy tới phút thứ 10 mà không gọi reset
    play(t, 600, 5);
    expect(t.take(1)!.watched_delta_seconds).toBe(10);
  });

  it("xem 2× cộng giây VIDEO (40 giây trong 20 giây tường)", () => {
    const t = new HeartbeatTracker();
    t.reset(0);
    play(t, 0, 20, 2);
    expect(t.take(1)!.watched_delta_seconds).toBe(40);
  });

  it("tạm dừng rồi phát tiếp (reset) không cộng khoảng nghỉ; phần lẻ < 1 giây giữ lại lần sau", () => {
    const t = new HeartbeatTracker();
    t.reset(0);
    t.observe(0.5);
    t.observe(0.75);
    t.reset(0.75);
    t.observe(1.2);
    const first = t.take(1)!;
    expect(first.watched_delta_seconds).toBe(1); // 0.5+0.25+0.45 = 1.2 → 1, dư 0.2
    t.observe(2.2);
    expect(t.take(100_000)!.watched_delta_seconds).toBe(1); // 0.2 + 1.0 = 1.2
  });

  it("delta bị chặn trần 60 giây và phần vượt không tích luỹ", () => {
    const t = new HeartbeatTracker();
    t.reset(0);
    play(t, 0, 200);
    expect(t.take(1)!.watched_delta_seconds).toBe(60);
    expect(t.take(100_000)).toBeNull(); // vị trí không đổi và không còn giây nào
  });

  it("không có gì mới (không xem thêm, vị trí không đổi) → không gửi", () => {
    const t = new HeartbeatTracker();
    t.reset(30);
    expect(t.take(1)).toEqual({ position_seconds: 30, watched_delta_seconds: 0 }); // lần đầu lưu vị trí
    expect(t.take(100_000)).toBeNull();
  });

  it("canSend giữ khoảng cách ≥ 10 giây (throttle 6/phút/bài)", () => {
    const t = new HeartbeatTracker();
    t.reset(0);
    play(t, 0, 20);
    expect(t.canSend(0)).toBe(true);
    t.take(5_000);
    expect(t.canSend(10_000)).toBe(false);
    expect(t.canSend(15_000)).toBe(true);
  });

  it("gửi lỗi tạm thời: restore trả lại giây để gửi bù lần sau", () => {
    const t = new HeartbeatTracker();
    t.reset(0);
    play(t, 0, 20);
    const body = t.take(1)!;
    t.restore(body);
    expect(t.take(100_000)!.watched_delta_seconds).toBe(20);
  });
});

describe("classifyHeartbeatFailure", () => {
  it("403 → thu hồi quyền; 404 → bài mất; 422 → bỏ (không gửi lại body sai); còn lại thử lại", () => {
    expect(classifyHeartbeatFailure(new ApiError(403, { message: "x", code: "COURSE_NOT_OWNED" }))).toBe("revoked");
    expect(classifyHeartbeatFailure(new ApiError(404, { message: "x" }))).toBe("gone");
    expect(classifyHeartbeatFailure(new ApiError(422, { message: "x" }))).toBe("drop");
    expect(classifyHeartbeatFailure(new ApiError(429, { message: "x" }))).toBe("retry");
    expect(classifyHeartbeatFailure(new ApiError(500, { message: "x" }))).toBe("retry");
    expect(classifyHeartbeatFailure(new NetworkError(new Error("x")))).toBe("retry");
  });
});
