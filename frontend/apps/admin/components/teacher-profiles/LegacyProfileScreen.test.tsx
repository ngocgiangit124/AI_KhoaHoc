import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import { ToastProvider } from "@vitaminvui/ui/v2";
import * as api from "@/lib/teacher-profiles/api";
import { LegacyProfileProvider, hasLegacyData, useLegacyProfile } from "@/lib/teacher-profiles/legacy";
import { profileFixture } from "./fixtures";
import { LegacyProfileScreen } from "./LegacyProfileScreen";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
vi.mock("@/lib/teacher-profiles/api");

const legacy = (over = {}) =>
  profileFixture({
    user: { id: 9, name: "Cô Cũ", role: "quan_ly_trang", status: "active" },
    avatar_url: "https://static.test/a.webp",
    abilities: { edit_content: false, consent: false, manage_homepage: false },
    homepage_status: { visible: false, reasons: ["not_teacher", "not_enabled"] },
    consent: { given: true, given_at: "2026-10-06T09:15:00+07:00", version: "2026-10", withdrawn_at: null, current_version: "2026-10", current_text: "x" },
    ...over,
  });
const renderScreen = () =>
  render(
    <ToastProvider>
      <LegacyProfileProvider role="quan_ly_trang" userId={9}>
        <LegacyProfileScreen />
      </LegacyProfileProvider>
    </ToastProvider>,
  );

beforeEach(() => vi.resetAllMocks());

describe("hasLegacyData", () => {
  it("true khi đồng ý đang bật hoặc còn ảnh; false khi cả hai đều không", () => {
    expect(hasLegacyData(legacy())).toBe(true);
    expect(hasLegacyData(legacy({ avatar_url: null }))).toBe(true);
    expect(hasLegacyData(legacy({ consent: { ...legacy().consent, given: false } }))).toBe(true);
    expect(hasLegacyData(legacy({ avatar_url: null, consent: { ...legacy().consent, given: false } }))).toBe(false);
    expect(hasLegacyData(null)).toBe(false);
  });
});

describe("LegacyProfileScreen", () => {
  it("chỉ xem + hai nút gỡ, không có ô sửa hay đồng ý lại", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(legacy());
    renderScreen();
    expect(await screen.findByRole("heading", { name: "Hồ sơ giáo viên cũ", level: 1 })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Rút đồng ý" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Xoá ảnh" })).toBeInTheDocument();
    expect(screen.queryByRole("textbox")).toBeNull();
    expect(screen.queryByRole("checkbox")).toBeNull();
    expect(screen.queryByRole("button", { name: "Lưu hồ sơ" })).toBeNull();
  });

  it("rút đồng ý phải qua xác nhận; huỷ thì không gọi API", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(legacy());
    vi.mocked(api.withdrawConsent).mockResolvedValue(legacy({ consent: { ...legacy().consent, given: false, withdrawn_at: "2026-10-07T10:00:00+07:00" } }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Rút đồng ý" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(screen.getByRole("button", { name: /Huỷ|Hủy/ }));
    expect(api.withdrawConsent).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole("button", { name: "Rút đồng ý" }));
    await userEvent.click(screen.getAllByRole("button", { name: "Rút đồng ý" }).at(-1)!);
    await waitFor(() => expect(api.withdrawConsent).toHaveBeenCalledTimes(1));
    expect(dialog).toBeDefined();
    expect(await screen.findByTestId("legacy-consent-off")).toBeInTheDocument();
  });

  it("xoá ảnh qua xác nhận; xong cả hai gỡ → báo không còn dữ liệu", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(legacy({ consent: { ...legacy().consent, given: false } }));
    vi.mocked(api.deleteAvatar).mockResolvedValue(legacy({ avatar_url: null, consent: { ...legacy().consent, given: false } }));
    renderScreen();
    await userEvent.click(await screen.findByRole("button", { name: "Xoá ảnh" }));
    expect(api.deleteAvatar).not.toHaveBeenCalled();
    await userEvent.click(screen.getAllByRole("button", { name: "Xoá ảnh" }).at(-1)!);
    await waitFor(() => expect(api.deleteAvatar).toHaveBeenCalledTimes(1));
    expect(await screen.findByTestId("legacy-empty")).toBeInTheDocument();
  });

  it("403 → màn không có quyền", async () => {
    vi.mocked(api.getProfile).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    renderScreen();
    expect(await screen.findByText(/không có quyền/i)).toBeInTheDocument();
  });
});

describe("LegacyProfileProvider", () => {
  function Probe() {
    return <span data-testid="has">{String(useLegacyProfile().hasData)}</span>;
  }
  it("giáo viên không gọi API; người khác gọi đúng 1 lần; 403 → ẩn", async () => {
    vi.mocked(api.getProfile).mockResolvedValue(legacy());
    const { unmount } = render(<LegacyProfileProvider role="giao_vien" userId={1}><Probe /></LegacyProfileProvider>);
    expect(api.getProfile).not.toHaveBeenCalled();
    unmount();
    const r = render(<LegacyProfileProvider role="quan_ly_trang" userId={9}><Probe /></LegacyProfileProvider>);
    await waitFor(() => expect(screen.getByTestId("has")).toHaveTextContent("true"));
    r.rerender(<LegacyProfileProvider role="quan_ly_trang" userId={9}><Probe /></LegacyProfileProvider>);
    expect(api.getProfile).toHaveBeenCalledTimes(1);
    r.unmount();
    vi.mocked(api.getProfile).mockRejectedValue(new ApiError(403, { message: "x", code: "FORBIDDEN" }));
    render(<LegacyProfileProvider role="admin" userId={2}><Probe /></LegacyProfileProvider>);
    await waitFor(() => expect(api.getProfile).toHaveBeenCalledTimes(2));
    expect(screen.getByTestId("has")).toHaveTextContent("false");
  });
});
