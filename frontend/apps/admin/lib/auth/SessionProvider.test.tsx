import { act, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { STAFF_GATE_EVENT } from "./gate";
import { SessionProvider, useSession } from "./SessionProvider";
import { fetchSession } from "./session";

vi.mock("./session", () => ({ fetchSession: vi.fn() }));

function Probe() {
  const { state } = useSession();
  return <p data-testid="kind">{state.kind}</p>;
}

describe("SessionProvider", () => {
  it("ACCOUNT_LOCKED giữa phiên → trạng thái locked (AuthGate dựng màn khoá thay khung)", async () => {
    vi.mocked(fetchSession).mockResolvedValue({
      kind: "staff",
      user: { id: 1, name: "A", email: null, role: "admin", permissions: null, mustChangePassword: false, session: null },
    });
    render(
      <SessionProvider>
        <Probe />
      </SessionProvider>,
    );
    await waitFor(() => expect(screen.getByTestId("kind")).toHaveTextContent("staff"));
    act(() => {
      window.dispatchEvent(new CustomEvent(STAFF_GATE_EVENT, { detail: { code: "ACCOUNT_LOCKED" } }));
    });
    expect(screen.getByTestId("kind")).toHaveTextContent("locked");
  });

  it("các mã cổng khác không đổi trạng thái ở đây (SessionWatcher xử lý)", async () => {
    vi.mocked(fetchSession).mockResolvedValue({ kind: "mfa_required" });
    render(
      <SessionProvider>
        <Probe />
      </SessionProvider>,
    );
    await waitFor(() => expect(screen.getByTestId("kind")).toHaveTextContent("mfa_required"));
    act(() => {
      window.dispatchEvent(new CustomEvent(STAFF_GATE_EVENT, { detail: { code: "PASSWORD_CHANGE_REQUIRED" } }));
    });
    expect(screen.getByTestId("kind")).toHaveTextContent("mfa_required");
  });
});
