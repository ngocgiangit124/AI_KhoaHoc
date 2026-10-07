import { act, render, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import * as api from "@/lib/curriculum/api";
import * as tusModule from "@/lib/curriculum/tusUpload";
import type { TusCallbacks, TusHandle } from "@/lib/curriculum/tusUpload";
import type { VideoUploadSession } from "@/lib/curriculum/types";
import { UploadProvider, useUploadManager } from "./useUploadManager";
import type { ReactNode } from "react";

const wrapper = ({ children }: { children: ReactNode }) => <UploadProvider>{children}</UploadProvider>;

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
vi.mock("@/lib/curriculum/api");
vi.mock("@/lib/curriculum/tusUpload");

const session: VideoUploadSession = {
  video_asset_id: 9,
  status: "uploading",
  upload: { protocol: "tus", tus_endpoint: "http://video.test/tus", headers: { VideoId: "v" }, expires_at: "2026-10-07T10:00:00Z" },
};
const file = new File([new Uint8Array(100)], "bai-1.mp4", { type: "video/mp4" });

let cb: TusCallbacks;
let handle: { resume: ReturnType<typeof vi.fn>; abort: ReturnType<typeof vi.fn> };

beforeEach(() => {
  vi.resetAllMocks();
  handle = { resume: vi.fn(), abort: vi.fn().mockResolvedValue(undefined) };
  vi.mocked(api.startVideoUpload).mockResolvedValue(session);
  vi.mocked(tusModule.createTusUpload).mockImplementation((_f, _s, callbacks) => {
    cb = callbacks;
    return handle as unknown as TusHandle;
  });
});

describe("useUploadManager", () => {
  it("xin phiên tải, tải theo endpoint+header của API, báo % và xong thì gọi onFinished(uploaded)", async () => {
    const onFinished = vi.fn();
    const { result } = renderHook(() => useUploadManager(5, onFinished), { wrapper });
    act(() => result.current.start(7, file));
    expect(result.current.uploads[7]?.phase).toBe("starting");
    expect(result.current.hasActive).toBe(true);
    await waitFor(() => expect(tusModule.createTusUpload).toHaveBeenCalled());
    expect(api.startVideoUpload).toHaveBeenCalledWith(5, 7, { name: "bai-1.mp4", size: 100 });
    expect(vi.mocked(tusModule.createTusUpload).mock.calls[0]![1]).toBe(session.upload);

    act(() => cb.onProgress(50, 100));
    expect(result.current.uploads[7]).toMatchObject({ phase: "uploading", percent: 50, filename: "bai-1.mp4" });

    act(() => cb.onSuccess());
    expect(result.current.uploads[7]).toBeUndefined();
    expect(result.current.hasActive).toBe(false);
    expect(onFinished).toHaveBeenCalledWith(7, "uploaded");
  });

  it("không tải hai lần cùng một bài", async () => {
    const { result } = renderHook(() => useUploadManager(5, vi.fn()), { wrapper });
    act(() => result.current.start(7, file));
    act(() => result.current.start(7, file));
    await waitFor(() => expect(tusModule.createTusUpload).toHaveBeenCalledTimes(1));
    expect(api.startVideoUpload).toHaveBeenCalledTimes(1);
  });

  it("rớt mạng: dừng ở % hiện tại, có mạng lại (sự kiện online) thì tự tải tiếp", async () => {
    const { result } = renderHook(() => useUploadManager(5, vi.fn()), { wrapper });
    act(() => result.current.start(7, file));
    await waitFor(() => expect(tusModule.createTusUpload).toHaveBeenCalled());
    act(() => cb.onProgress(40, 100));
    act(() => cb.onError({ retryable: true, message: null, status: null }));
    expect(result.current.uploads[7]).toMatchObject({ phase: "paused", percent: 40 });
    expect(result.current.hasActive).toBe(true);

    act(() => {
      window.dispatchEvent(new Event("online"));
    });
    expect(handle.resume).toHaveBeenCalledTimes(1);
    expect(result.current.uploads[7]?.phase).toBe("uploading");
  });

  it("bấm Tải tiếp thủ công", async () => {
    const { result } = renderHook(() => useUploadManager(5, vi.fn()), { wrapper });
    act(() => result.current.start(7, file));
    await waitFor(() => expect(tusModule.createTusUpload).toHaveBeenCalled());
    act(() => cb.onError({ retryable: true, message: null, status: null }));
    act(() => result.current.resume(7));
    expect(handle.resume).toHaveBeenCalled();
  });

  it("lỗi không tải lại được (422 VIDEO_INVALID): hiện message của API và báo dừng", async () => {
    const onFinished = vi.fn();
    const { result } = renderHook(() => useUploadManager(5, onFinished), { wrapper });
    act(() => result.current.start(7, file));
    await waitFor(() => expect(tusModule.createTusUpload).toHaveBeenCalled());
    act(() => cb.onError({ retryable: false, message: "Tệp tải lên không phải video hợp lệ.", status: 422 }));
    expect(result.current.uploads[7]).toMatchObject({ phase: "failed", message: "Tệp tải lên không phải video hợp lệ." });
    expect(result.current.hasActive).toBe(false);
    expect(onFinished).toHaveBeenCalledWith(7, "stopped");
    act(() => result.current.dismiss(7));
    expect(result.current.uploads[7]).toBeUndefined();
  });

  it("huỷ: dừng tus, xoá trạng thái, onFinished(stopped); callback muộn bị bỏ qua", async () => {
    const onFinished = vi.fn();
    const { result } = renderHook(() => useUploadManager(5, onFinished), { wrapper });
    act(() => result.current.start(7, file));
    await waitFor(() => expect(tusModule.createTusUpload).toHaveBeenCalled());
    await act(async () => result.current.cancel(7));
    expect(handle.abort).toHaveBeenCalled();
    expect(result.current.uploads[7]).toBeUndefined();
    expect(onFinished).toHaveBeenCalledWith(7, "stopped");
    act(() => cb.onProgress(90, 100));
    expect(result.current.uploads[7]).toBeUndefined();
  });

  it("API từ chối phiên tải (hết hạn mức): báo lỗi tiếng Việt, không mở TUS", async () => {
    vi.mocked(api.startVideoUpload).mockRejectedValue(new ApiError(422, { message: "x", code: "VIDEO_QUOTA_EXCEEDED" }));
    const { result } = renderHook(() => useUploadManager(5, vi.fn()), { wrapper });
    act(() => result.current.start(7, file));
    await waitFor(() => expect(result.current.uploads[7]?.phase).toBe("failed"));
    expect(result.current.uploads[7]).toMatchObject({ message: expect.stringMatching(/hạn mức/) });
    expect(tusModule.createTusUpload).not.toHaveBeenCalled();
  });

  it("cảnh báo khi đóng tab lúc đang tải", async () => {
    const { result } = renderHook(() => useUploadManager(5, vi.fn()), { wrapper });
    const idle = new Event("beforeunload", { cancelable: true });
    window.dispatchEvent(idle);
    expect(idle.defaultPrevented).toBe(false);
    act(() => result.current.start(7, file));
    const busy = new Event("beforeunload", { cancelable: true });
    window.dispatchEvent(busy);
    expect(busy.defaultPrevented).toBe(true);
  });

  it("R1: lượt tải sống tiếp khi màn hình đang dùng nó bị gỡ (điều hướng trong ứng dụng)", async () => {
    const seen: Array<[number, string]> = [];
    let mgr: ReturnType<typeof useUploadManager> | null = null;
    function Starter() {
      mgr = useUploadManager(5, () => undefined);
      return null;
    }
    function Observer() {
      const m = useUploadManager(5, (id, outcome) => seen.push([id, outcome]));
      return <span data-testid="phase">{m.uploads[7]?.phase ?? "none"}</span>;
    }
    const view = (showStarter: boolean) => (
      <UploadProvider>
        {showStarter ? <Starter /> : null}
        <Observer />
      </UploadProvider>
    );
    const { rerender, getByTestId } = render(view(true));
    act(() => mgr!.start(7, file));
    await waitFor(() => expect(tusModule.createTusUpload).toHaveBeenCalled());
    act(() => cb.onProgress(30, 100));
    rerender(view(false)); // Starter (màn hình sửa khóa) bị gỡ
    expect(getByTestId("phase").textContent).toBe("uploading");
    expect(handle.abort).not.toHaveBeenCalled();
    act(() => cb.onSuccess());
    expect(seen).toEqual([[7, "uploaded"]]);
  });
});
