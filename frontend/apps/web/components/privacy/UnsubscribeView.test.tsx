import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

const unsubscribe = vi.fn();
vi.mock("@/lib/privacy/api", () => ({ unsubscribeParentNotice: (t: string) => unsubscribe(t) }));
vi.mock("next/link", () => ({ default: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a> }));

import { UnsubscribeView, UNSUBSCRIBE_DONE_MESSAGE } from "./UnsubscribeView";

describe("UnsubscribeView", () => {
  beforeEach(() => {
    unsubscribe.mockReset();
    window.history.replaceState(null, "", "/phu-huynh/huy-nhan-thong-bao?t=12.tokenabc");
  });

  it("gỡ ?t= khỏi URL, KHÔNG tự POST khi mở trang", async () => {
    render(<UnsubscribeView />);
    expect(await screen.findByRole("button", { name: "Huỷ nhận thông báo" })).toBeInTheDocument();
    expect(window.location.search).toBe("");
    expect(unsubscribe).not.toHaveBeenCalled();
  });

  it("bấm gửi token đúng một lần (chặn bấm kép) rồi hiện thông điệp chung", async () => {
    let resolve!: () => void;
    unsubscribe.mockReturnValue(new Promise<void>((r) => (resolve = r)));
    render(<UnsubscribeView />);
    const btn = await screen.findByRole("button", { name: "Huỷ nhận thông báo" });
    const user = userEvent.setup();
    await user.dblClick(btn);
    expect(unsubscribe).toHaveBeenCalledTimes(1);
    expect(unsubscribe).toHaveBeenCalledWith("12.tokenabc");
    resolve();
    expect(await screen.findByText(UNSUBSCRIBE_DONE_MESSAGE)).toBeInTheDocument();
  });

  it("không có token -> báo liên kết không dùng được, không có nút gửi", async () => {
    window.history.replaceState(null, "", "/phu-huynh/huy-nhan-thong-bao");
    render(<UnsubscribeView />);
    await waitFor(() => expect(screen.getByText("Liên kết không dùng được")).toBeInTheDocument());
    expect(screen.queryByRole("button", { name: "Huỷ nhận thông báo" })).not.toBeInTheDocument();
  });
});
