import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import { useSession, type SessionState } from "@/lib/auth/SessionProvider";
import type { StaffUser } from "@/lib/auth/types";
import * as api from "@/lib/enrollment-requests/api";
import type { EnrollmentRequest, EnrollmentRequestPage } from "@/lib/enrollment-requests/types";
import { EnrollmentRequestsScreen } from "./EnrollmentRequestsScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
const replace = vi.fn();
let search = "";
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn() }),
  usePathname: () => "/quan-tri/duyet-dang-ky",
  useSearchParams: () => new URLSearchParams(search),
}));
vi.mock("@/lib/auth/SessionProvider", () => ({ useSession: vi.fn() }));
vi.mock("@/lib/enrollment-requests/api");

const req = (id: number, name: string, over: Partial<EnrollmentRequest> = {}): EnrollmentRequest => ({
  id,
  status: "pending_approval",
  requested_at: "2026-10-01T03:00:00+00:00",
  approved_at: null,
  rejection_reason: null,
  course: { id: 5, title: "Toán 9 miễn phí", slug: "toan-9" },
  student: { id: id + 100, name, grade_level: 9, email_masked: "n***@example.com", phone_masked: null },
  ...over,
});
const pageOf = (data: EnrollmentRequest[], last = 1): EnrollmentRequestPage => ({
  data,
  meta: { current_page: 1, per_page: 25, total: data.length, last_page: last },
  links: { next: null, prev: null },
});

function setUser(role: StaffUser["role"]) {
  const user: StaffUser = { id: 1, name: "N", email: null, role, permissions: null, mustChangePassword: false, session: null };
  vi.mocked(useSession).mockReturnValue({ state: { kind: "staff", user } as SessionState, refresh: vi.fn() });
}
const renderScreen = () =>
  render(
    <ToastProvider>
      <EnrollmentRequestsScreen />
    </ToastProvider>,
  );

beforeEach(() => {
  vi.resetAllMocks();
  search = "";
  setUser("quan_ly_trang");
  vi.mocked(api.listFreeCourses).mockResolvedValue([]);
});

describe("EnrollmentRequestsScreen", () => {
  it("mặc định tải 'Chờ duyệt'; chỉ hiện dữ liệu API trả (email đã che), có đếm ở tab hiện tại", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    renderScreen();
    expect(await screen.findByText("Lan Anh")).toBeInTheDocument();
    expect(vi.mocked(api.listRequests).mock.calls[0]?.[0]).toMatchObject({ status: "pending_approval", courseId: null, page: 1 });
    expect(screen.getByText(/n\*\*\*@example\.com/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Chờ duyệt/ })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: /Đã duyệt/ })).toHaveAttribute("href", "/quan-tri/duyet-dang-ky?status=active");
  });

  it("duyệt: gọi approve một lần dù bấm kép, rồi tải lại", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    let done: (v: EnrollmentRequest) => void = () => {};
    vi.mocked(api.approveRequest).mockReturnValue(new Promise((r) => (done = r)));
    renderScreen();
    const btn = await screen.findByRole("button", { name: "Duyệt yêu cầu của Lan Anh" });
    await userEvent.dblClick(btn);
    expect(api.approveRequest).toHaveBeenCalledTimes(1);
    done(req(1, "Lan Anh", { status: "active" }));
    await waitFor(() => expect(api.listRequests).toHaveBeenCalledTimes(2));
  });

  it("từ chối: hộp thoại lý do, gửi lý do đã cắt khoảng trắng", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    vi.mocked(api.rejectRequest).mockResolvedValue(req(1, "Lan Anh", { status: "rejected" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Từ chối yêu cầu của Lan Anh" }));
    await userEvent.type(screen.getByLabelText(/Lý do/), "Chưa đủ điều kiện");
    await userEvent.click(screen.getByRole("button", { name: "Xác nhận từ chối" }));
    await waitFor(() => expect(api.rejectRequest).toHaveBeenCalledWith(1, "Chưa đủ điều kiện"));
    await waitFor(() => expect(api.listRequests).toHaveBeenCalledTimes(2));
  });

  it("422 lý do hiện dưới ô, hộp thoại vẫn mở", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    vi.mocked(api.rejectRequest).mockRejectedValue(new ApiError(422, { message: "x", code: "VALIDATION_ERROR", errors: { reason: ["Lý do không được chứa HTML."] } }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Từ chối yêu cầu của Lan Anh" }));
    await userEvent.type(screen.getByLabelText(/Lý do/), "abc");
    await userEvent.click(screen.getByRole("button", { name: "Xác nhận từ chối" }));
    expect(await screen.findByText("Lý do không được chứa HTML.")).toBeInTheDocument();
    expect(screen.getByLabelText(/Lý do/)).toHaveValue("abc");
    expect(api.listRequests).toHaveBeenCalledTimes(1);
  });

  it("409 ALREADY_PROCESSED: báo đã có người xử lý và tải lại", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    vi.mocked(api.approveRequest).mockRejectedValue(new ApiError(409, { message: "x", code: "ALREADY_PROCESSED" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Duyệt yêu cầu của Lan Anh" }));
    expect(await screen.findByText(/người khác xử lý/)).toBeInTheDocument();
    await waitFor(() => expect(api.listRequests).toHaveBeenCalledTimes(2));
  });

  it("422 COURSE_NOT_FREE: thông báo ngay tại dòng, không tải lại", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    vi.mocked(api.approveRequest).mockRejectedValue(new ApiError(422, { message: "x", code: "COURSE_NOT_FREE" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Duyệt yêu cầu của Lan Anh" }));
    const note = await screen.findByRole("alert");
    expect(within(note).getByText(/chuyển sang có phí/)).toBeInTheDocument();
    expect(api.listRequests).toHaveBeenCalledTimes(1);
  });

  it("tab Đã từ chối: hiện lý do, không có nút duyệt/từ chối", async () => {
    search = "status=rejected&course_id=5";
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(2, "Minh", { status: "rejected", rejection_reason: "Thiếu thông tin" })]));
    renderScreen();
    expect(await screen.findByText("Lý do: Thiếu thông tin")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Duyệt yêu cầu/ })).toBeNull();
    expect(vi.mocked(api.listRequests).mock.calls[0]?.[0]).toMatchObject({ status: "rejected", courseId: 5 });
  });

  it("đổi khóa trong bộ lọc ghi lên URL và về trang 1", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    vi.mocked(api.listFreeCourses).mockResolvedValue([{ id: 5, title: "Toán 9 miễn phí" } as never]);
    renderScreen();
    await screen.findByText("Lan Anh");
    await userEvent.selectOptions(await screen.findByLabelText("Khóa học"), "5");
    expect(replace).toHaveBeenCalledWith("/quan-tri/duyet-dang-ky?course_id=5", { scroll: false });
  });

  it("rỗng, 403 và lỗi tải", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([]));
    const a = renderScreen();
    expect(await screen.findByText("Hiện không có yêu cầu nào đang chờ duyệt")).toBeInTheDocument();
    a.unmount();
    vi.mocked(api.listRequests).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    const b = renderScreen();
    expect(await screen.findByTestId("forbidden-view")).toBeInTheDocument();
    b.unmount();
    vi.mocked(api.listRequests).mockRejectedValue(new ApiError(500, { message: "Lỗi máy chủ" }));
    renderScreen();
    expect(await screen.findByText("Không tải được danh sách yêu cầu")).toBeInTheDocument();
  });

  it("giáo viên: gọi danh sách khóa với isStaff=false", async () => {
    setUser("giao_vien");
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([]));
    renderScreen();
    await screen.findByText("Hiện không có yêu cầu nào đang chờ duyệt");
    expect(vi.mocked(api.listFreeCourses).mock.calls[0]?.[0]).toBe(false);
  });

  it("đổi tab trong lúc đang tải: dòng cũ không có nút Duyệt/Từ chối, nút bị khoá khi đang tải", async () => {
    search = "status=active";
    vi.mocked(api.listRequests).mockResolvedValueOnce(pageOf([req(2, "Minh", { status: "active", approved_at: "2026-10-02T03:00:00+00:00" })]));
    const view = renderScreen();
    await screen.findByText("Minh");
    // Chuyển sang Chờ duyệt: lần tải mới treo, màn vẫn giữ dòng "Đã duyệt" cũ.
    search = "";
    vi.mocked(api.listRequests).mockReturnValueOnce(new Promise(() => {}));
    view.rerender(
      <ToastProvider>
        <EnrollmentRequestsScreen />
      </ToastProvider>,
    );
    await waitFor(() => expect(api.listRequests).toHaveBeenCalledTimes(2));
    expect(screen.queryByRole("button", { name: /Duyệt yêu cầu|Từ chối yêu cầu/ })).toBeNull();
  });

  it("dòng chờ duyệt đang tải lại thì nút bị khoá", async () => {
    vi.mocked(api.listRequests).mockResolvedValueOnce(pageOf([req(1, "Lan Anh")]));
    const view = renderScreen();
    await screen.findByText("Lan Anh");
    vi.mocked(api.listRequests).mockReturnValueOnce(new Promise(() => {}));
    search = "per_page=50";
    view.rerender(
      <ToastProvider>
        <EnrollmentRequestsScreen />
      </ToastProvider>,
    );
    await waitFor(() => expect(screen.getByRole("button", { name: "Duyệt yêu cầu của Lan Anh" })).toBeDisabled());
    expect(screen.getByRole("button", { name: "Từ chối yêu cầu của Lan Anh" })).toBeDisabled();
  });

  it("403 khi duyệt: toast cảnh báo và tải lại", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    vi.mocked(api.approveRequest).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Duyệt yêu cầu của Lan Anh" }));
    expect(await screen.findByText(/không có quyền xử lý yêu cầu này/)).toBeInTheDocument();
    await waitFor(() => expect(api.listRequests).toHaveBeenCalledTimes(2));
  });

  it("404 khi từ chối: đóng hộp thoại, báo và tải lại", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    vi.mocked(api.rejectRequest).mockRejectedValue(new ApiError(404, { message: "x" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Từ chối yêu cầu của Lan Anh" }));
    await userEvent.click(screen.getByRole("button", { name: "Xác nhận từ chối" }));
    expect(await screen.findByText(/không còn tồn tại/)).toBeInTheDocument();
    await waitFor(() => expect(api.listRequests).toHaveBeenCalledTimes(2));
  });

  it("409 COURSE_UNAVAILABLE hiện ở dòng; 429 hiện ở dòng, đều không tải lại", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    vi.mocked(api.approveRequest).mockRejectedValueOnce(new ApiError(409, { message: "x", code: "COURSE_UNAVAILABLE" }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Duyệt yêu cầu của Lan Anh" }));
    expect(await screen.findByText(/đã bị xoá/)).toBeInTheDocument();
    vi.mocked(api.approveRequest).mockRejectedValueOnce(new ApiError(429, { message: "x" }));
    await userEvent.click(screen.getByRole("button", { name: "Duyệt yêu cầu của Lan Anh" }));
    expect(await screen.findByText(/thao tác quá nhanh/)).toBeInTheDocument();
    expect(api.listRequests).toHaveBeenCalledTimes(1);
  });

  it("ô lý do có gợi ý văn bản thuần", async () => {
    vi.mocked(api.listRequests).mockResolvedValue(pageOf([req(1, "Lan Anh")]));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Từ chối yêu cầu của Lan Anh" }));
    expect(screen.getByText(/Chỉ nhập văn bản thuần/)).toBeInTheDocument();
  });
});
