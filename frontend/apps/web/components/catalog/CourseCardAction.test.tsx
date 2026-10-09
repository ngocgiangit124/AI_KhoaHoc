import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AuthState } from "@/lib/auth/AuthProvider";

vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

const authFetch = vi.fn();
vi.mock("@/lib/api", () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }));

let authState: AuthState;
const refreshAuth = vi.fn();
vi.mock("@/lib/auth/AuthProvider", () => ({
  useOptionalAuth: () => ({ state: authState, refresh: refreshAuth }),
  useAuth: () => ({ state: authState, refresh: refreshAuth }),
}));

const addCartItem = vi.fn();
vi.mock("@/lib/orders/api", () => ({ addCartItem: (...a: unknown[]) => addCartItem(...a) }));

import { ToastProvider } from "@vitaminvui/ui/v2";
import { CardCartProvider } from "./CardCartProvider";
import { CourseCardAction } from "./CourseCardAction";

const student = { id: 1, name: "A", email: "a@example.com", phone: null, role: "hoc_sinh", grade_level: 9, is_verified: true, parent_consent_status: "not_required" as const };
const cartBody = (ids: number[]) => ({
  items: ids.map((id) => ({ course_id: id, title: `K${id}`, slug: `k${id}`, grade_level: 9, thumbnail_url: null, price: 100000, unavailable: false, discount_amount: 0, final_amount: 100000, added_at: "2026-10-09T00:00:00+07:00" })),
  coupon: null,
  pricing: { subtotal: 0, discount: 0, total: 0 },
  notices: [],
});

function mockApi(cartIds: number[], owned: number[], pending: number[] = []) {
  authFetch.mockImplementation(async (url: string) => {
    if (url.startsWith("/api/v1/cart")) return cartBody(cartIds);
    if (url.startsWith("/api/v1/me/courses")) return { data: owned.map((id) => ({ course: { id } })), pending: pending.map((id) => ({ course: { id } })) };
    throw new Error(url);
  });
}

function renderCards(paid = true) {
  return render(
    <ToastProvider>
      <CardCartProvider paidCheckoutEnabled={paid}>
        <ul>
          {[10, 11, 12, 13].map((id) => (
            <li key={id}>
              <span>Thẻ {id}</span>
              <CourseCardAction course={{ id, slug: `k${id}`, title: `Khóa ${id}`, isFree: id === 13 }} />
            </li>
          ))}
        </ul>
      </CardCartProvider>
    </ToastProvider>,
  );
}

beforeEach(() => {
  authFetch.mockReset();
  addCartItem.mockReset();
  refreshAuth.mockReset();
  authState = { status: "user", user: student };
});

describe("Nút trên thẻ khóa", () => {
  it("1 lần tải cho cả trang (cart + me/courses); chưa có -> Thêm vào giỏ, trong giỏ -> Xem giỏ hàng, đã sở hữu -> không nút, khóa miễn phí -> không nút", async () => {
    mockApi([11], [12], [13]);
    renderCards();
    expect(await screen.findByRole("button", { name: /^Thêm vào giỏ: / })).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /^Thêm vào giỏ: / })).toHaveLength(1);
    expect(screen.getByRole("link", { name: "Xem giỏ hàng: Khóa 11" })).toHaveAttribute("href", "/gio-hang");
    expect(screen.getByRole("link", { name: "Vào học: Khóa 12" })).toHaveAttribute("href", "/hoc/12");
    expect(authFetch).toHaveBeenCalledTimes(2);
  });

  it("chờ duyệt: nhãn Chờ duyệt (không bấm được)", async () => {
    mockApi([], [], [10]);
    renderCards();
    expect(await screen.findByText("Chờ duyệt")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Chờ duyệt" })).not.toBeInTheDocument();
  });

  it("bấm Thêm vào giỏ: POST, thẻ chuyển Xem giỏ hàng, làm mới số giỏ ở header", async () => {
    mockApi([], []);
    addCartItem.mockResolvedValue(cartBody([10]));
    renderCards();
    const btns = await screen.findAllByRole("button", { name: /^Thêm vào giỏ: / });
    await userEvent.click(btns[0]!);
    await waitFor(() => expect(screen.getAllByRole("link", { name: /^Xem giỏ hàng: / })).toHaveLength(1));
    expect(addCartItem).toHaveBeenCalledWith(10);
    expect(await screen.findByText("Đã thêm Khóa 10 vào giỏ")).toBeInTheDocument();
    expect(refreshAuth).toHaveBeenCalled();
  });

  it("409 ALREADY_OWNED: nút biến mất (không báo lỗi)", async () => {
    mockApi([], []);
    addCartItem.mockRejectedValue(new ApiError(409, { message: "x", code: "ALREADY_OWNED" }));
    renderCards();
    const btns = await screen.findAllByRole("button", { name: /^Thêm vào giỏ: / });
    await userEvent.click(btns[0]!);
    await waitFor(() => expect(screen.getAllByRole("button", { name: /^Thêm vào giỏ: / })).toHaveLength(btns.length - 1));
  });

  it("khách: Mua khóa học dẫn tới đăng nhập kèm next về trang khóa; không gọi API", () => {
    authState = { status: "guest" };
    renderCards();
    const links = screen.getAllByRole("link", { name: /^Mua khóa học: / });
    expect(links).toHaveLength(3); // khóa miễn phí không có nút
    expect(links[0]).toHaveAttribute("href", `/dang-nhap?next=${encodeURIComponent("/khoa-hoc/k10")}`);
    expect(authFetch).not.toHaveBeenCalled();
  });

  it("thanh toán tạm đóng: không nút, không gọi API", () => {
    renderCards(false);
    expect(screen.queryByRole("button", { name: /^Thêm vào giỏ: / })).not.toBeInTheDocument();
    expect(authFetch).not.toHaveBeenCalled();
  });

  it("lỗi tải trạng thái giỏ: không thẻ nào hiện nút", async () => {
    authFetch.mockRejectedValue(new Error("x"));
    renderCards();
    await waitFor(() => expect(authFetch).toHaveBeenCalled());
    await new Promise((r) => setTimeout(r, 20));
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
    expect(document.querySelectorAll('li > div[aria-hidden="true"].h-11')).toHaveLength(3); // giữ chỗ 44px
  });
});
