"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
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
  formatDate,
  formatDateTime,
  formatPrice,
} from "@vitaminvui/ui/v2";

export interface OrderActionsProps {
  code: string;
  total: number;
  studentName: string;
  itemsCount: number;
  status: "pending" | "paid" | "cancelled" | "failed" | "refunded";
  /** Nhãn lý do huỷ (đã dịch) để nêu trong hộp Duyệt muộn. */
  cancelLabel: string | null;
  cancelledAt: string | null;
  /** Hạn cuối duyệt muộn (cancelled_at + 30 ngày) và còn trong hạn hay không (server tính). */
  approvalWindowUntil: string | null;
  windowOpen: boolean;
  /** Khóa đã ngừng bán trong đơn (AC23) — cảnh báo trước khi duyệt. */
  unpublishedTitles: string[];
  /** Cảnh báo duyệt muộn (đã sở hữu khóa, mã vượt lượt...). */
  lateWarnings: string[];
  /** Bản xem trước: trang kết quả sau mỗi thao tác (thành công hoặc 409). */
  outcomes: { approve: string; late: string; cancel: string; refund: string };
}

const REF_MAX = 100;
const NOTE_MAX = 1000;
const REASON_MIN = 5;
const REASON_MAX = 500;
const REASON_SAMPLES = [
  "Quản trị viên đã gọi điện và nhắn Zalo nhưng chưa liên lạc được với bạn. Khi sẵn sàng, bạn có thể đặt lại đơn từ giỏ hàng.",
  "Theo trao đổi với bạn, đơn này được huỷ để đặt lại đúng khóa cần học.",
];

/**
 * Hành động trên chi tiết đơn (US-022 AC17–AC22, US-010 AC4).
 * - Duyệt: hộp xác nhận, tick "Đã nhận đủ {tổng}" bắt buộc (nút khoá kèm câu giải thích), mã giao dịch + ghi chú tuỳ chọn.
 * - Duyệt muộn (đơn đã huỷ ≤ 30 ngày): 2 bước, nền cảnh báo, nêu thời điểm + lý do huỷ.
 * - Huỷ: lý do gửi học sinh BẮT BUỘC (5–500) tách khỏi ghi chú nội bộ.
 * - Hoàn tiền (đơn đã duyệt): như US-010.
 * TODO(dev): POST /admin/orders/{code}/approve `{confirm:true, payment_reference?, note?, late?}`,
 * /cancel `{reason, note?}`, /refund `{confirm:true, note?}`. Thành công → router.refresh() + toast.
 * 409 ALREADY_PROCESSED / ORDER_STATUS_CHANGED → đóng hộp, router.refresh(), Alert nêu trạng thái mới (không thử lại);
 * 409 COURSE_UNAVAILABLE / ORDER_APPROVAL_WINDOW_PASSED → Alert trong trang; 422 → lỗi dưới ô.
 */
export function OrderActions(p: OrderActionsProps) {
  const router = useRouter();
  const [dialog, setDialog] = useState<null | "approve" | "late" | "late-2" | "cancel" | "refund">(null);
  const [received, setReceived] = useState(false);
  const [reference, setReference] = useState("");
  const [note, setNote] = useState("");
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState<string>();
  const [refunded, setRefunded] = useState(false);
  const [busy, setBusy] = useState(false);

  const totalText = formatPrice(p.total);

  function open(d: NonNullable<typeof dialog>) {
    setReceived(false);
    setReference("");
    setNote("");
    setReason("");
    setReasonError(undefined);
    setRefunded(false);
    setDialog(d);
  }
  function close() {
    if (!busy) setDialog(null);
  }
  function go(href: string) {
    setBusy(true);
    setTimeout(() => router.push(href, { scroll: false }), 800);
  }

  const receivedBox = (
    <div className="flex flex-col gap-4">
      <div className="flex items-baseline justify-between gap-3 rounded-control bg-sunken p-3">
        <span className="text-sm text-ink-soft">Số tiền cần nhận</span>
        <span className="num text-heading font-extrabold tracking-heading text-ink">{totalText}</span>
      </div>
      <div>
        <Checkbox
          id="da-nhan-du"
          checked={received}
          onChange={(e) => setReceived(e.target.checked)}
          label={<span className="font-semibold">Đã nhận đủ {totalText}</span>}
          description="Đã đối chiếu sao kê: đúng số tiền, nội dung có mã đơn hoặc học sinh đã xác nhận."
        />
        {!received ? <p className="pl-8 text-sm text-ink-soft">Tick ô này để bật nút duyệt.</p> : null}
      </div>
      <Field label="Mã giao dịch / nội dung chuyển khoản (không bắt buộc)" aside={<span className="num text-ink-soft">{reference.length}/{REF_MAX}</span>}>
        <TextInput size="sm" value={reference} maxLength={REF_MAX} onChange={(e) => setReference(e.target.value)} placeholder="FT26281… VV2610…" autoComplete="off" />
      </Field>
      <Field label="Ghi chú nội bộ (không bắt buộc)" hint="Học sinh không thấy." aside={<span className="num text-ink-soft">{note.length}/{NOTE_MAX}</span>}>
        <Textarea rows={2} maxLength={NOTE_MAX} value={note} onChange={(e) => setNote(e.target.value)} className="text-sm" />
      </Field>
    </div>
  );

  const unpublishedAlert = p.unpublishedTitles.length ? (
    <Alert tone="warning" title="Có khóa đã ngừng bán">
      {p.unpublishedTitles.map((t) => `“${t}”`).join(", ")} đã ngừng bán sau khi đặt đơn. Duyệt vẫn mở khóa cho học sinh vì học sinh trả theo giá đã chốt.
    </Alert>
  ) : null;

  return (
    <>
      <div className="flex flex-col gap-2">
        <div className="flex flex-wrap gap-2">
          {p.status === "pending" ? (
            <>
              <Button size="sm" variant="secondary" onClick={() => open("cancel")}>
                Huỷ đơn
              </Button>
              <Button size="sm" leadingIcon={<IconCheck size={16} />} onClick={() => open("approve")}>
                Duyệt: đã nhận tiền
              </Button>
            </>
          ) : null}
          {p.status === "cancelled" && p.windowOpen ? (
            <Button size="sm" variant="secondary" leadingIcon={<IconAlertTriangle size={16} className="text-warning" />} onClick={() => open("late")}>
              Duyệt muộn
            </Button>
          ) : null}
          {p.status === "paid" ? (
            <Button size="sm" variant="secondary" onClick={() => open("refund")}>
              Đánh dấu hoàn tiền
            </Button>
          ) : null}
        </div>
        {p.status === "cancelled" && p.approvalWindowUntil ? (
          <p className="text-sm text-ink-soft">
            {p.windowOpen
              ? `Nếu học sinh đã chuyển tiền sau khi đơn huỷ, có thể duyệt muộn tới ${formatDate(p.approvalWindowUntil)}.`
              : `Đã quá 30 ngày kể từ lúc huỷ (hạn ${formatDate(p.approvalWindowUntil)}), không duyệt được nữa. Nếu học sinh đã chuyển tiền, hoàn tiền ngoài hệ thống.`}
          </p>
        ) : null}
      </div>

      {/* Duyệt */}
      <Dialog
        open={dialog === "approve"}
        onClose={close}
        dismissible={!busy}
        title={`Duyệt đơn ${p.code}`}
        description={`${p.studentName} được mở ${p.itemsCount} khóa ngay và nhận email xác nhận. Lượt dùng mã giảm giá (nếu có) được ghi nhận.`}
        footer={
          <>
            <Button variant="secondary" onClick={close} disabled={busy}>
              Huỷ
            </Button>
            <Button disabled={!received} loading={busy} loadingText="Đang duyệt…" onClick={() => go(p.outcomes.approve)}>
              Duyệt đơn
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          {unpublishedAlert}
          {receivedBox}
        </div>
      </Dialog>

      {/* Duyệt muộn — bước 1 */}
      <Dialog
        open={dialog === "late"}
        onClose={close}
        title={`Duyệt muộn đơn ${p.code}`}
        description="Đơn này đã huỷ. Chỉ duyệt khi chắc chắn đã nhận tiền cho đúng đơn này."
        footer={
          <>
            <Button variant="secondary" onClick={close}>
              Huỷ
            </Button>
            <Button disabled={!received} onClick={() => setDialog("late-2")}>
              Tiếp tục
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          <div className="flex gap-3 rounded-card border border-warning/40 bg-warning-soft p-4 text-sm text-ink">
            <IconAlertTriangle className="mt-0.5 shrink-0 text-warning" />
            <div className="flex flex-col gap-1">
              <p className="font-semibold">
                Đơn đã huỷ lúc {p.cancelledAt ? formatDateTime(p.cancelledAt) : "—"}
                {p.cancelLabel ? ` — ${p.cancelLabel.toLowerCase()}` : ""}.
              </p>
              <p>Duyệt muộn sẽ mở khóa cho học sinh và gắn cờ “Cần xem lại” cho đơn.</p>
              {p.lateWarnings.length ? (
                <ul className="mt-1 list-disc pl-5">
                  {p.lateWarnings.map((w) => (
                    <li key={w}>{w}</li>
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
        description={`Đơn ${p.code} — ${totalText}. ${p.studentName} được mở ${p.itemsCount} khóa ngay. Không hoàn tác được; muốn thu hồi phải dùng “Đánh dấu hoàn tiền”.`}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDialog("late")} disabled={busy}>
              Quay lại
            </Button>
            <Button loading={busy} loadingText="Đang duyệt…" onClick={() => go(p.outcomes.late)}>
              Xác nhận duyệt muộn
            </Button>
          </>
        }
      />

      {/* Huỷ */}
      <Dialog
        open={dialog === "cancel"}
        onClose={close}
        dismissible={!busy}
        title={`Huỷ đơn ${p.code}`}
        description="Học sinh nhận email kèm lý do. Lượt mã giảm giá được nhả; giỏ hàng của học sinh giữ nguyên."
        footer={
          <>
            <Button variant="secondary" onClick={close} disabled={busy} autoFocus>
              Không huỷ
            </Button>
            <Button
              variant="danger"
              loading={busy}
              loadingText="Đang huỷ…"
              onClick={() => {
                const r = reason.trim();
                if (r.length < REASON_MIN) {
                  setReasonError(`Nhập lý do cho học sinh, ít nhất ${REASON_MIN} ký tự.`);
                  document.getElementById("ly-do-huy")?.focus();
                  return;
                }
                go(p.outcomes.cancel);
              }}
            >
              Huỷ đơn
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          <Field
            id="ly-do-huy"
            label="Lý do gửi học sinh"
            required
            hint="Học sinh đọc được lý do này trong email và trang đơn hàng. Viết lịch sự, không ghi thông tin nội bộ."
            error={reasonError}
            aside={<span className="num text-ink-soft">{reason.length}/{REASON_MAX}</span>}
          >
            <Textarea
              rows={3}
              maxLength={REASON_MAX}
              value={reason}
              onChange={(e) => {
                setReason(e.target.value);
                if (reasonError) setReasonError(undefined);
              }}
              className="text-sm"
            />
          </Field>
          <div className="flex flex-col gap-1.5">
            <p className="text-xs font-semibold text-ink-soft">Câu mẫu (bấm để điền, sửa lại được)</p>
            <div className="flex flex-wrap gap-2">
              {REASON_SAMPLES.map((s, i) => (
                <button
                  key={s}
                  type="button"
                  onClick={() => {
                    setReason(s);
                    setReasonError(undefined);
                  }}
                  className="focus-ring min-h-9 rounded-full border border-line-strong px-3 text-left text-xs font-medium text-ink hover:border-primary hover:text-primary"
                >
                  {i === 0 ? "Không liên lạc được" : "Học sinh muốn đặt lại"}
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
              aside={<span className="num text-ink-soft">{note.length}/{NOTE_MAX}</span>}
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
        title={`Đánh dấu hoàn tiền đơn ${p.code}`}
        description={`${p.studentName} mất quyền học ${p.itemsCount} khóa trong đơn ngay lập tức. Không hoàn tác được.`}
        footer={
          <>
            <Button variant="secondary" onClick={close} disabled={busy} autoFocus>
              Không hoàn tiền
            </Button>
            <Button variant="danger" disabled={!refunded} loading={busy} loadingText="Đang xử lý…" onClick={() => go(p.outcomes.refund)}>
              Xác nhận hoàn tiền
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          <Alert tone="danger" role="none">
            Hệ thống không tự chuyển tiền. Hãy hoàn {totalText} cho học sinh ngoài hệ thống trước khi xác nhận.
          </Alert>
          <div>
            <Checkbox checked={refunded} onChange={(e) => setRefunded(e.target.checked)} label={`Tôi đã hoàn ${totalText} cho học sinh`} />
            {!refunded ? <p className="pl-8 text-sm text-ink-soft">Tick ô này để bật nút xác nhận.</p> : null}
          </div>
          <Field label="Ghi chú (không bắt buộc)" aside={<span className="num text-ink-soft">{note.length}/{NOTE_MAX}</span>}>
            <Textarea rows={2} maxLength={NOTE_MAX} value={note} onChange={(e) => setNote(e.target.value)} className="text-sm" />
          </Field>
        </div>
      </Dialog>
    </>
  );
}
