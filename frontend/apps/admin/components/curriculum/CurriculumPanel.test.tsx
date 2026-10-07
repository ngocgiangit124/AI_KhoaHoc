import { act, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import * as api from "@/lib/curriculum/api";
import type { Chapter, Lesson } from "@/lib/curriculum/types";
import { MAX_VIDEO_BYTES, POLL_MAX_MS } from "@/lib/curriculum/video";
import { CurriculumPanel, type CurriculumPanelProps } from "./CurriculumPanel";
import type { UploadManager, UploadState } from "./useUploadManager";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
vi.mock("@/lib/curriculum/api");

const lesson = (id: number, chapter_id: number, position: number, extra: Partial<Lesson> = {}): Lesson => ({
  id,
  course_id: 5,
  chapter_id,
  title: `Bài ${id}`,
  position,
  is_preview: false,
  video_source: "upload",
  duration_seconds: 120,
  external_provider: null,
  external_video_id: null,
  external_embed_url: null,
  has_video_asset: true,
  video_status: "ready",
  ...extra,
});
const tree = (): Chapter[] => [
  { id: 10, course_id: 5, title: "Chương 1", position: 1, lessons: [lesson(1, 10, 1), lesson(2, 10, 2, { video_status: "processing", duration_seconds: null })] },
  { id: 20, course_id: 5, title: "Chương 2", position: 2, lessons: [lesson(3, 20, 1, { video_status: "failed", duration_seconds: null })] },
];

function manager(uploads: Record<number, UploadState> = {}, over: Partial<UploadManager> = {}): UploadManager {
  return {
    uploads,
    hasActive: Object.keys(uploads).length > 0,
    start: vi.fn(),
    cancel: vi.fn(),
    resume: vi.fn(),
    dismiss: vi.fn(),
    ...over,
  };
}

function setup(over: Partial<CurriculumPanelProps> = {}) {
  const props: CurriculumPanelProps = {
    courseId: 5,
    manager: manager(),
    refreshTick: 0,
    watchedLessonIds: [],
    onWatchedSettled: vi.fn(),
    dirtyRef: { current: false },
    selectedLessonId: null,
    lessonHref: (id) => `/quan-tri/khoa-hoc/5/sua?tab=chuong-bai${id ? `&bai=${id}` : ""}`,
    onSelect: vi.fn(),
    onStructureChanged: vi.fn(),
    ...over,
  };
  const view = render(
    <ToastProvider>
      <CurriculumPanel {...props} />
    </ToastProvider>,
  );
  const rerenderWith = (next: Partial<CurriculumPanelProps>) =>
    view.rerender(
      <ToastProvider>
        <CurriculumPanel {...props} {...next} />
      </ToastProvider>,
    );
  return { props, rerenderWith, ...view };
}

beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(api.getCurriculum).mockResolvedValue({ course_id: 5, chapters: tree() });
  vi.mocked(api.getLessonVideo).mockResolvedValue({
    lesson_id: 3,
    video_source: "upload",
    has_video_asset: true,
    video_asset_id: 9,
    status: "failed",
    duration_seconds: null,
    original_filename: "x.mp4",
    error_message: "Tệp tải lên không phải video hợp lệ. Hãy chọn tệp khác.",
  });
});
afterEach(() => vi.useRealTimers());

describe("CurriculumPanel", () => {
  it("hiện cây chương/bài với nhãn trạng thái video và thời lượng", async () => {
    setup();
    expect(await screen.findByRole("heading", { name: "Chương 1" })).toBeInTheDocument();
    expect(screen.getByText("Sẵn sàng")).toBeInTheDocument();
    expect(screen.getByText("Đang xử lý")).toBeInTheDocument();
    expect(screen.getByText("Lỗi video")).toBeInTheDocument();
    expect(screen.getByText("2:00")).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /Kéo để đổi thứ tự/ })).toHaveLength(5);
    expect(screen.getByRole("link", { name: /Bài 1/ })).toHaveAttribute("href", "/quan-tri/khoa-hoc/5/sua?tab=chuong-bai&bai=1");
  });

  it("khóa không có chương: gợi ý thêm chương đầu tiên", async () => {
    vi.mocked(api.getCurriculum).mockResolvedValue({ course_id: 5, chapters: [] });
    setup();
    expect(await screen.findByText("Khóa học chưa có chương nào")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Thêm chương đầu tiên" })).toBeInTheDocument();
  });

  it("403: báo không có quyền (giáo viên không được gán)", async () => {
    vi.mocked(api.getCurriculum).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    setup();
    expect(await screen.findByTestId("curriculum-forbidden")).toHaveTextContent("Bạn không có quyền sửa nội dung khóa học này.");
  });

  it("lỗi tải: banner kèm nút Thử lại", async () => {
    vi.mocked(api.getCurriculum).mockRejectedValueOnce(new ApiError(500, { message: "Lỗi máy chủ." }));
    setup();
    expect(await screen.findByText("Không tải được chương và bài")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Thử lại" }));
    expect(await screen.findByRole("heading", { name: "Chương 1" })).toBeInTheDocument();
  });

  it("nút Xuống: lưu thứ tự bằng PUT mảng gốc, bài sang chương kế khi ở cuối chương", async () => {
    vi.mocked(api.saveCurriculumOrder).mockImplementation(async () => ({ course_id: 5, chapters: tree() }));
    setup({ selectedLessonId: 2 });
    await screen.findByRole("heading", { name: "Chương 1" });
    await userEvent.click(await screen.findByRole("button", { name: "Xuống" }));
    await waitFor(() =>
      expect(api.saveCurriculumOrder).toHaveBeenCalledWith(5, [
        { chapter_id: 10, lesson_ids: [1] },
        { chapter_id: 20, lesson_ids: [2, 3] },
      ]),
    );
    expect(await screen.findByText("Đã lưu thứ tự")).toBeInTheDocument();
  });

  it("nút Lên của bài đầu tiên bị khoá", async () => {
    setup({ selectedLessonId: 1 });
    expect(await screen.findByRole("button", { name: "Lên" })).toBeDisabled();
  });

  it("422 CURRICULUM_MISMATCH: hoàn lại, tải lại cây, báo tiếng Việt", async () => {
    vi.mocked(api.saveCurriculumOrder).mockRejectedValue(new ApiError(422, { message: "x", code: "CURRICULUM_MISMATCH" }));
    setup({ selectedLessonId: 2 });
    await userEvent.click(await screen.findByRole("button", { name: "Xuống" }));
    expect(await screen.findByText(/vừa được thay đổi ở nơi khác/)).toBeInTheDocument();
    await waitFor(() => expect(api.getCurriculum).toHaveBeenCalledTimes(2));
    const chapter1 = screen.getByRole("region", { name: "Chương 1" });
    expect(within(chapter1).getByRole("link", { name: /Bài 2/ })).toBeInTheDocument();
  });

  it("thêm chương: 422 hiện dưới ô, thành công thì thêm vào cây", async () => {
    vi.mocked(api.createChapter)
      .mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { title: ["Tên chương không hợp lệ."] } }))
      .mockResolvedValueOnce({ id: 30, course_id: 5, title: "Chương 3", position: 3, lessons: [] });
    const { props } = setup();
    await userEvent.click(await screen.findByRole("button", { name: "Thêm chương" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText(/Tên chương/), "<b>");
    await userEvent.click(within(dialog).getByRole("button", { name: "Thêm chương" }));
    expect(await within(dialog).findByText("Tên chương không hợp lệ.")).toBeInTheDocument();
    await userEvent.clear(within(dialog).getByLabelText(/Tên chương/));
    await userEvent.type(within(dialog).getByLabelText(/Tên chương/), "Chương 3");
    await userEvent.click(within(dialog).getByRole("button", { name: "Thêm chương" }));
    expect(await screen.findByRole("heading", { name: "Chương 3" })).toBeInTheDocument();
    expect(api.createChapter).toHaveBeenLastCalledWith(5, "Chương 3");
    expect(props.onStructureChanged).toHaveBeenCalled();
  });

  it("thêm bài vào chương: tạo bài video_source=none rồi chọn bài đó", async () => {
    vi.mocked(api.createLesson).mockResolvedValue(lesson(77, 20, 2, { title: "Bài mới", video_source: "none", has_video_asset: false, video_status: null }));
    const { props } = setup();
    const region = await screen.findByRole("region", { name: "Chương 2" });
    await userEvent.click(within(region).getAllByRole("button", { name: "Thêm bài" })[0]!);
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText(/Tên bài học/), "Bài mới");
    await userEvent.click(within(dialog).getByRole("button", { name: "Thêm bài" }));
    await waitFor(() => expect(api.createLesson).toHaveBeenCalledWith(5, 20, { title: "Bài mới", is_preview: false, video_source: "none" }));
    expect(props.onSelect).toHaveBeenCalledWith(77);
  });

  it("xoá chương cần xác nhận; 409 CHAPTER_HAS_PROGRESS báo lý do và giữ chương", async () => {
    vi.mocked(api.deleteChapter).mockRejectedValue(new ApiError(409, { message: "x", code: "CHAPTER_HAS_PROGRESS" }));
    setup();
    await userEvent.click(await screen.findByRole("button", { name: "Xoá Chương 1" }));
    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("2 bài học, tất cả sẽ bị xoá");
    await userEvent.click(within(dialog).getByRole("button", { name: "Xoá chương" }));
    expect(await screen.findByText(/đã có học sinh học bài trong chương này/)).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Chương 1" })).toBeInTheDocument();
  });

  it("xoá bài cuối của khóa đang xuất bản: COURSE_LAST_LESSON", async () => {
    vi.mocked(api.deleteLesson).mockRejectedValue(new ApiError(409, { message: "x", code: "COURSE_LAST_LESSON" }));
    setup({ selectedLessonId: 1 });
    await userEvent.click(await screen.findByRole("button", { name: "Xoá bài" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "Xoá bài" }));
    expect(await screen.findByText(/Hãy ngừng bán khóa học/)).toBeInTheDocument();
    expect(api.deleteLesson).toHaveBeenCalledWith(5, 10, 1);
  });

  it("lưu bài: chỉ gửi tên + xem thử, lỗi 422 hiện dưới ô tên", async () => {
    vi.mocked(api.updateLesson)
      .mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { title: ["Tên bài không hợp lệ."] } }))
      .mockResolvedValueOnce(lesson(1, 10, 1, { title: "Bài một", is_preview: true }));
    setup({ selectedLessonId: 1 });
    const name = await screen.findByLabelText(/Tên bài học/);
    await userEvent.clear(name);
    await userEvent.type(name, "Bài một");
    await userEvent.click(screen.getByRole("checkbox", { name: /Cho xem thử/ }));
    await userEvent.click(screen.getByRole("button", { name: "Lưu bài học" }));
    expect(await screen.findByText("Tên bài không hợp lệ.")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Lưu bài học" }));
    await waitFor(() => expect(api.updateLesson).toHaveBeenLastCalledWith(5, 10, 1, { title: "Bài một", is_preview: true }));
    expect(await screen.findByText("Đã lưu bài học")).toBeInTheDocument();
  });

  it("link ngoài chỉ chọn được khi bài cho xem thử (lý do hiện ngay)", async () => {
    setup({ selectedLessonId: 1 });
    const ext = await screen.findByRole("radio", { name: /Dán link YouTube/ });
    expect(ext).toBeDisabled();
    expect(screen.getByText(/Chỉ bài cho xem thử mới được dùng link ngoài/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("checkbox", { name: /Cho xem thử/ }));
    expect(ext).toBeEnabled();
  });


  it("R2: xoá bài đang có lượt tải cục bộ thì dừng lượt tải đó (sau khi xoá thành công)", async () => {
    vi.mocked(api.deleteLesson).mockResolvedValue(undefined);
    const m = manager({ 1: { phase: "uploading", filename: "a.mp4", size: 10, sent: 1, percent: 10, etaSeconds: null } });
    setup({ selectedLessonId: 1, manager: m });
    await userEvent.click(await screen.findByRole("button", { name: "Xoá bài" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "Xoá bài" }));
    await waitFor(() => expect(m.cancel).toHaveBeenCalledWith(1));
  });

  it("R2: xoá chương dừng lượt tải của mọi bài trong chương", async () => {
    vi.mocked(api.deleteChapter).mockResolvedValue(undefined);
    const m = manager({ 2: { phase: "paused", filename: "a.mp4", size: 10, sent: 1, percent: 10, message: "x" } });
    setup({ manager: m });
    await userEvent.click(await screen.findByRole("button", { name: "Xoá Chương 1" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "Xoá chương" }));
    await waitFor(() => expect(m.cancel).toHaveBeenCalledWith(1));
    expect(m.cancel).toHaveBeenCalledWith(2);
  });

  it("R3: GET gửi trước khi lưu thứ tự nhưng về sau không ghi đè thứ tự mới", async () => {
    const reordered: Chapter[] = [
      { ...tree()[0]!, lessons: [lesson(1, 10, 1)] },
      { ...tree()[1]!, lessons: [lesson(2, 20, 1, { video_status: "ready" }), lesson(3, 20, 2, { video_status: "failed", duration_seconds: null })] },
    ];
    vi.mocked(api.saveCurriculumOrder).mockResolvedValue({ course_id: 5, chapters: reordered });
    const { rerenderWith } = setup({ selectedLessonId: 2 });
    await screen.findByRole("heading", { name: "Chương 1" });
    let release!: (v: { course_id: number; chapters: Chapter[] }) => void;
    vi.mocked(api.getCurriculum).mockReturnValueOnce(new Promise((r) => (release = r)));
    rerenderWith({ refreshTick: 1 }); // GET bay lên (dữ liệu cũ)
    await waitFor(() => expect(api.getCurriculum).toHaveBeenCalledTimes(2));
    await userEvent.click(await screen.findByRole("button", { name: "Xuống" }));
    await screen.findByText("Đã lưu thứ tự");
    await act(async () => release({ course_id: 5, chapters: tree() })); // về muộn
    const ch1 = screen.getByRole("region", { name: "Chương 1" });
    expect(within(ch1).queryByRole("link", { name: /Bài 2/ })).toBeNull();
    expect(within(screen.getByRole("region", { name: "Chương 2" })).getByRole("link", { name: /Bài 2/ })).toBeInTheDocument();
  });

  it("R8: quá 10 phút bài vẫn xử lý thì dừng hỏi, hiện nút Kiểm tra lại", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    setup();
    expect(await screen.findByText("Đang xử lý")).toBeInTheDocument();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_MAX_MS + 30_000);
    });
    expect(await screen.findByText("Video chưa cập nhật trạng thái")).toBeInTheDocument();
    const calls = vi.mocked(api.getCurriculum).mock.calls.length;
    await act(async () => {
      await vi.advanceTimersByTimeAsync(60_000);
    });
    expect(api.getCurriculum).toHaveBeenCalledTimes(calls);
    await userEvent.click(screen.getByRole("button", { name: "Kiểm tra lại" }));
    await waitFor(() => expect(vi.mocked(api.getCurriculum).mock.calls.length).toBeGreaterThan(calls));
    await waitFor(() => expect(screen.queryByText("Video chưa cập nhật trạng thái")).toBeNull());
  });

  describe("video", () => {
    it("tệp > 1 GB bị chặn trước khi tải, thông báo tiếng Việt", async () => {
      const m = manager();
      setup({ selectedLessonId: 1, manager: m });
      const big = new File(["x"], "lon.mp4", { type: "video/mp4" });
      Object.defineProperty(big, "size", { value: MAX_VIDEO_BYTES + 1 });
      await userEvent.upload(await screen.findByTestId("video-file-input"), big);
      expect(await screen.findByRole("alert")).toHaveTextContent(/Tệp quá lớn.*tối đa 1 GB/);
      expect(m.start).not.toHaveBeenCalled();
    });

    it("định dạng lạ bị chặn", async () => {
      const m = manager();
      setup({ selectedLessonId: 1, manager: m });
      const input = await screen.findByTestId("video-file-input");
      // Bỏ qua lọc `accept` của userEvent để kiểm logic của ứng dụng.
      await userEvent.setup({ applyAccept: false }).upload(input, new File(["x"], "a.avi"));
      expect(await screen.findByRole("alert")).toHaveTextContent(/Định dạng không được hỗ trợ/);
      expect(m.start).not.toHaveBeenCalled();
    });

    it("bài đã có video: hỏi xác nhận trước khi thay", async () => {
      const m = manager();
      setup({ selectedLessonId: 1, manager: m });
      const f = new File(["x"], "moi.mp4", { type: "video/mp4" });
      await userEvent.upload(await screen.findByTestId("video-file-input"), f);
      const dialog = await screen.findByRole("dialog");
      expect(m.start).not.toHaveBeenCalled();
      await userEvent.click(within(dialog).getByRole("button", { name: "Thay video" }));
      expect(m.start).toHaveBeenCalledWith(1, f);
    });

    it("bài chưa có video: chọn tệp là tải ngay", async () => {
      vi.mocked(api.getCurriculum).mockResolvedValue({
        course_id: 5,
        chapters: [{ id: 10, course_id: 5, title: "Chương 1", position: 1, lessons: [lesson(1, 10, 1, { video_source: "none", has_video_asset: false, video_status: null, duration_seconds: null })] }],
      });
      const m = manager();
      setup({ selectedLessonId: 1, manager: m });
      const f = new File(["x"], "moi.mp4", { type: "video/mp4" });
      await userEvent.upload(await screen.findByTestId("video-file-input"), f);
      expect(m.start).toHaveBeenCalledWith(1, f);
    });

    it("đang tải: thanh tiến độ %, nút Huỷ, khoá nút Lưu, ẩn ô chọn tệp", async () => {
      const m = manager({ 1: { phase: "uploading", filename: "bai-1.mp4", size: 100, sent: 45, percent: 45, etaSeconds: 200 } });
      setup({ selectedLessonId: 1, manager: m });
      const bar = await screen.findByRole("progressbar", { name: "Đang tải lên bai-1.mp4" });
      expect(bar).toHaveAttribute("aria-valuenow", "45");
      expect(screen.getAllByText(/45% · còn khoảng 4 phút/).length).toBeGreaterThan(0);
      expect(screen.getByText(/Mất mạng sẽ tự tiếp tục/)).toBeInTheDocument();
      expect(screen.getByRole("button", { name: "Lưu bài học" })).toBeDisabled();
      expect(screen.queryByTestId("video-file-input")).toBeNull();
      await userEvent.click(screen.getByRole("button", { name: "Huỷ tải lên" }));
      expect(m.cancel).toHaveBeenCalledWith(1);
      // nhãn trên cây cũng hiện %
      expect(screen.getAllByText(/Đang tải lên 45%/).length).toBeGreaterThan(0);
    });

    it("mất mạng: báo đang dừng + nút Tải tiếp", async () => {
      const m = manager({ 1: { phase: "paused", filename: "bai-1.mp4", size: 100, sent: 40, percent: 40, message: "Mất kết nối mạng." } });
      setup({ selectedLessonId: 1, manager: m });
      expect(await screen.findByText("Tải lên đang dừng")).toBeInTheDocument();
      await userEvent.click(screen.getByRole("button", { name: "Tải tiếp" }));
      expect(m.resume).toHaveBeenCalledWith(1);
    });

    it("video lỗi: hiện lý do từ API (VIDEO_INVALID) và cho chọn tệp khác", async () => {
      setup({ selectedLessonId: 3 });
      expect(await screen.findByText("Tệp tải lên không phải video hợp lệ. Hãy chọn tệp khác.")).toBeInTheDocument();
      expect(screen.getByText("Chọn tệp khác")).toBeInTheDocument();
    });

    it("tải bị gián đoạn (server còn 'uploading', không có lượt tải cục bộ): báo chọn lại tệp", async () => {
      vi.mocked(api.getCurriculum).mockResolvedValue({
        course_id: 5,
        chapters: [{ id: 10, course_id: 5, title: "Chương 1", position: 1, lessons: [lesson(1, 10, 1, { video_status: "uploading", duration_seconds: null })] }],
      });
      setup({ selectedLessonId: 1 });
      expect(await screen.findByText("Tải lên chưa hoàn tất")).toBeInTheDocument();
    });
  });

  describe("polling trạng thái xử lý", () => {
    it("bài đang xử lý được hỏi lại sau 3 giây và chuyển sang Sẵn sàng", async () => {
      vi.useFakeTimers({ shouldAdvanceTime: true });
      const done = tree();
      done[0]!.lessons[1] = lesson(2, 10, 2, { video_status: "ready", duration_seconds: 300 });
      vi.mocked(api.getCurriculum).mockResolvedValueOnce({ course_id: 5, chapters: tree() }).mockResolvedValue({ course_id: 5, chapters: done });
      setup();
      expect(await screen.findByText("Đang xử lý")).toBeInTheDocument();
      expect(api.getCurriculum).toHaveBeenCalledTimes(1);
      await act(async () => {
        await vi.advanceTimersByTimeAsync(3100);
      });
      await waitFor(() => expect(screen.queryByText("Đang xử lý")).toBeNull());
      expect(api.getCurriculum).toHaveBeenCalledTimes(2);
      expect(screen.getByText("5:00")).toBeInTheDocument();
    });

    it("không hỏi lại khi không có bài nào đang xử lý", async () => {
      vi.useFakeTimers({ shouldAdvanceTime: true });
      vi.mocked(api.getCurriculum).mockResolvedValue({ course_id: 5, chapters: [{ ...tree()[0]!, lessons: [lesson(1, 10, 1)] }] });
      setup();
      await screen.findByRole("heading", { name: "Chương 1" });
      await act(async () => {
        await vi.advanceTimersByTimeAsync(30_000);
      });
      expect(api.getCurriculum).toHaveBeenCalledTimes(1);
    });
  });
});
