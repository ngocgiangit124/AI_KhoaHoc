import { act, render, screen } from "@testing-library/react";
import { FORCED_LOGOUT_EVENT, LOGIN_REQUIRED_EVENT } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";

let pathname = "/khoa-hoc";
vi.mock("next/navigation", () => ({ usePathname: () => pathname }));

HTMLDialogElement.prototype.showModal = function showModalStub(this: HTMLDialogElement) {
  this.setAttribute("open", "");
};
HTMLDialogElement.prototype.close = function closeStub(this: HTMLDialogElement) {
  this.removeAttribute("open");
};

import { SessionEndedGate, loginUrl } from "./SessionEndedGate";

const emit = (name: string, code: string) => act(() => void window.dispatchEvent(new CustomEvent(name, { detail: { code } })));

describe("SessionEndedGate (hộp thoại phiên kết thúc)", () => {
  const assign = vi.fn();
  beforeEach(() => {
    pathname = "/khoa-hoc";
    assign.mockReset();
    Object.defineProperty(window, "location", { value: { pathname: "/khoa-hoc", search: "?grade=9", assign }, writable: true });
  });

  it("không có sự kiện → không hiện gì", () => {
    render(<SessionEndedGate />);
    expect(screen.queryByRole("dialog", { hidden: true })).toBeNull();
  });

  it("SESSION_REPLACED → hộp thoại 'thiết bị khác' + liên kết Đăng nhập lại kèm next và 'Đặt lại mật khẩu'", () => {
    render(<SessionEndedGate />);
    emit(FORCED_LOGOUT_EVENT, "SESSION_REPLACED");
    expect(screen.getByText("Tài khoản vừa đăng nhập trên thiết bị khác")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Đăng nhập lại" })).toHaveAttribute("href", "/dang-nhap?next=%2Fkhoa-hoc%3Fgrade%3D9");
    expect(screen.getByRole("link", { name: "Đặt lại mật khẩu" })).toHaveAttribute("href", "/quen-mat-khau");
  });

  it("SESSION_REVOKED → hộp thoại 'Bạn cần đăng nhập lại' (không còn chuyển thẳng)", () => {
    render(<SessionEndedGate />);
    emit(LOGIN_REQUIRED_EVENT, "SESSION_REVOKED");
    expect(screen.getByText("Bạn cần đăng nhập lại")).toBeInTheDocument();
    expect(assign).not.toHaveBeenCalled();
  });

  it("SESSION_EXPIRED / UNAUTHENTICATED → chuyển thẳng tới đăng nhập kèm next và thông báo hết phiên", () => {
    render(<SessionEndedGate />);
    emit(LOGIN_REQUIRED_EVENT, "SESSION_EXPIRED");
    expect(assign).toHaveBeenCalledWith("/dang-nhap?next=%2Fkhoa-hoc%3Fgrade%3D9&trang-thai=het-phien");
    expect(screen.queryByText("Bạn cần đăng nhập lại")).not.toBeInTheDocument();
  });

  it("ở trang đăng nhập/đăng ký/quên mật khẩu → bỏ qua sự kiện (không vòng lặp)", () => {
    pathname = "/dang-nhap";
    Object.defineProperty(window, "location", { value: { pathname: "/dang-nhap", search: "", assign }, writable: true });
    render(<SessionEndedGate />);
    emit(FORCED_LOGOUT_EVENT, "SESSION_REPLACED");
    emit(LOGIN_REQUIRED_EVENT, "SESSION_EXPIRED");
    expect(screen.queryByText("Tài khoản vừa đăng nhập trên thiết bị khác")).not.toBeInTheDocument();
    expect(assign).not.toHaveBeenCalled();
  });

  it("tới trang đăng nhập (bấm Đăng nhập lại) → hộp thoại tự đóng", () => {
    const { rerender } = render(<SessionEndedGate />);
    emit(FORCED_LOGOUT_EVENT, "SESSION_REPLACED");
    expect(screen.getByText("Tài khoản vừa đăng nhập trên thiết bị khác")).toBeInTheDocument();
    pathname = "/dang-nhap";
    rerender(<SessionEndedGate />);
    expect(screen.queryByText("Tài khoản vừa đăng nhập trên thiết bị khác")).not.toBeInTheDocument();
  });

  it("nút Back (đổi sang trang không phải đăng nhập) KHÔNG làm hộp thoại biến mất", () => {
    const { rerender } = render(<SessionEndedGate />);
    emit(FORCED_LOGOUT_EVENT, "SESSION_REPLACED");
    pathname = "/";
    rerender(<SessionEndedGate />);
    expect(screen.getByText("Tài khoản vừa đăng nhập trên thiết bị khác")).toBeInTheDocument();
  });
});

describe("loginUrl", () => {
  it("giữ next có query; bỏ next là trang xác thực kể cả khi có query; thêm thông báo", () => {
    expect(loginUrl("/khoa-hoc?grade=9")).toBe("/dang-nhap?next=%2Fkhoa-hoc%3Fgrade%3D9");
    expect(loginUrl("/dang-nhap?x=1")).toBe("/dang-nhap");
    expect(loginUrl("/quen-mat-khau/dat-lai?a=1")).toBe("/dang-nhap");
    expect(loginUrl("/", "het-phien")).toBe("/dang-nhap?trang-thai=het-phien");
  });
});
