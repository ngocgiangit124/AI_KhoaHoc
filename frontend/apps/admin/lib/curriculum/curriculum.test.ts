import { describe, expect, it, vi } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { applyDrop, canShiftLesson, chapterDndId, dropDndId, lessonDndId, moveChapter, moveLesson, parseDndId, shiftLesson, toOrderPayload } from "./order";
import { curriculumError, lessonFieldErrors } from "./errors";
import type { Chapter, Lesson } from "./types";
import { MAX_VIDEO_BYTES, TUS_CHUNK_SIZE, formatBytes, formatEta, nextPollDelay, validateVideoFile } from "./video";
import { buildLessonPayload, externalUrlOf } from "@/components/curriculum/LessonForm";
import { toFailure } from "./tusUpload";
import { DetailedError } from "tus-js-client";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));

const lesson = (id: number, chapter_id: number, position: number, extra: Partial<Lesson> = {}): Lesson => ({
  id,
  course_id: 1,
  chapter_id,
  title: `Bài ${id}`,
  position,
  is_preview: false,
  video_source: "none",
  duration_seconds: null,
  external_provider: null,
  external_video_id: null,
  external_embed_url: null,
  has_video_asset: false,
  video_status: null,
  ...extra,
});
const tree = (): Chapter[] => [
  { id: 10, course_id: 1, title: "C1", position: 1, lessons: [lesson(1, 10, 1), lesson(2, 10, 2), lesson(3, 10, 3)] },
  { id: 20, course_id: 1, title: "C2", position: 2, lessons: [lesson(4, 20, 1)] },
  { id: 30, course_id: 1, title: "C3", position: 3, lessons: [] },
];
const ids = (c: Chapter[]) => c.map((x) => ({ c: x.id, l: x.lessons.map((l) => l.id) }));

describe("sắp xếp cây chương/bài", () => {
  it("parse id dnd", () => {
    expect(parseDndId(chapterDndId(5))).toEqual({ kind: "chapter", id: 5 });
    expect(parseDndId(lessonDndId(7))).toEqual({ kind: "lesson", id: 7 });
    expect(parseDndId(dropDndId(9))).toEqual({ kind: "drop", id: 9 });
    expect(parseDndId("x:1")).toBeNull();
    expect(parseDndId(3)).toBeNull();
  });

  it("đổi chỗ chương và đánh lại position 1..n", () => {
    const next = moveChapter(tree(), 30, 0)!;
    expect(next.map((c) => [c.id, c.position])).toEqual([[30, 1], [10, 2], [20, 3]]);
    expect(moveChapter(tree(), 10, 0)).toBeNull();
    expect(moveChapter(tree(), 99, 0)).toBeNull();
  });

  it("kéo chương thả lên bài của chương khác = vào vị trí chương đó", () => {
    const next = applyDrop(tree(), chapterDndId(10), lessonDndId(4))!;
    expect(next.map((c) => c.id)).toEqual([20, 10, 30]);
  });

  it("kéo bài xuống trong cùng chương", () => {
    const next = applyDrop(tree(), lessonDndId(1), lessonDndId(3))!;
    expect(ids(next)[0]).toEqual({ c: 10, l: [2, 3, 1] });
  });

  it("kéo bài lên trong cùng chương", () => {
    const next = applyDrop(tree(), lessonDndId(3), lessonDndId(1))!;
    expect(ids(next)[0]).toEqual({ c: 10, l: [3, 1, 2] });
  });

  it("kéo bài sang chương khác: vào trước bài đích, chapter_id/position cập nhật", () => {
    const next = applyDrop(tree(), lessonDndId(2), lessonDndId(4))!;
    expect(ids(next)).toEqual([{ c: 10, l: [1, 3] }, { c: 20, l: [2, 4] }, { c: 30, l: [] }]);
    expect(next[1]!.lessons.map((l) => [l.chapter_id, l.position])).toEqual([[20, 1], [20, 2]]);
  });

  it("thả bài vào chương rỗng và vào vùng chương (cuối)", () => {
    expect(ids(applyDrop(tree(), lessonDndId(1), dropDndId(30))!)[2]).toEqual({ c: 30, l: [1] });
    expect(ids(applyDrop(tree(), lessonDndId(1), dropDndId(20))!)[1]).toEqual({ c: 20, l: [4, 1] });
    // vùng chương của chính nó: xuống cuối
    expect(ids(applyDrop(tree(), lessonDndId(1), dropDndId(10))!)[0]).toEqual({ c: 10, l: [2, 3, 1] });
  });

  it("thả về chỗ cũ hoặc đích lạ thì không đổi", () => {
    expect(applyDrop(tree(), lessonDndId(1), lessonDndId(1))).toBeNull();
    expect(applyDrop(tree(), lessonDndId(3), dropDndId(10))).toBeNull();
    expect(applyDrop(tree(), lessonDndId(1), "zzz")).toBeNull();
    expect(moveLesson(tree(), 99, 10, 0)).toBeNull();
  });

  it("nút Lên/Xuống: trong chương, qua ranh giới chương, và chặn ở hai đầu", () => {
    expect(ids(shiftLesson(tree(), 2, -1)!)[0]!.l).toEqual([2, 1, 3]);
    expect(ids(shiftLesson(tree(), 3, 1)!)).toEqual([{ c: 10, l: [1, 2] }, { c: 20, l: [3, 4] }, { c: 30, l: [] }]);
    expect(ids(shiftLesson(tree(), 4, -1)!)[0]!.l).toEqual([1, 2, 3, 4]);
    expect(canShiftLesson(tree(), 1, -1)).toBe(false);
    expect(canShiftLesson(tree(), 4, 1)).toBe(true); // sang chương rỗng C3
    expect(canShiftLesson(tree(), 1, 1)).toBe(true);
  });

  it("payload PUT order: mảng gốc, đủ mọi chương kể cả rỗng", () => {
    expect(toOrderPayload(tree())).toEqual([
      { chapter_id: 10, lesson_ids: [1, 2, 3] },
      { chapter_id: 20, lesson_ids: [4] },
      { chapter_id: 30, lesson_ids: [] },
    ]);
  });
});

describe("kiểm tệp video (1 GB)", () => {
  it("chấp nhận 4 định dạng, không phân biệt hoa thường", () => {
    for (const n of ["a.mp4", "B.MOV", "c.mkv", "d.WebM"]) expect(validateVideoFile({ name: n, size: 1000 })).toBeNull();
  });
  it("chặn định dạng khác", () => {
    expect(validateVideoFile({ name: "a.avi", size: 10 })).toMatch(/Định dạng không được hỗ trợ/);
    expect(validateVideoFile({ name: "mp4", size: 10 })).toMatch(/Định dạng/);
  });
  it("đúng 1 GB qua, hơn 1 byte thì chặn kèm thông báo tiếng Việt", () => {
    expect(validateVideoFile({ name: "a.mp4", size: MAX_VIDEO_BYTES })).toBeNull();
    const msg = validateVideoFile({ name: "a.mp4", size: MAX_VIDEO_BYTES + 1 });
    expect(msg).toMatch(/Tệp quá lớn/);
    expect(msg).toMatch(/tối đa 1 GB/);
  });
  it("chặn tệp rỗng", () => {
    expect(validateVideoFile({ name: "a.mp4", size: 0 })).toMatch(/trống/);
  });
  it("chunk TUS ≤ 8 MB; định dạng dung lượng/ETA", () => {
    expect(TUS_CHUNK_SIZE).toBeLessThanOrEqual(8 * 1024 * 1024);
    expect(formatBytes(1536 * 1024 * 1024)).toBe("1,5 GB");
    expect(formatBytes(5 * 1024 * 1024)).toBe("5 MB");
    expect(formatEta(5)).toBe("còn vài giây");
    expect(formatEta(45)).toBe("còn khoảng 45 giây");
    expect(formatEta(200)).toBe("còn khoảng 4 phút");
    expect(formatEta(null)).toBeNull();
  });
  it("khoảng chờ polling tăng dần, tối đa 10 giây", () => {
    expect(nextPollDelay(0)).toBe(3000);
    expect(nextPollDelay(40_000)).toBe(5000);
    expect(nextPollDelay(600_000)).toBe(10_000);
  });
});

describe("thông điệp lỗi", () => {
  const e = (status: number, code?: string, message = "x") => new ApiError(status, { message, code });
  it("mã nghiệp vụ → tiếng Việt", () => {
    expect(curriculumError(e(409, "CHAPTER_HAS_PROGRESS"))).toMatch(/đã có học sinh học/);
    expect(curriculumError(e(409, "LESSON_HAS_PROGRESS"))).toMatch(/đã có học sinh học/);
    expect(curriculumError(e(409, "COURSE_LAST_LESSON"))).toMatch(/ngừng bán/);
    expect(curriculumError(e(422, "CURRICULUM_MISMATCH"))).toMatch(/thay đổi ở nơi khác/);
    expect(curriculumError(e(422, "VIDEO_QUOTA_EXCEEDED"))).toMatch(/hạn mức/);
    expect(curriculumError(e(503, "VIDEO_PROVIDER_UNAVAILABLE"))).toMatch(/tạm thời/);
    expect(curriculumError(e(422, "VIDEO_INVALID", "Tệp không phải video."))).toBe("Tệp không phải video.");
    expect(curriculumError(e(403))).toMatch(/không có quyền/);
    expect(curriculumError(e(404))).toMatch(/không còn tồn tại/);
    expect(curriculumError(new ApiError(429, { message: "x" }, 12))).toMatch(/12 giây/);
    expect(curriculumError(new NetworkError("x"))).toMatch(/kết nối/);
    expect(curriculumError(new Error("boom"))).toMatch(/Đã có lỗi/);
  });
  it("lỗi 422 theo field", () => {
    const err = new ApiError(422, { message: "x", errors: { title: ["Bắt buộc."], "external_url": ["Sai link."] } });
    expect(lessonFieldErrors(err)).toEqual({ title: "Bắt buộc.", external_url: "Sai link." });
    expect(lessonFieldErrors(e(500))).toEqual({});
  });
});

describe("payload lưu bài học", () => {
  const none = { video_source: "none" as const, external_provider: null, external_video_id: null };
  const ext = { video_source: "external_link" as const, external_provider: "youtube" as const, external_video_id: "abc" };
  it("dựng lại link chuẩn", () => {
    expect(externalUrlOf(ext)).toBe("https://www.youtube.com/watch?v=abc");
    expect(externalUrlOf({ external_provider: "vimeo", external_video_id: "123" })).toBe("https://vimeo.com/123");
    expect(externalUrlOf(none)).toBe("");
  });
  it("chỉ tên và xem thử: không gửi video_source", () => {
    expect(buildLessonPayload(none, { title: " Bài mới ", preview: true, source: "upload", url: "" })).toEqual({ title: "Bài mới", is_preview: true });
  });
  it("chọn link ngoài: gửi link; không sửa link thì không gửi external_url", () => {
    expect(buildLessonPayload(none, { title: "a", preview: true, source: "external_link", url: " https://youtu.be/x " })).toEqual({
      title: "a",
      is_preview: true,
      video_source: "external_link",
      external_url: "https://youtu.be/x",
    });
    expect(buildLessonPayload(ext, { title: "a", preview: true, source: "external_link", url: externalUrlOf(ext) })).toEqual({ title: "a", is_preview: true, video_source: "external_link" });
  });
  it("đổi từ link ngoài sang tải lên: bỏ link (none)", () => {
    expect(buildLessonPayload(ext, { title: "a", preview: false, source: "upload", url: "" })).toEqual({ title: "a", is_preview: false, video_source: "none" });
  });
});

describe("phân loại lỗi TUS", () => {
  const res = (status: number, body = "") => ({ getStatus: () => status, getBody: () => body, getHeader: () => undefined, getUnderlyingObject: () => null });
  const detailed = (r: ReturnType<typeof res> | null) => Object.assign(new DetailedError("tus"), { originalResponse: r as never });
  it("không có phản hồi = mất mạng, tải tiếp được", () => {
    expect(toFailure(detailed(null))).toEqual({ retryable: true, message: null, status: null });
  });
  it("5xx tải tiếp được; 422 VIDEO_INVALID lấy message tiếng Việt và không thử lại", () => {
    expect(toFailure(detailed(res(503))).retryable).toBe(true);
    const f = toFailure(detailed(res(422, JSON.stringify({ message: "Tệp tải lên không phải video hợp lệ.", code: "VIDEO_INVALID" }))));
    expect(f).toEqual({ retryable: false, message: "Tệp tải lên không phải video hợp lệ.", status: 422 });
    expect(toFailure(detailed(res(413, "<html>"))).message).toBeNull();
  });
});
