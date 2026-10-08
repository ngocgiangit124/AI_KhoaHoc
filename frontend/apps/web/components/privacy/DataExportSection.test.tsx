import { render as rtlRender, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactElement } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { beforeEach, describe, expect, it, vi } from "vitest";

const fetchExportStatus = vi.fn();
const downloadDataExport = vi.fn();
vi.mock("@/lib/privacy/api", () => ({
  fetchExportStatus: (...a: unknown[]) => fetchExportStatus(...a),
  downloadDataExport: (...a: unknown[]) => downloadDataExport(...a),
}));

import { DataExportSection } from "./DataExportSection";

const render = (ui: ReactElement) => rtlRender(<ToastProvider>{ui}</ToastProvider>);

beforeEach(() => {
  fetchExportStatus.mockReset().mockResolvedValue({ limit_per_day: 2, used_today: 1, remaining: 1, resets_at: "2026-10-09T00:00:00+07:00" });
  downloadDataExport.mockReset();
  HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement) {
    this.setAttribute("open", "");
  };
  HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement) {
    this.removeAttribute("open");
  };
});

describe("DataExportSection", () => {
  it("hiện số lượt còn lại", async () => {
    render(<DataExportSection />);
    expect(await screen.findByTestId("export-remaining")).toHaveTextContent("Còn 1 lượt hôm nay");
  });

  it("429 DATA_EXPORT_LIMIT: khoá nút tải, hiện giờ đặt lại (giờ VN)", async () => {
    downloadDataExport.mockRejectedValueOnce(
      new ApiError(429, { message: "m", code: "DATA_EXPORT_LIMIT", errors: { limit: 2, resets_at: "2026-10-09T00:00:00+07:00" } } as unknown as ConstructorParameters<typeof ApiError>[1], 3600),
    );
    const user = userEvent.setup();
    render(<DataExportSection />);
    await user.click(await screen.findByRole("button", { name: "Tải dữ liệu của tôi" }));
    await user.type(await screen.findByLabelText(/Mật khẩu hiện tại/), "matkhau-123");
    await user.click(screen.getByRole("button", { name: "Tải về" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Tải dữ liệu của tôi" })).toBeDisabled());
    expect(screen.getByText("Bạn đã tải 2 lần hôm nay. Thử lại sau 00:00, 09/10/2026.")).toBeInTheDocument();
  });
});
