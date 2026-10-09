"use client";

import { useEffect, useRef, useState } from "react";
import {
  Alert,
  Button,
  Checkbox,
  Dialog,
  Field,
  IconAlertTriangle,
  IconCheck,
  IconLock,
  TextInput,
  Textarea,
  formatPrice,
} from "@vitaminvui/ui/v2";
import { approveOrder, cancelOrder, refundOrder } from "@/lib/orders/api";
import { classifyActionError, type ActionFailure, type Notice, type OrderAction } from "@/lib/orders/errors";
import { adminStatus, formatDay, formatWhen, lastLogTo, warningText } from "@/lib/orders/format";
import {
  NOTE_MAX,
  REASON_MAX,
  REF_MAX,
  approveFormSchema,
  cancelFormSchema,
  firstErrors,
  refundFormSchema,
  type OrderDetail,
} from "@/lib/orders/schemas";

type DialogKind = null | "approve" | "late" | "late-2" | "cancel" | "refund";

const REASON_SAMPLES: Array<{ label: string; text: string }> = [
  { label: "Không liên lạc được", text: "Quản trị viên đã gọi điện và nhắn Zalo nhưng chưa liên lạc được với bạn. Khi sẵn sàng, bạn có thể đặt lại đơn từ giỏ hàng." },
  { label: "Học sinh muốn đặt lại", text: "Theo trao đổi với bạn, đơn này được huỷ để đặt lại đúng khóa cần học." },
];

const counter = (n: number, max: number) => (
  <span className="num text-ink-soft">
    {n}/{max}
  </span>
);

export interface OrderActionsProps {
  order: OrderDetail;
  /** Thao tác thành công: `order` là chi tiết mới trả về (không cần GET lại → không ghi thêm audit xem). */
  onUpdated: (order: OrderDetail, notice: Notice) => void;
  /** 409 (và 403/404): đã đóng hộp; cha tải lại đơn rồi báo rõ. */
  onConflict: (action: OrderAction, failure: ActionFailure) => void;
}

/**
 * Hành động trên chi tiết đơn (US-022 AC17–AC22, US-010 AC4). Nút hiện theo `order.approval.*` của server
 * (một nguồn sự thật với guard); quyền và trạng thái thật vẫn do API kiểm. Chống bấm kép: khoá bằng ref + `busy`.
 */
export function OrderActions({ order, onUpdated, onConflict }: OrderActionsProps) {
  const { approval } = order;
  const [dialog, setDialog] = useState<DialogKind>(null);
  const [received, setReceived] = useState(false);
  const [reference, setReference] = useState("");
  const [note, setNote] = useState("");
  const [reason, setReason] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const lock = useRef(false);

  // Hộp nguy hiểm (huỷ, hoàn tiền): focus đầu vào nút an toàn "Không huỷ"/"Không hoàn tiền". `autoFocus` của React chỉ chạy lúc
  // mount (khi <dialog> còn đóng nên bỏ qua), vì vậy focus tường minh mỗi lần hộp mở.
  useEffect(() => {
    if (dialog !== "cancel" && dialog !== "refund") return;
    const id = dialog === "cancel" ? "khong-huy" : "khong-hoan-tien";
    const t = window.setTimeout(() => document.getElementById(id)?.focus(), 0);
    return () => window.clearTimeout(t);
  }, [dialog]);

  const totalText = formatPrice(order.total);
  const who = order.student.is_deleted ? "Học sinh" : order.student.name;
  const unpublished = order.approval.warnings.filter((w) => w.code === "COURSE_UNPUBLISHED");
  const lateOnlyWarnings = order.approval.late_approval_warnings;
  const showRefund = order.status === "paid" && order.payment_method !== "none";

  function resetForm() {
    setReceived(false);
    setReference("");
    setNote("");
    setReason("");
    setConfirmed(false);
    setErrors({});
    setBanner(null);
  }
  function open(d: Exclude<DialogKind, null>) {
    resetForm();
    setDialog(d);
  }
  function close() {
    if (!busy) setDialog(null);
  }

  /** Chạy một thao tác ghi: khoá chống bấm kép, phân loại lỗi, 409 → đóng hộp + báo cha. */
  async function run(action: OrderAction, call: () => Promise<OrderDetail>, success: Notice, backTo: DialogKind) {
    if (lock.current) return;
    lock.current = true;
    setBusy(true);
    setBanner(null);
    try {
      const next = await call();
      setDialog(null);
      resetForm(); // không để mã giao dịch/ghi chú nội bộ nằm lại trong hộp thoại đã đóng
      onUpdated(next, success);
    } catch (err) {
      const f = classifyActionError(err);
      if (f.reload || f.kind === "forbidden" || f.kind === "not_found") {
        setDialog(null);
        resetForm();
        onConflict(action, f);
      } else {
        const fieldMap: Record<string, string> = {};
        for (const [k, v] of Object.entries(f.fields)) if (v) fieldMap[k] = v;
        setErrors(fieldMap);
        setBanner(f.message || null);
        if (backTo && Object.keys(fieldMap).length > 0) setDialog(backTo);
        if (fieldMap["reason"]) document.getElementById("ly-do-huy")?.focus();
      }
    } finally {
      lock.current = false;
      setBusy(false);
    }
  }

  function submitApprove(late: boolean) {
    const parsed = approveFormSchema.safeParse({ received, payment_reference: reference, note });
    if (!parsed.success) {
      setErrors(firstErrors(parsed.error));
      return;
    }
    setErrors({});
    void run(
      late ? "late" : "approve",
      () => approveOrder(order.code, { late, payment_reference: parsed.data.payment_reference, note: parsed.data.note }),
      late
        ? { tone: "success", title: "Đã duyệt muộn", body: "Học sinh đã được mở khóa học. Đơn được gắn cờ “Cần xem lại”." }
        : { tone: "success", title: "Đã duyệt đơn", body: "Học sinh đã được mở khóa học." },
      late ? "late" : "approve",
    );
  }

  function submitCancel() {
    const parsed = cancelFormSchema.safeParse({ reason, note });
    if (!parsed.success) {
      const e = firstErrors(parsed.error);
      setErrors(e);
      if (e["reason"]) document.getElementById("ly-do-huy")?.focus();
      return;
    }
    setErrors({});
    void run(
      "cancel",
      () => cancelOrder(order.code, parsed.data),
      { tone: "success", title: "Đã huỷ đơn", body: "Học sinh nhận email kèm lý do. Lượt mã giảm giá đã được nhả." },
      "cancel",
    );
  }

  function submitRefund() {
    const parsed = refundFormSchema.safeParse({ confirmed, note });
    if (!parsed.success) {
      setErrors(firstErrors(parsed.error));
      return;
    }
    setErrors({});
    void run("refund", () => refundOrder(order.code, parsed.data.note), { tone: "success", title: "Đã đánh dấu hoàn tiền", body: "Đơn đã chuyển sang trạng thái Đã hoàn tiền." }, "refund");
  }

  const unpublishedAlert = unpublished.length ? (
    <Alert tone="warning" title="Có khóa đã ngừng bán">
      {unpublished.map((w) => `“${w.title ?? ""}”`).join(", ")} đã ngừng bán sau khi đặt đơn. Duyệt vẫn mở khóa cho học sinh vì học sinh trả theo giá đã chốt.
    </Alert>
  ) : null;

  const bannerAlert = banner ? (
    <Alert tone="danger" role="alert">
      {banner}
    </Alert>
  ) : null;

  const receivedBox = (
    <div className="flex flex-col gap-4">
      <div className="flex items-baseline justify-between gap-3 rounded-control bg-sunken p-3">
        <span className="text-sm text-ink-soft">Số tiền cần nhận</span>
        <span className="num text-heading font-extrabold tracking-heading text-ink">{totalText}</span>
      </div>
      <div>
        <Checkbox
          checked={received}
          onChange={(e) => {
            setReceived(e.target.checked);
            setErrors((p) => ({ ...p, received: "" }));
          }}
          label={<span className="font-semibold">Đã nhận đủ {totalText}</span>}
          description="Đã đối chiếu sao kê: đúng số tiền, nội dung có mã đơn hoặc học sinh đã xác nhận."
        />
        {!received ? <p className="pl-8 text-sm text-ink-soft">Tick ô này để bật nút duyệt.</p> : null}
        {errors["confirm"] || errors["received"] ? (
          <p role="alert" className="pl-8 text-sm font-medium text-danger">
            {errors["confirm"] || errors["received"]}
          </p>
        ) : null}
      </div>
      <Field label="Mã giao dịch / nội dung chuyển khoản (không bắt buộc)" error={errors["payment_reference"]} aside={counter(reference.length, REF_MAX)}>
        <TextInput size="sm" value={reference} maxLength={REF_MAX} onChange={(e) => setReference(e.target.value)} placeholder="FT26281… VV2610…" autoComplete="off" />
      </Field>
      <Field label="Ghi chú nội bộ (không bắt buộc)" hint="Học sinh không thấy." error={errors["note"]} aside={counter(note.length, NOTE_MAX)}>
        <Textarea rows={2} maxLength={NOTE_MAX} value={note} onChange={(e) => setNote(e.target.value)} className="text-sm" />
      </Field>
    </div>
  );

  const cancelled = lastLogTo(order, "cancelled");
  const cancelLabel = order.status === "cancelled" ? adminStatus(order.status, order.status_reason, order.payment_method).label : null;
  const accountGone = order.student.is_deleted || order.status_reason === "account_deleted";
  const mobileBtn = "max-sm:h-11";

  return (
    <>
      <div className="flex flex-col gap-2">
        <div className="flex flex-wrap gap-2">
          {approval.can_cancel ? (
            <Button size="sm" variant="secondary" className={mobileBtn} onClick={() => open("cancel")}>
              Huỷ đơn
            </Button>
          ) : null}
          {approval.can_approve ? (
            <Button size="sm" className={mobileBtn} leadingIcon={<IconCheck size={16} />} onClick={() => open("approve")}>
              Duyệt: đã nhận tiền
            </Button>
          ) : null}
          {approval.can_approve_late ? (
            <Button size="sm" variant="secondary" className={mobileBtn} leadingIcon={<IconAlertTriangle size={16} className="text-warning" />} onClick={() => open("late")}>
              Duyệt muộn
            </Button>
          ) : null}
          {showRefund ? (
            <Button size="sm" variant="secondary" className={mobileBtn} onClick={() => open("refund")}>
              Đánh dấu hoàn tiền
            </Button>
          ) : null}
        </div>
        {order.status === "cancelled" && order.payment_method === "manual" ? (
          <p className="max-w-md text-sm text-ink-soft">
            {approval.can_approve_late && approval.approval_window_until
              ? `Nếu học sinh đã chuyển tiền sau khi đơn huỷ, có thể duyệt muộn tới ${formatDay(approval.approval_window_until)}.`
              : accountGone
                ? "Tài khoản học sinh đã xoá nên không duyệt muộn được."
                : approval.approval_window_until
                  ? `Đã quá hạn duyệt muộn (hạn ${formatDay(approval.approval_window_until)}). Nếu học sinh đã chuyển tiền, hoàn tiền ngoài hệ thống.`
                  : "Đơn này không còn duyệt muộn được."}
          </p>
        ) : null}
      </div>

      {/* Duyệt */}
      <Dialog
        open={dialog === "approve"}
        onClose={close}
        dismissible={!busy}
        title={`Duyệt đơn ${order.code}`}
        description={`${who} được mở ${order.items.length} khóa ngay và nhận email xác nhận. Lượt dùng mã giảm giá (nếu có) được ghi nhận.`}
        footer={
          <>
            <Button variant="secondary" onClick={close} disabled={busy}>
              Huỷ
            </Button>
            <Button disabled={!received} loading={busy} loadingText="Đang duyệt…" onClick={() => submitApprove(false)}>
              Duyệt đơn
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          {bannerAlert}
          {unpublishedAlert}
          {receivedBox}
        </div>
      </Dialog>

      {/* Duyệt muộn — bước 1 */}
      <Dialog
        open={dialog === "late"}
        onClose={close}
        title={`Duyệt muộn đơn ${order.code}`}
        description="Đơn này đã huỷ. Chỉ duyệt khi chắc chắn đã nhận tiền cho đúng đơn này."
        footer={
          <>
            <Button variant="secondary" onClick={close}>
              Huỷ
            </Button>
            <Button
              disabled={!received}
              onClick={() => {
                const parsed = approveFormSchema.safeParse({ received, payment_reference: reference, note });
                if (!parsed.success) setErrors(firstErrors(parsed.error));
                else {
                  setErrors({});
                  setDialog("late-2");
                }
              }}
            >
              Tiếp tục
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          {bannerAlert}
          <div className="flex gap-3 rounded-card border border-warning/40 bg-warning-soft p-4 text-sm text-ink" data-testid="late-warning-box">
            <IconAlertTriangle className="mt-0.5 shrink-0 text-warning" />
            <div className="flex min-w-0 flex-col gap-1">
              <p className="font-semibold">
                Đơn đã huỷ lúc {order.cancelled_at ? formatWhen(order.cancelled_at) : cancelled ? formatWhen(cancelled.created_at) : "—"}
                {cancelLabel ? ` — ${cancelLabel.toLowerCase()}` : ""}.
              </p>
              {order.cancel_reason ? <p className="whitespace-pre-line break-words">Lý do đã gửi học sinh: {order.cancel_reason}</p> : null}
              {approval.approval_window_until ? <p>Duyệt muộn được tới {formatDay(approval.approval_window_until)}.</p> : null}
              <p>Duyệt muộn sẽ mở khóa cho học sinh và gắn cờ “Cần xem lại” cho đơn.</p>
              {lateOnlyWarnings.length ? (
                <ul className="mt-1 list-disc pl-5" aria-label="Cảnh báo duyệt muộn">
                  {lateOnlyWarnings.map((w, i) => (
                    <li key={`${w.code}-${w.course_id ?? w.coupon_code ?? i}`}>{warningText(w, true)}</li>
                  ))}
                </ul>
              ) : null}
            </div>
          </div>
          {unpublishedAlert}
          {receivedBox}
        </div>
      </Dialog>

      {/* Duyệt muộn — bước 2 (xác nhận lần 2) */}
      <Dialog
        open={dialog === "late-2"}
        onClose={close}
        dismissible={!busy}
        size="sm"
        title="Xác nhận lần 2: duyệt đơn đã huỷ?"
        description={`Đơn ${order.code} — ${totalText}. ${who} được mở ${order.items.length} khóa ngay. Không hoàn tác được; muốn thu hồi phải dùng “Đánh dấu hoàn tiền”.`}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDialog("late")} disabled={busy}>
              Quay lại
            </Button>
            <Button loading={busy} loadingText="Đang duyệt…" onClick={() => submitApprove(true)}>
              Xác nhận duyệt muộn
            </Button>
          </>
        }
      >
        {banner ? bannerAlert : null}
      </Dialog>

      {/* Huỷ */}
      <Dialog
        open={dialog === "cancel"}
        onClose={close}
        dismissible={!busy}
        title={`Huỷ đơn ${order.code}`}
        description="Học sinh nhận email kèm lý do. Lượt mã giảm giá được nhả; giỏ hàng của học sinh giữ nguyên."
        footer={
          <>
            <Button id="khong-huy" variant="secondary" onClick={close} disabled={busy}>
              Không huỷ
            </Button>
            <Button variant="danger" loading={busy} loadingText="Đang huỷ…" onClick={submitCancel}>
              Huỷ đơn
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          {bannerAlert}
          <Field
            id="ly-do-huy"
            label="Lý do gửi học sinh"
            required
            hint="Học sinh đọc được lý do này trong email và trang đơn hàng. Viết lịch sự, không ghi thông tin nội bộ."
            error={errors["reason"]}
            aside={counter(reason.length, REASON_MAX)}
          >
            <Textarea
              rows={3}
              maxLength={REASON_MAX}
              value={reason}
              onChange={(e) => {
                setReason(e.target.value);
                if (errors["reason"]) setErrors((p) => ({ ...p, reason: "" }));
              }}
              className="text-sm"
            />
          </Field>
          <div className="flex flex-col gap-1.5">
            <p className="text-xs font-semibold text-ink-soft">Câu mẫu (bấm để điền, sửa lại được)</p>
            <div className="flex flex-wrap gap-2">
              {REASON_SAMPLES.map((s) => (
                <button
                  key={s.label}
                  type="button"
                  onClick={() => {
                    setReason(s.text);
                    setErrors((p) => ({ ...p, reason: "" }));
                  }}
                  className="focus-ring min-h-11 rounded-full border border-line-strong px-3 text-left text-xs font-medium text-ink hover:border-primary hover:text-primary sm:min-h-9"
                >
                  {s.label}
                </button>
              ))}
            </div>
          </div>
          <div className="border-t border-line pt-4">
            <Field
              label={
                <span className="inline-flex items-center gap-1.5">
                  <IconLock size={14} /> Ghi chú nội bộ (không bắt buộc)
                </span>
              }
              hint="Chỉ Quản trị viên thấy. Học sinh không nhận được nội dung này."
              error={errors["note"]}
              aside={counter(note.length, NOTE_MAX)}
            >
              <Textarea rows={2} maxLength={NOTE_MAX} value={note} onChange={(e) => setNote(e.target.value)} className="text-sm" />
            </Field>
          </div>
        </div>
      </Dialog>

      {/* Hoàn tiền (US-010 AC4) */}
      <Dialog
        open={dialog === "refund"}
        onClose={close}
        dismissible={!busy}
        title={`Đánh dấu hoàn tiền đơn ${order.code}`}
        description={`${who} mất quyền học ${order.items.length} khóa trong đơn. Không hoàn tác được.`}
        footer={
          <>
            <Button id="khong-hoan-tien" variant="secondary" onClick={close} disabled={busy}>
              Không hoàn tiền
            </Button>
            <Button variant="danger" disabled={!confirmed} loading={busy} loadingText="Đang xử lý…" onClick={submitRefund}>
              Xác nhận hoàn tiền
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          {bannerAlert}
          <Alert tone="danger" role="none">
            Hệ thống không tự chuyển tiền. Hãy hoàn {totalText} cho học sinh ngoài hệ thống trước khi xác nhận.
          </Alert>
          <div>
            <Checkbox checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} label={`Tôi đã hoàn ${totalText} cho học sinh`} />
            {!confirmed ? <p className="pl-8 text-sm text-ink-soft">Tick ô này để bật nút xác nhận.</p> : null}
          </div>
          <Field label="Ghi chú (không bắt buộc)" error={errors["note"]} aside={counter(note.length, NOTE_MAX)}>
            <Textarea rows={2} maxLength={NOTE_MAX} value={note} onChange={(e) => setNote(e.target.value)} className="text-sm" />
          </Field>
        </div>
      </Dialog>
    </>
  );
}
