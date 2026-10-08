import { render as rtlRender, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactElement } from "react";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AuthState } from "@/lib/auth/AuthProvider";

vi.mock("next/link", () => ({ default: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a> }));
let authState: AuthState;
const refresh = vi.fn();
vi.mock("@/lib/auth/AuthProvider", () => ({ useAuth: () => ({ state: authState, refresh }) }));
const fetchConsents = vi.fn();
const acceptConsents = vi.fn();
vi.mock("@/lib/privacy/api", () => ({ fetchConsents: (...a: unknown[]) => fetchConsents(...a), acceptConsents: (v: string) => acceptConsents(v) }));

import { PolicyAcceptanceBanner } from "./PolicyAcceptanceBanner";

const render = (ui: ReactElement) => rtlRender(<ToastProvider>{ui}</ToastProvider>);
const user = (needs: boolean): AuthState => ({
  status: "user",
  user: { id: 1, name: "A", email: "a@x.vn", phone: null, role: "hoc_sinh", grade_level: 9, is_verified: true, needs_policy_acceptance: needs },
});
const consents = (v: string) => ({ data: [], meta: { current_policy_version: v, needs_acceptance: true } });

describe("PolicyAcceptanceBanner", () => {
  beforeEach(() => {
    fetchConsents.mockReset().mockResolvedValue(consents("2026-10-tam"));
    acceptConsents.mockReset();
    refresh.mockReset();
  });

  it("không hiện khi không cần chấp nhận lại", () => {
    authState = user(false);
    render(<PolicyAcceptanceBanner />);
    expect(screen.queryByText("Điều khoản đã cập nhật")).not.toBeInTheDocument();
  });

  it("2 ô không tick sẵn; chưa tick thì báo lỗi, tick đủ thì POST đúng phiên bản", async () => {
    authState = user(true);
    acceptConsents.mockResolvedValue(consents("2026-10-tam"));
    render(<PolicyAcceptanceBanner />);
    const u = userEvent.setup();
    const boxes = screen.getAllByRole("checkbox");
    expect(boxes).toHaveLength(2);
    boxes.forEach((b) => expect(b).not.toBeChecked());
    await screen.findByText("2026-10-tam");
    await u.click(screen.getByRole("button", { name: "Đồng ý" }));
    expect(screen.getByRole("alert")).toHaveTextContent("tích cả hai ô");
    expect(acceptConsents).not.toHaveBeenCalled();
    await u.click(boxes[0]!);
    await u.click(boxes[1]!);
    await u.click(screen.getByRole("button", { name: "Đồng ý" }));
    await waitFor(() => expect(acceptConsents).toHaveBeenCalledWith("2026-10-tam"));
    await waitFor(() => expect(refresh).toHaveBeenCalled());
  });

  it("409 CONSENT_VERSION_CHANGED: bỏ tick, dùng phiên bản mới, nhắc xem lại", async () => {
    authState = user(true);
    acceptConsents.mockRejectedValueOnce(
      new ApiError(409, { message: "m", code: "CONSENT_VERSION_CHANGED", errors: { current_version: "2026-11" } as unknown as Record<string, string[]> }),
    );
    render(<PolicyAcceptanceBanner />);
    const u = userEvent.setup();
    const boxes = screen.getAllByRole("checkbox");
    await screen.findByText("2026-10-tam");
    await u.click(boxes[0]!);
    await u.click(boxes[1]!);
    await u.click(screen.getByRole("button", { name: "Đồng ý" }));
    expect(await screen.findByText("2026-11")).toBeInTheDocument();
    expect(screen.getByText(/vừa được cập nhật lại/)).toBeInTheDocument();
    screen.getAllByRole("checkbox").forEach((b) => expect(b).not.toBeChecked());
    expect(refresh).not.toHaveBeenCalled();
  });
});
