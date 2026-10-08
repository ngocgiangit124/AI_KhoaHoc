import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { internalHref, useUnsavedChangesGuard } from "./useUnsavedChangesGuard";

const push = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

/* eslint-disable @next/next/no-html-link-for-pages -- cố ý dùng <a> thường để thử bộ bắt click ở cấp document */
function Harness({ history = false }: { history?: boolean }) {
  const { setDirty, rearm, dialog } = useUnsavedChangesGuard({ guardHistory: history, description: "Mô tả." });
  return (
    <div>
      <a href="/quan-tri/khoa-hoc">Sidebar</a>
      <a href="#x">Neo</a>
      <a href="https://example.org/">Ngoài</a>
      <a href="/quan-tri/khoa-hoc" target="_blank">Tab mới</a>
      <button onClick={() => setDirty(true)}>Bẩn</button>
      <button onClick={() => setDirty(false)}>Sạch</button>
      <button onClick={rearm}>Rearm</button>
      {dialog}
    </div>
  );
}

beforeEach(() => {
  push.mockReset();
  window.history.replaceState(null, "", "/quan-tri/khoa-hoc/1/bai-tap/2");
});

describe("useUnsavedChangesGuard", () => {
  it("sạch: để liên kết đi tiếp (không hỏi)", async () => {
    render(<Harness />);
    const a = screen.getByRole("link", { name: "Sidebar" });
    a.addEventListener("click", (e) => e.preventDefault()); // chặn jsdom điều hướng
    await userEvent.click(a);
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("bẩn: click liên kết nội bộ ngoài khung → hộp xác nhận; ở lại không đi; bỏ thay đổi thì router.push", async () => {
    render(<Harness />);
    await userEvent.click(screen.getByRole("button", { name: "Bẩn" }));
    await userEvent.click(screen.getByRole("link", { name: "Sidebar" }));
    expect(push).not.toHaveBeenCalled();
    await screen.findByRole("dialog");
    await userEvent.click(screen.getByRole("button", { name: "Ở lại để lưu" }));
    expect(push).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole("link", { name: "Sidebar" }));
    await userEvent.click(await screen.findByRole("button", { name: "Bỏ thay đổi" }));
    expect(push).toHaveBeenCalledWith("/quan-tri/khoa-hoc");
  });

  it("bẩn: neo #, liên kết ngoài, _blank không bị chặn", async () => {
    render(<Harness />);
    await userEvent.click(screen.getByRole("button", { name: "Bẩn" }));
    for (const name of ["Neo", "Ngoài", "Tab mới"]) {
      const a = screen.getByRole("link", { name });
      a.addEventListener("click", (e) => e.preventDefault());
      await userEvent.click(a);
    }
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("nút Back khi bẩn → hộp xác nhận; bỏ thay đổi thì history.back()", async () => {
    const back = vi.spyOn(window.history, "back").mockImplementation(() => undefined);
    render(<Harness history />);
    await userEvent.click(screen.getByRole("button", { name: "Bẩn" }));
    window.dispatchEvent(new PopStateEvent("popstate"));
    await userEvent.click(await screen.findByRole("button", { name: "Bỏ thay đổi" }));
    expect(back).toHaveBeenCalled();
    back.mockRestore();
  });

  it("rearm: sau khi URL bị replace, lần bẩn kế tiếp đẩy lại mục đệm", async () => {
    const pushState = vi.spyOn(window.history, "pushState");
    render(<Harness history />);
    await userEvent.click(screen.getByRole("button", { name: "Bẩn" }));
    expect(pushState).toHaveBeenCalledTimes(1);
    await userEvent.click(screen.getByRole("button", { name: "Sạch" }));
    await userEvent.click(screen.getByRole("button", { name: "Bẩn" }));
    expect(pushState).toHaveBeenCalledTimes(1); // mục đệm cũ còn nguyên
    await userEvent.click(screen.getByRole("button", { name: "Sạch" }));
    await userEvent.click(screen.getByRole("button", { name: "Rearm" }));
    await userEvent.click(screen.getByRole("button", { name: "Bẩn" }));
    expect(pushState).toHaveBeenCalledTimes(2);
    pushState.mockRestore();
  });

  it("internalHref: cùng trang / khác origin / download → null", () => {
    const mk = (attrs: string) => {
      const d = document.createElement("div");
      d.innerHTML = `<a ${attrs}>x</a>`;
      return d.firstElementChild as HTMLAnchorElement;
    };
    expect(internalHref(mk('href="/quan-tri/khoa-hoc/1/bai-tap/2"'))).toBeNull();
    expect(internalHref(mk('href="/quan-tri"'))).toBe("/quan-tri");
    expect(internalHref(mk('href="/quan-tri" download'))).toBeNull();
    expect(internalHref(mk('href="https://example.org/a"'))).toBeNull();
  });
});
