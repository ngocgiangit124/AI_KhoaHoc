import { StrictMode } from "react";
import { act, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import * as api from "./api";
import { PendingOrdersProvider, usePendingOrders } from "./PendingOrders";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
let path = "/quan-tri";
vi.mock("next/navigation", () => ({ usePathname: () => path }));
vi.mock("./api", async (orig) => ({ ...(await orig<typeof import("./api")>()), getPendingCount: vi.fn() }));

function Probe() {
  const { count, refresh } = usePendingOrders();
  return (
    <button type="button" onClick={refresh}>
      count:{count === null ? "?" : count}
    </button>
  );
}

beforeEach(() => {
  vi.resetAllMocks();
  path = "/quan-tri";
  vi.mocked(api.getPendingCount).mockResolvedValue({ pending_manual: 6, expiring_soon: 1 });
});

describe("PendingOrdersProvider (số đơn chờ trên menu)", () => {
  it("StrictMode (effect chạy 2 lần) vẫn hiện được số; refresh sau thao tác gọi lại", async () => {
    render(
      <StrictMode>
        <PendingOrdersProvider enabled>
          <Probe />
        </PendingOrdersProvider>
      </StrictMode>,
    );
    expect(await screen.findByRole("button", { name: "count:6" })).toBeInTheDocument();
    vi.mocked(api.getPendingCount).mockResolvedValue({ pending_manual: 5, expiring_soon: 0 });
    act(() => screen.getByRole("button").click());
    expect(await screen.findByRole("button", { name: "count:5" })).toBeInTheDocument();
  });

  it("không có quyền → không gọi API; lỗi (429/mạng) bỏ qua, giữ số cũ", async () => {
    const { unmount } = render(
      <PendingOrdersProvider enabled={false}>
        <Probe />
      </PendingOrdersProvider>,
    );
    expect(screen.getByRole("button", { name: "count:?" })).toBeInTheDocument();
    expect(api.getPendingCount).not.toHaveBeenCalled();
    unmount();
    render(
      <PendingOrdersProvider enabled>
        <Probe />
      </PendingOrdersProvider>,
    );
    await screen.findByRole("button", { name: "count:6" });
    vi.mocked(api.getPendingCount).mockRejectedValue(new Error("429"));
    act(() => screen.getByRole("button").click());
    await waitFor(() => expect(api.getPendingCount).toHaveBeenCalledTimes(2));
    expect(screen.getByRole("button", { name: "count:6" })).toBeInTheDocument();
  });
});

describe("PendingOrdersProvider: chu kỳ và tab ẩn", () => {
  const setVisibility = (v: "visible" | "hidden") => Object.defineProperty(document, "visibilityState", { configurable: true, get: () => v });
  const flush = async (ms: number) => {
    await act(async () => {
      await vi.advanceTimersByTimeAsync(ms);
    });
  };

  it("gọi lại mỗi 60 giây khi tab hiện; tab ẩn thì không gọi; visibilitychange trong 30 giây sau lần gọi trước không gọi lại", async () => {
    vi.useFakeTimers();
    try {
      setVisibility("visible");
      render(
        <PendingOrdersProvider enabled>
          <Probe />
        </PendingOrdersProvider>,
      );
      await flush(0);
      expect(api.getPendingCount).toHaveBeenCalledTimes(1);
      await flush(59_000);
      expect(api.getPendingCount).toHaveBeenCalledTimes(1);
      await flush(1_000);
      expect(api.getPendingCount).toHaveBeenCalledTimes(2);

      // Tab ẩn: tới hạn 60 giây cũng không gọi.
      setVisibility("hidden");
      await flush(60_000);
      expect(api.getPendingCount).toHaveBeenCalledTimes(2);

      // Quay lại tab sau > 30 giây kể từ lần gọi trước: gọi lại.
      setVisibility("visible");
      act(() => void document.dispatchEvent(new Event("visibilitychange")));
      await flush(0);
      expect(api.getPendingCount).toHaveBeenCalledTimes(3);

      // Ẩn/hiện lại trong 30 giây: không gọi thêm.
      await flush(10_000);
      setVisibility("hidden");
      act(() => void document.dispatchEvent(new Event("visibilitychange")));
      setVisibility("visible");
      act(() => void document.dispatchEvent(new Event("visibilitychange")));
      await flush(0);
      expect(api.getPendingCount).toHaveBeenCalledTimes(3);
    } finally {
      setVisibility("visible");
      vi.useRealTimers();
    }
  });
});
