import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const fetchCurrentUser = vi.hoisted(() => vi.fn());
vi.mock("./api", () => ({ fetchCurrentUser }));

import { AuthProvider, useAuth } from "./AuthProvider";

const user = { id: 1, name: "A", email: "a@example.com", phone: null, role: "hoc_sinh", grade_level: 9, is_verified: true, cart_count: 1 };

function Probe() {
  const { state, refresh } = useAuth();
  const [returned, setReturned] = useState("-");
  return (
    <div>
      <p data-testid="state">{state.status}</p>
      <p data-testid="cart">{state.status === "user" ? state.user.cart_count : "-"}</p>
      <p data-testid="returned">{returned}</p>
      <button type="button" onClick={() => void refresh().then((s) => setReturned(s.status))}>
        làm mới
      </button>
    </div>
  );
}
const setup = () => render(<AuthProvider><Probe /></AuthProvider>);

beforeEach(() => fetchCurrentUser.mockReset());

describe("AuthProvider.refresh", () => {
  it("đang là user mà làm mới gặp lỗi tạm thời -> giữ nguyên user cũ (state và giá trị trả về)", async () => {
    fetchCurrentUser.mockResolvedValueOnce({ kind: "user", user });
    setup();
    await waitFor(() => expect(screen.getByTestId("state")).toHaveTextContent("user"));
    fetchCurrentUser.mockResolvedValueOnce({ kind: "error" });
    await userEvent.click(screen.getByRole("button", { name: "làm mới" }));
    await waitFor(() => expect(screen.getByTestId("returned")).toHaveTextContent("user"));
    expect(screen.getByTestId("state")).toHaveTextContent("user");
  });

  it("làm mới thành công cập nhật user; guest thì chuyển guest", async () => {
    fetchCurrentUser.mockResolvedValueOnce({ kind: "user", user });
    setup();
    await waitFor(() => expect(screen.getByTestId("state")).toHaveTextContent("user"));
    fetchCurrentUser.mockResolvedValueOnce({ kind: "user", user: { ...user, cart_count: 5 } });
    await userEvent.click(screen.getByRole("button", { name: "làm mới" }));
    await waitFor(() => expect(screen.getByTestId("cart")).toHaveTextContent("5"));
    fetchCurrentUser.mockResolvedValueOnce({ kind: "guest" });
    await userEvent.click(screen.getByRole("button", { name: "làm mới" }));
    await waitFor(() => expect(screen.getByTestId("state")).toHaveTextContent("guest"));
  });

  it("chưa có user mà lỗi -> vẫn là error (nút Thử lại của màn)", async () => {
    fetchCurrentUser.mockResolvedValueOnce({ kind: "error" });
    setup();
    await waitFor(() => expect(screen.getByTestId("state")).toHaveTextContent("error"));
  });
});
