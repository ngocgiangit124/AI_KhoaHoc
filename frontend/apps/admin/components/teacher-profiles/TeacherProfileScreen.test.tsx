import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import * as api from "@/lib/teacher-profiles/api";
import { profileFixture } from "./fixtures";
import { TeacherProfileScreen } from "./TeacherProfileScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
vi.mock("@/lib/teacher-profiles/api");

const err = (status: number, body: { message?: string; code?: string; errors?: Record<string, string[]> }) => new ApiError(status, { message: body.message ?? "x", ...body });
const renderSelf = () =>
  render(
    <ToastProvider>
      <TeacherProfileScreen target={{ kind: "me" }} mode="self" />
    </ToastProvider>,
  );
const renderAdmin = () =>
  render(
    <ToastProvider>
      <TeacherProfileScreen target={{ kind: "user", id: 12 }} mode="admin" />
    </ToastProvider>,
  );

beforeEach(() => {
  vi.resetAllMocks();
});

describe("TeacherProfileScreen — Hồ sơ của tôi", () => {
  it("hiện câu đồng ý hiện hành, trạng thái 'Chưa hiển thị' kèm lý do tiếng Việt, đếm ký tự", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(profileFixture());
    renderSelf();
    expect(await screen.findByRole("heading", { name: "Hồ sơ của tôi", level: 1 })).toBeInTheDocument();
    expect(screen.getByLabelText(/Tôi đồng ý công khai ảnh/)).not.toBeChecked();
    expect(screen.getByText("Chưa hiển thị")).toBeInTheDocument();
    expect(screen.getByTestId("not-shown-reasons")).toHaveTextContent("Chưa hiện: chưa bật hiển thị, chưa đồng ý công khai, chưa có ảnh");
    expect(screen.getByText("19/120")).toBeInTheDocument();
  });

  it("PATCH chỉ gửi trường đã đổi (AC21) và khoá nút khi đang gửi", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(profileFixture());
    let resolve!: (p: ReturnType<typeof profileFixture>) => void;
    vi.mocked(api.patchProfile).mockReturnValue(new Promise((r) => (resolve = r)));
    renderSelf();
    const bio = await screen.findByLabelText("Giới thiệu bản thân");
    await userEvent.clear(bio);
    await userEvent.type(bio, "Giới thiệu mới");
    await userEvent.click(screen.getByRole("button", { name: "Lưu hồ sơ" }));
    expect(api.patchProfile).toHaveBeenCalledTimes(1);
    expect(api.patchProfile).toHaveBeenCalledWith({ kind: "me" }, { bio: "Giới thiệu mới" });
    expect(screen.getByRole("button", { name: /Đang lưu/ })).toBeDisabled();
    resolve(profileFixture({ bio: "Giới thiệu mới", headline: "Do người khác sửa" }));
    // Trường không sửa lấy giá trị mới nhất từ server (không ghi đè bản của người kia).
    await waitFor(() => expect(screen.getByLabelText("Chuyên môn (một dòng)")).toHaveValue("Do người khác sửa"));
    expect(api.giveConsent).not.toHaveBeenCalled();
  });

  it("422: lỗi dưới đúng ô và focus vào ô lỗi đầu tiên; giữ nguyên dữ liệu đã nhập", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(profileFixture());
    vi.mocked(api.patchProfile).mockRejectedValue(err(422, { errors: { bio: ["Giới thiệu chứa ký tự không hợp lệ."] } }));
    renderSelf();
    const bio = await screen.findByLabelText("Giới thiệu bản thân");
    await userEvent.type(bio, " thêm");
    await userEvent.click(screen.getByRole("button", { name: "Lưu hồ sơ" }));
    expect(await screen.findByText("Giới thiệu chứa ký tự không hợp lệ.")).toBeInTheDocument();
    await waitFor(() => expect(bio).toHaveFocus());
    expect(bio).toHaveAttribute("aria-invalid", "true");
    expect(bio).toHaveValue("10 năm luyện thi vào 10.\nHọc sinh đạt giải cấp tỉnh. thêm");
  });

  it("kiểm client: quá 600 ký tự không gọi API, báo lỗi dưới ô", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(profileFixture({ bio: null }));
    renderSelf();
    const bio = await screen.findByLabelText("Giới thiệu bản thân");
    await userEvent.click(bio);
    await userEvent.paste("a".repeat(601));
    await userEvent.click(screen.getByRole("button", { name: "Lưu hồ sơ" }));
    expect(await screen.findByText("Giới thiệu tối đa 600 ký tự.")).toBeInTheDocument();
    expect(api.patchProfile).not.toHaveBeenCalled();
  });

  it("tick đồng ý gửi đúng version hiện hành", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(profileFixture());
    vi.mocked(api.giveConsent).mockResolvedValue(
      profileFixture({ consent: { ...profileFixture().consent, given: true, given_at: "2026-10-07T09:00:00+07:00", version: "2026-10" } }),
    );
    renderSelf();
    await userEvent.click(await screen.findByLabelText(/Tôi đồng ý công khai ảnh/));
    await userEvent.click(screen.getByRole("button", { name: "Lưu hồ sơ" }));
    await waitFor(() => expect(api.giveConsent).toHaveBeenCalledWith("2026-10"));
    expect(api.patchProfile).not.toHaveBeenCalled();
    expect(await screen.findByTestId("consent-given")).toBeInTheDocument();
  });

  it("409 CONSENT_VERSION_CHANGED: tải lại hồ sơ, hiện câu mới, bỏ tick", async () => {
    const fresh = profileFixture({ consent: { ...profileFixture().consent, current_version: "2026-11", current_text: "Câu đồng ý mới nhất" } });
    vi.mocked(api.getProfile).mockResolvedValueOnce(profileFixture()).mockResolvedValueOnce(fresh);
    vi.mocked(api.giveConsent).mockRejectedValue(err(409, { code: "CONSENT_VERSION_CHANGED" }));
    renderSelf();
    await userEvent.click(await screen.findByLabelText(/Tôi đồng ý công khai ảnh/));
    await userEvent.click(screen.getByRole("button", { name: "Lưu hồ sơ" }));
    expect(await screen.findByText("Câu đồng ý đã được cập nhật")).toBeInTheDocument();
    expect(screen.getByLabelText(/Câu đồng ý mới nhất/)).not.toBeChecked();
  });

  it("rút đồng ý có hộp xác nhận rồi mới gọi API", async () => {
    const given = profileFixture({ consent: { ...profileFixture().consent, given: true, given_at: "2026-10-07T09:00:00+07:00", version: "2026-10" } });
    vi.mocked(api.getProfile).mockResolvedValue(given);
    vi.mocked(api.withdrawConsent).mockResolvedValue(profileFixture({ consent: { ...given.consent, given: false, given_at: null, withdrawn_at: "2026-10-07T10:00:00+07:00" } }));
    renderSelf();
    await userEvent.click(await screen.findByRole("button", { name: "Rút đồng ý" }));
    expect(api.withdrawConsent).not.toHaveBeenCalled();
    await userEvent.click(await screen.findByRole("button", { name: "Rút đồng ý", hidden: false, description: undefined }).catch(() => screen.getAllByRole("button", { name: "Rút đồng ý" })[1]!));
    await waitFor(() => expect(api.withdrawConsent).toHaveBeenCalledTimes(1));
  });

  it("hiện 'Chỉnh sửa gần nhất bởi' khi người khác sửa (is_self=false), không hiện khi tự sửa", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(profileFixture({ last_edited_by: { id: 1, name: "Trần Quản Trị", is_self: false }, last_edited_at: "2026-10-06T10:00:00+07:00" }));
    const { unmount } = renderSelf();
    expect(await screen.findByText(/Chỉnh sửa gần nhất bởi Trần Quản Trị/)).toBeInTheDocument();
    unmount();
    vi.mocked(api.getProfile).mockResolvedValue(profileFixture({ last_edited_by: { id: 12, name: "Chính tôi", is_self: true }, last_edited_at: "2026-10-06T10:00:00+07:00" }));
    renderSelf();
    await screen.findByRole("heading", { name: "Hồ sơ của tôi" });
    expect(screen.queryByText(/Chỉnh sửa gần nhất/)).toBeNull();
  });

  it("403 → màn không có quyền", async () => {
    vi.mocked(api.getProfile).mockRejectedValue(err(403, { code: "FORBIDDEN" }));
    renderSelf();
    expect(await screen.findByTestId("forbidden-view")).toBeInTheDocument();
  });
});

describe("TeacherProfileScreen — Admin/QLT sửa hộ", () => {
  it("ô đồng ý chỉ đọc kèm chữ giải thích; không có nút rút/đồng ý; lưu nội dung gọi PATCH theo id", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(profileFixture({ abilities: { edit_content: true, manage_homepage: true } }));
    vi.mocked(api.patchProfile).mockResolvedValue(profileFixture({ headline: "Mới" }));
    renderAdmin();
    const box = await screen.findByLabelText(/Tôi đồng ý công khai ảnh/);
    expect(box).toBeDisabled();
    expect(screen.getByTestId("consent-readonly-note")).toHaveTextContent("Chỉ giáo viên được đồng ý công khai");
    expect(screen.queryByRole("button", { name: "Rút đồng ý" })).toBeNull();
    const headline = screen.getByLabelText("Chuyên môn (một dòng)");
    await userEvent.clear(headline);
    await userEvent.type(headline, "Mới");
    await userEvent.click(screen.getByRole("button", { name: "Lưu hồ sơ" }));
    await waitFor(() => expect(api.patchProfile).toHaveBeenCalledWith({ kind: "user", id: 12 }, { headline: "Mới" }));
    expect(api.giveConsent).not.toHaveBeenCalled();
  });

  it("người không còn là giáo viên: ô nội dung bị khoá kèm giải thích", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(
      profileFixture({ user: { id: 12, name: "Cũ", role: "quan_ly_trang", status: "active" }, abilities: { edit_content: false, manage_homepage: true } }),
    );
    renderAdmin();
    expect(await screen.findByText("Không sửa được nội dung")).toBeInTheDocument();
    expect(screen.getByLabelText("Giới thiệu bản thân")).toBeDisabled();
  });

  it("404 → màn không tìm thấy với đường về danh sách", async () => {
    vi.mocked(api.getProfile).mockRejectedValue(err(404, { code: "NOT_FOUND" }));
    renderAdmin();
    expect(await screen.findByTestId("profile-not-found")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Về danh sách giáo viên" })).toHaveAttribute("href", "/quan-tri/giao-vien");
  });
});
