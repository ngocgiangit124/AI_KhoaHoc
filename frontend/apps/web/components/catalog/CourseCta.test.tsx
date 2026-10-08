import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { AuthState } from "@/lib/auth/AuthProvider";

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

const authFetch = vi.fn();
vi.mock("@/lib/api", () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }));

vi.mock("@/lib/auth/api", () => ({ sendOtp: vi.fn().mockResolvedValue({ resendAvailableAt: null }) }));

let authState: AuthState;
const refreshAuth = vi.fn();
vi.mock("@/lib/auth/AuthProvider", () => ({ useAuth: () => ({ state: authState, refresh: refreshAuth }) }));

import { ToastProvider } from "@vitaminvui/ui/v2";
import { CourseAction } from "./CourseAction";
import { CourseCtaProvider } from "./CourseCtaProvider";
import { CourseOutline } from "./CourseOutline";

const student = {
  id: 1,
  name: "A",
  email: "a@example.com",
  phone: null,
  role: "hoc_sinh",
  grade_level: 9,
  is_verified: true,
  parent_consent_status: "not_required" as const,
};

function renderCta(isFree: boolean, paidCheckoutEnabled = true) {
  return render(
    <ToastProvider>
      <CourseCtaProvider course={{ id: 7, slug: "toan-9", isFree, paidCheckoutEnabled }}>
        <CourseAction />
      </CourseCtaProvider>
    </ToastProvider>,
  );
}

function viewerState(state: string, resume: number | null = null) {
  authFetch.mockImplementation((path: string) => {
    if (path.endsWith("/viewer-state")) return Promise.resolve({ viewer_state: state, resume_lesson_id: resume });
    return Promise.reject(new Error(`không mong đợi: ${path}`));
  });
}

// jsdom chưa có <dialog>.showModal.
const showModal = vi.fn();
HTMLDialogElement.prototype.showModal = function showModalStub(this: HTMLDialogElement) {
  showModal();
  this.setAttribute("open", "");
};
HTMLDialogElement.prototype.close = function closeStub(this: HTMLDialogElement) {
  this.removeAttribute("open");
};

describe("CourseCta", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState = { status: "user", user: student };
  });

  it("khách (chưa có phiên): không gọi viewer-state; nút là liên kết /dang-nhap?next=", async () => {
    authState = { status: "guest" };
    renderCta(true);
    const link = screen.getByRole("link", { name: "Đăng ký học miễn phí" });
    expect(link).toHaveAttribute("href", "/dang-nhap?next=%2Fkhoa-hoc%2Ftoan-9");
    expect(authFetch).not.toHaveBeenCalled();
  });

  it("khách xem khóa có phí: thanh toán bật -> Mua khóa học; tạm khoá -> Sắp mở bán, KHÔNG có nút mua", () => {
    authState = { status: "guest" };
    const { unmount } = renderCta(false, true);
    expect(screen.getByRole("link", { name: "Mua khóa học" })).toHaveAttribute("href", "/dang-nhap?next=%2Fkhoa-hoc%2Ftoan-9");
    unmount();
    renderCta(false, false);
    expect(screen.getByText("Sắp mở bán")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Mua khóa học/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Mua khóa học/ })).not.toBeInTheDocument();
  });

  it("owned -> nút Tiếp tục học vô hiệu + 'Sắp mở trang học' (chưa có trang học, FW4), kể cả khi thanh toán tạm khoá", async () => {
    viewerState("owned", 42);
    renderCta(false, false);
    expect(await screen.findByRole("button", { name: "Tiếp tục học" })).toBeDisabled();
    expect(screen.queryByRole("link", { name: "Tiếp tục học" })).not.toBeInTheDocument();
    expect(screen.getByText("Sắp mở trang học.")).toBeInTheDocument();
    expect(authFetch).toHaveBeenCalledWith("/api/v1/courses/toan-9/viewer-state");
  });

  it("pending_approval -> khối trạng thái Đang chờ duyệt (không phải nút)", async () => {
    viewerState("pending_approval");
    renderCta(true);
    expect(await screen.findByRole("status")).toHaveTextContent("Đang chờ duyệt");
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("can_buy + thanh toán bật -> Mua khóa học chưa hoạt động (chờ FW3), có chữ giải thích", async () => {
    viewerState("can_buy");
    renderCta(false, true);
    expect(await screen.findByRole("button", { name: "Mua khóa học" })).toBeDisabled();
    expect(screen.getByText("Giỏ hàng sắp mở.")).toBeInTheDocument();
  });

  it("can_buy / in_cart + thanh toán tạm khoá -> Sắp mở bán, không nút", async () => {
    viewerState("in_cart");
    renderCta(false, false);
    expect(await screen.findByText("Sắp mở bán")).toBeInTheDocument();
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("viewer-state lỗi -> nút Thử lại, bấm gọi lại", async () => {
    authFetch.mockRejectedValueOnce(new ApiError(500, { message: "x" }));
    renderCta(false);
    const retry = await screen.findByRole("button", { name: "Thử lại" });
    viewerState("can_buy");
    await userEvent.click(retry);
    expect(await screen.findByRole("button", { name: "Mua khóa học" })).toBeInTheDocument();
  });

  describe("đăng ký miễn phí", () => {
    function setup(enrollResult: "ok" | ApiError) {
      authFetch.mockImplementation((path: string, opts?: { method?: string }) => {
        if (path.endsWith("/viewer-state")) return Promise.resolve({ viewer_state: "can_register_free", resume_lesson_id: null });
        if (path === "/api/v1/courses/7/free-enrollments" && opts?.method === "POST") {
          return enrollResult === "ok" ? Promise.resolve({ id: 1, status: "pending_approval" }) : Promise.reject(enrollResult);
        }
        return Promise.reject(new Error(path));
      });
      renderCta(true);
    }

    it("201 -> chuyển sang Đang chờ duyệt", async () => {
      setup("ok");
      await userEvent.click(await screen.findByRole("button", { name: "Đăng ký học miễn phí" }));
      expect(await screen.findByText("Đang chờ duyệt")).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: "Đăng ký học miễn phí" })).not.toBeInTheDocument();
    });

    it("403 ACCOUNT_NOT_VERIFIED -> mở hộp thoại 'Xác thực email để tiếp tục' tại chỗ (không rời trang khóa học)", async () => {
      setup(new ApiError(403, { message: "x", code: "ACCOUNT_NOT_VERIFIED" }));
      await userEvent.click(await screen.findByRole("button", { name: "Đăng ký học miễn phí" }));
      expect(await screen.findByText("Xác thực email để tiếp tục")).toBeInTheDocument();
      expect(showModal).toHaveBeenCalled();
      expect(push).not.toHaveBeenCalled();
      expect(screen.getByRole("link", { name: "Tôi đã có mã" })).toHaveAttribute("href", "/xac-thuc-otp");
      expect(screen.getByText(/a\*+@example\.com/)).toBeInTheDocument();
    });

    it("hộp thoại: 'Gửi mã xác nhận' gọi POST otp/send rồi sang /xac-thuc-otp", async () => {
      setup(new ApiError(403, { message: "x", code: "ACCOUNT_NOT_VERIFIED" }));
      await userEvent.click(await screen.findByRole("button", { name: "Đăng ký học miễn phí" }));
      await userEvent.click(await screen.findByRole("button", { name: "Gửi mã xác nhận" }));
      await waitFor(() => expect(push).toHaveBeenCalledWith("/xac-thuc-otp"));
    });

    it("mã cũ 403 PARENT_CONSENT_REQUIRED -> không có hộp thoại chờ phụ huynh, chỉ báo lỗi chung", async () => {
      setup(new ApiError(403, { message: "x", code: "PARENT_CONSENT_REQUIRED" }));
      await userEvent.click(await screen.findByRole("button", { name: "Đăng ký học miễn phí" }));
      expect(await screen.findByRole("alert")).toHaveTextContent("không thể đăng ký");
      expect(screen.queryByText(/chờ phụ huynh/i)).not.toBeInTheDocument();
    });

    it("409 ENROLLMENT_PENDING -> Đang chờ duyệt", async () => {
      setup(new ApiError(409, { message: "x", code: "ENROLLMENT_PENDING" }));
      await userEvent.click(await screen.findByRole("button", { name: "Đăng ký học miễn phí" }));
      expect(await screen.findByText("Đang chờ duyệt")).toBeInTheDocument();
    });

    it("422 COURSE_NOT_FREE -> báo lỗi và tải lại viewer-state", async () => {
      setup(new ApiError(422, { message: "x", code: "COURSE_NOT_FREE" }));
      await userEvent.click(await screen.findByRole("button", { name: "Đăng ký học miễn phí" }));
      expect(await screen.findByRole("alert")).toHaveTextContent("không còn miễn phí");
      await waitFor(() => expect(authFetch.mock.calls.filter((c) => String(c[0]).endsWith("/viewer-state")).length).toBe(2));
    });
  });
});

describe("CourseOutline", () => {
  const chapters = [
    {
      id: 1,
      title: "Chương 1",
      position: 1,
      lessons: [
        { id: 10, title: "Bài xem thử", position: 1, duration_seconds: 125, is_preview: true },
        { id: 11, title: "Bài khoá", position: 2, duration_seconds: null, is_preview: false },
      ],
    },
  ];

  function renderOutline() {
    return render(
      <ToastProvider>
        <CourseCtaProvider course={{ id: 7, slug: "toan-9", isFree: false, paidCheckoutEnabled: false }}>
          <CourseOutline chapters={chapters} courseId={7} />
        </CourseCtaProvider>
      </ToastProvider>,
    );
  }

  beforeEach(() => {
    vi.clearAllMocks();
    authState = { status: "guest" };
  });

  it("hiện tên bài, thời lượng, nhãn Học thử; bài khoá là hàng tĩnh (không bật thông báo khi bấm) kèm câu giải thích phía trên", () => {
    renderOutline();
    expect(screen.getByText("Bài xem thử")).toBeInTheDocument();
    expect(screen.getByText("2:05")).toBeInTheDocument();
    expect(screen.getByText("Học thử")).toBeInTheDocument();
    expect(screen.getByText(/Bài có biểu tượng khoá mở khi bạn sở hữu khóa học/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Bài khoá/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Bài khoá/ })).not.toBeInTheDocument();
  });

  it("outline rỗng -> thông báo đang cập nhật", () => {
    render(
      <ToastProvider>
        <CourseCtaProvider course={{ id: 7, slug: "toan-9", isFree: false, paidCheckoutEnabled: false }}>
          <CourseOutline chapters={[]} courseId={7} />
        </CourseCtaProvider>
      </ToastProvider>,
    );
    expect(screen.getByText("Nội dung khóa học đang được cập nhật")).toBeInTheDocument();
  });

  it("đã sở hữu -> bài là hàng tĩnh (chưa có trang học, không link chết)", async () => {
    authState = { status: "user", user: student };
    authFetch.mockResolvedValue({ viewer_state: "owned", resume_lesson_id: 10 });
    renderOutline();
    await waitFor(() => expect(screen.queryByText(/Bài có biểu tượng khoá/)).not.toBeInTheDocument());
    expect(screen.getByText("Bài khoá")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Bài khoá/ })).not.toBeInTheDocument();
  });
});
