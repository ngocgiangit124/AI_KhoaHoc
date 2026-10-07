import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";
import "@testing-library/jest-dom/vitest";

afterEach(() => {
  cleanup();
});

// jsdom chưa có HTMLDialogElement.showModal/close (Dialog v2 dựa trên <dialog> gốc). Mô phỏng đủ hành vi cần kiểm thử:
// mở = đặt thuộc tính `open`; Esc = bắn sự kiện `cancel` (huỷ được bằng preventDefault) rồi đóng nếu không bị chặn.
if (typeof HTMLDialogElement !== "undefined" && typeof HTMLDialogElement.prototype.showModal !== "function") {
  const escapeHandlers = new WeakMap<HTMLDialogElement, (e: KeyboardEvent) => void>();
  HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement) {
    this.setAttribute("open", "");
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== "Escape") return;
      const cancel = new Event("cancel", { cancelable: true });
      this.dispatchEvent(cancel);
      if (!cancel.defaultPrevented) this.close();
    };
    escapeHandlers.set(this, onKey);
    document.addEventListener("keydown", onKey);
  };
  HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement) {
    const onKey = escapeHandlers.get(this);
    if (onKey) document.removeEventListener("keydown", onKey);
    this.removeAttribute("open");
    this.dispatchEvent(new Event("close"));
  };
}
