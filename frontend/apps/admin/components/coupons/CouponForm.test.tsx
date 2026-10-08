import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import * as api from "@/lib/coupons/api";
import * as options from "@/lib/coupons/options";
import type { Coupon } from "@/lib/coupons/types";
import { CouponForm } from "./CouponForm";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
vi.mock("@/lib/coupons/api");
vi.mock("@/lib/coupons/options");

const saved = (over: Partial<Coupon> = {}): Coupon => ({
  id: 9,
  code: "TOAN2026",
  name: null,
  discount_type: "percent",
  discount_value: 20,
  max_uses: null,
  max_uses_per_user: 1,
  used_count: 0,
  valid_from: "2026-10-01T00:00:00+07:00",
  valid_until: null,
  status: "active",
  state: "active",
  is_restricted: false,
  created_at: "2026-10-01T00:00:00+07:00",
  ...over,
});

beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(options.listAllSubjects).mockResolvedValue([
    { id: 1, name: "Đại số", hidden: false },
    { id: 2, name: "Chuyên đề ẩn", hidden: true },
  ]);
  vi.mocked(options.searchCourses).mockResolvedValue([
    { id: 11, title: "Hình học 9", status: "published" },
    { id: 12, title: "Đại số 8", status: "draft" },
  ]);
  vi.mocked(options.cheapestPublishedPrice).mockResolvedValue(300000);
});

describe("CouponForm (tạo)", () => {
  it("gửi rỗng: lỗi dưới từng ô, hộp tóm tắt có liên kết, không gọi API", async () => {
    const user = userEvent.setup();
    render(<CouponForm mode="create" onSaved={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: "Tạo mã giảm giá" }));
    expect(await screen.findByText(/Chưa lưu được — còn 2 chỗ/)).toBeInTheDocument();
    expect(screen.getAllByText("Vui lòng nhập mã giảm giá.").length).toBeGreaterThan(0);
    expect(screen.getByLabelText(/Mã giảm giá/)).toHaveAttribute("aria-invalid", "true");
    expect(api.createCoupon).not.toHaveBeenCalled();
    await user.click(screen.getByRole("link", { name: "Giá trị giảm" }));
    expect(screen.getByLabelText(/Giảm \(%\)/)).toHaveFocus();
  });

  it("AC7: 101% báo lỗi, không lưu", async () => {
    const user = userEvent.setup();
    render(<CouponForm mode="create" onSaved={vi.fn()} />);
    await user.type(screen.getByLabelText(/Mã giảm giá/), "toan2026");
    expect(screen.getByLabelText(/Mã giảm giá/)).toHaveValue("TOAN2026");
    await user.type(screen.getByLabelText(/Giảm \(%\)/), "101");
    await user.click(screen.getByRole("button", { name: "Tạo mã giảm giá" }));
    expect((await screen.findAllByText("Giá trị giảm không được vượt quá 100%.")).length).toBeGreaterThan(0);
    expect(api.createCoupon).not.toHaveBeenCalled();
  });

  it("gửi đúng body, bấm kép chỉ gọi một lần, báo onSaved", async () => {
    const user = userEvent.setup();
    let done: (c: Coupon) => void = () => {};
    vi.mocked(api.createCoupon).mockReturnValue(new Promise((r) => (done = r)));
    const onSaved = vi.fn();
    render(<CouponForm mode="create" onSaved={onSaved} />);
    await user.type(screen.getByLabelText(/Mã giảm giá/), "toan2026");
    await user.type(screen.getByLabelText(/Giảm \(%\)/), "20");
    await user.type(screen.getByLabelText(/Tổng số lượt/), "50");
    const submit = screen.getByRole("button", { name: "Tạo mã giảm giá" });
    await user.dblClick(submit);
    expect(api.createCoupon).toHaveBeenCalledTimes(1);
    expect(api.createCoupon).toHaveBeenCalledWith({
      code: "TOAN2026",
      name: null,
      discount_type: "percent",
      discount_value: 20,
      max_uses: 50,
      valid_until: null,
      subject_ids: [],
      course_ids: [],
    });
    expect(screen.getByRole("button", { name: /Đang lưu/ })).toBeDisabled();
    done(saved());
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ id: 9 })));
  });

  it("422 mã trùng hiện dưới ô mã + tóm tắt; giữ nguyên dữ liệu đã nhập", async () => {
    const user = userEvent.setup();
    vi.mocked(api.createCoupon).mockRejectedValue(new ApiError(422, { message: "x", code: "VALIDATION_ERROR", errors: { code: ["Mã giảm giá đã tồn tại."] } }));
    render(<CouponForm mode="create" onSaved={vi.fn()} />);
    await user.type(screen.getByLabelText(/Mã giảm giá/), "toan2026");
    await user.type(screen.getByLabelText(/Giảm \(%\)/), "20");
    await user.click(screen.getByRole("button", { name: "Tạo mã giảm giá" }));
    expect((await screen.findAllByText(/Mã giảm giá đã tồn tại\./)).length).toBe(2);
    expect(screen.getByLabelText(/Mã giảm giá/)).toHaveValue("TOAN2026");
    expect(screen.getByLabelText(/Giảm \(%\)/)).toHaveValue("20");
    expect(screen.getByRole("button", { name: "Tạo mã giảm giá" })).toBeEnabled();
  });

  it("S18: giảm 100% đòi giới hạn lượt + ngày hết hạn trước khi gọi API", async () => {
    const user = userEvent.setup();
    render(<CouponForm mode="create" onSaved={vi.fn()} />);
    await user.type(screen.getByLabelText(/Mã giảm giá/), "FREE100");
    await user.type(screen.getByLabelText(/Giảm \(%\)/), "100");
    expect(screen.getByText("Mã giảm hết giá trị đơn")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Tạo mã giảm giá" }));
    expect((await screen.findAllByText(/bắt buộc có giới hạn lượt dùng và ngày hết hạn/)).length).toBeGreaterThan(2);
    expect(api.createCoupon).not.toHaveBeenCalled();
  });

  it("giảm cố định ≥ giá khóa rẻ nhất cũng bị đòi giới hạn (giá lấy từ danh sách khóa đang bán)", async () => {
    const user = userEvent.setup();
    render(<CouponForm mode="create" onSaved={vi.fn()} />);
    await user.click(screen.getByRole("radio", { name: "Số tiền cố định (đ)" }));
    expect(options.cheapestPublishedPrice).not.toHaveBeenCalled(); // chưa có số thì chưa quét
    await user.type(screen.getByLabelText(/Mã giảm giá/), "GIAM300");
    await user.type(screen.getByLabelText(/Giảm \(đồng\)/), "300000");
    await waitFor(() => expect(options.cheapestPublishedPrice).toHaveBeenCalledTimes(1));
    expect(await screen.findByText("Mã giảm hết giá trị đơn")).toBeInTheDocument();
  });

  it("phạm vi theo khóa: tìm, chọn, bỏ chọn; gửi course_ids; chưa chọn thì báo lỗi", async () => {
    const user = userEvent.setup();
    vi.mocked(api.createCoupon).mockResolvedValue(saved({ is_restricted: true }));
    render(<CouponForm mode="create" onSaved={vi.fn()} />);
    await user.type(screen.getByLabelText(/Mã giảm giá/), "HINHHOC9");
    await user.type(screen.getByLabelText(/Giảm \(%\)/), "10");
    await user.click(screen.getByRole("radio", { name: "Theo khóa học cụ thể" }));
    await user.click(screen.getByRole("button", { name: "Tạo mã giảm giá" }));
    expect((await screen.findAllByText(/Chọn ít nhất 1 khóa học/)).length).toBeGreaterThan(0);
    await user.click(await screen.findByRole("checkbox", { name: "Hình học 9" }));
    expect(screen.getByRole("button", { name: "Bỏ khóa Hình học 9" })).toBeInTheDocument();
    expect(screen.queryByText(/Chọn ít nhất 1 khóa học/)).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Tạo mã giảm giá" }));
    await waitFor(() => expect(api.createCoupon).toHaveBeenCalledWith(expect.objectContaining({ course_ids: [11], subject_ids: [] })));
  });

  it("phạm vi theo chuyên đề: chuyên đề ẩn vẫn chọn được và có nhãn", async () => {
    const user = userEvent.setup();
    vi.mocked(api.createCoupon).mockResolvedValue(saved());
    render(<CouponForm mode="create" onSaved={vi.fn()} />);
    await user.type(screen.getByLabelText(/Mã giảm giá/), "CHUYENDE");
    await user.type(screen.getByLabelText(/Giảm \(%\)/), "10");
    await user.click(screen.getByRole("radio", { name: "Theo chuyên đề cụ thể" }));
    expect(await screen.findByText("(đang ẩn)")).toBeInTheDocument();
    await user.click(screen.getByRole("checkbox", { name: /Chuyên đề ẩn/ }));
    await user.click(screen.getByRole("button", { name: "Tạo mã giảm giá" }));
    await waitFor(() => expect(api.createCoupon).toHaveBeenCalledWith(expect.objectContaining({ subject_ids: [2], course_ids: [] })));
  });

  it("báo cáo dirty khi nhập; lỗi 429 và lỗi mạng hiện ở hộp tóm tắt", async () => {
    const user = userEvent.setup();
    const onDirty = vi.fn();
    vi.mocked(api.createCoupon).mockRejectedValue(new ApiError(429, { message: "x" }, 12));
    render(<CouponForm mode="create" onSaved={vi.fn()} onDirtyChange={onDirty} />);
    await user.type(screen.getByLabelText(/Mã giảm giá/), "ABCD");
    await waitFor(() => expect(onDirty).toHaveBeenLastCalledWith(true));
    await user.type(screen.getByLabelText(/Giảm \(%\)/), "5");
    await user.click(screen.getByRole("button", { name: "Tạo mã giảm giá" }));
    expect(await screen.findByText(/quá nhanh.*12 giây/)).toBeInTheDocument();
  });
});

describe("CouponForm (sửa)", () => {
  const used = saved({ used_count: 5, max_uses: 100, name: "Tên cũ" });

  it("mã đã dùng: mã/loại/giá trị chỉ đọc, kèm giải thích; sửa tên gửi lại đúng giá trị cũ", async () => {
    const user = userEvent.setup();
    vi.mocked(api.updateCoupon).mockResolvedValue(used);
    render(<CouponForm mode="edit" base={used} usedCount={5} onSaved={vi.fn()} />);
    expect(screen.getByLabelText(/Mã giảm giá/)).toBeDisabled();
    expect(screen.getByLabelText(/Giảm \(%\)/)).toBeDisabled();
    expect(screen.getByRole("radio", { name: "Phần trăm (%)" })).toBeDisabled();
    expect(screen.getByText(/không đổi được mã, loại hoặc giá trị giảm/)).toBeInTheDocument();
    await user.clear(screen.getByLabelText(/Tên gợi nhớ/));
    await user.type(screen.getByLabelText(/Tên gợi nhớ/), "Tên mới");
    await user.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    await waitFor(() => expect(api.updateCoupon).toHaveBeenCalledTimes(1));
    expect(api.updateCoupon).toHaveBeenCalledWith(
      9,
      expect.objectContaining({ code: "TOAN2026", discount_type: "percent", discount_value: 20, name: "Tên mới", max_uses: 100, valid_from: null, valid_until: null }),
    );
  });

  it("không có thay đổi → báo, không gọi API; max_uses nhỏ hơn lượt đã dùng bị chặn", async () => {
    const user = userEvent.setup();
    render(<CouponForm mode="edit" base={used} usedCount={5} onSaved={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    expect(await screen.findByText("Chưa có thay đổi nào để lưu.")).toBeInTheDocument();
    await user.clear(screen.getByLabelText(/Tổng số lượt/));
    await user.type(screen.getByLabelText(/Tổng số lượt/), "3");
    await user.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    expect((await screen.findAllByText(/Không được nhỏ hơn số lượt đã dùng \(5\)/)).length).toBeGreaterThan(0);
    expect(api.updateCoupon).not.toHaveBeenCalled();
  });

  it("422 COUPON_LOCKED: banner, gọi onStale để tải lại; dữ liệu khác còn nguyên", async () => {
    const user = userEvent.setup();
    const onStale = vi.fn();
    const fresh = saved({ name: "Tên cũ" });
    vi.mocked(api.updateCoupon).mockRejectedValue(new ApiError(422, { message: "x", code: "COUPON_LOCKED", errors: { fields: ["discount_value"] } }));
    render(<CouponForm mode="edit" base={fresh} usedCount={0} onSaved={vi.fn()} onStale={onStale} />);
    await user.clear(screen.getByLabelText(/Giảm \(%\)/));
    await user.type(screen.getByLabelText(/Giảm \(%\)/), "30");
    await user.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    expect(await screen.findByText(/không đổi được giá trị giảm/)).toBeInTheDocument();
    expect(onStale).toHaveBeenCalled();
  });

  it("xoá trống 'Bắt đầu' khi sửa không tính là thay đổi, không gọi PUT", async () => {
    const user = userEvent.setup();
    render(<CouponForm mode="edit" base={saved()} usedCount={0} onSaved={vi.fn()} />);
    await user.clear(screen.getByLabelText(/Bắt đầu/));
    expect(screen.getByRole("button", { name: "Huỷ thay đổi" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    expect(await screen.findByText("Chưa có thay đổi nào để lưu.")).toBeInTheDocument();
    expect(api.updateCoupon).not.toHaveBeenCalled();
  });

  it("mã cũ có cả khóa và chuyên đề: lựa chọn 'Kết hợp' vẫn hiện sau khi chuyển radio", async () => {
    const user = userEvent.setup();
    const both = saved({ is_restricted: true, courses: [{ id: 11, title: "Hình học 9" }], subjects: [{ id: 1, name: "Đại số" }] });
    render(<CouponForm mode="edit" base={both} usedCount={0} onSaved={vi.fn()} />);
    await user.click(screen.getByRole("radio", { name: "Toàn bộ khóa học" }));
    expect(screen.getByRole("radio", { name: "Kết hợp chuyên đề và khóa học" })).toBeInTheDocument();
  });

  it("sau COUPON_LOCKED form mang giá trị server mới (bỏ thay đổi chưa lưu), vẫn giữ banner, mã/giá trị bị khoá", async () => {
    const user = userEvent.setup();
    const old = saved({ name: "Tên cũ" });
    vi.mocked(api.updateCoupon).mockRejectedValue(new ApiError(422, { message: "x", code: "COUPON_LOCKED", errors: { fields: ["discount_value"] } }));
    const { rerender } = render(<CouponForm mode="edit" base={old} usedCount={0} version={0} onSaved={vi.fn()} onStale={vi.fn()} />);
    await user.clear(screen.getByLabelText(/Giảm \(%\)/));
    await user.type(screen.getByLabelText(/Giảm \(%\)/), "30");
    await user.type(screen.getByLabelText(/Tên gợi nhớ/), " thêm");
    await user.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    expect(await screen.findByText(/không đổi được giá trị giảm/)).toBeInTheDocument();
    const fresh = saved({ name: "Tên server", used_count: 4 });
    rerender(<CouponForm mode="edit" base={fresh} usedCount={4} version={1} onSaved={vi.fn()} onStale={vi.fn()} />);
    expect(screen.getByLabelText(/Tên gợi nhớ/)).toHaveValue("Tên server");
    expect(screen.getByLabelText(/Giảm \(%\)/)).toHaveValue("20");
    expect(screen.getByLabelText(/Giảm \(%\)/)).toBeDisabled();
    expect(screen.getByText(/không đổi được giá trị giảm/)).toBeInTheDocument();
  });

  it("404 khi lưu → onGone", async () => {
    const user = userEvent.setup();
    const onGone = vi.fn();
    vi.mocked(api.updateCoupon).mockRejectedValue(new ApiError(404, { message: "x" }));
    render(<CouponForm mode="edit" base={saved()} usedCount={0} onSaved={vi.fn()} onGone={onGone} />);
    await user.type(screen.getByLabelText(/Tên gợi nhớ/), "x");
    await user.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    await waitFor(() => expect(onGone).toHaveBeenCalled());
  });
});
