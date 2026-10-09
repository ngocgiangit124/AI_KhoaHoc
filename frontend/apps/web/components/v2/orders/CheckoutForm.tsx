"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useId, useRef, useState, type FormEvent, type ReactNode } from "react";
import {
  Alert,
  Button,
  ButtonLink,
  Dialog,
  Field,
  IconAlertTriangle,
  IconCheck,
  IconMessageCircle,
  Sheet,
  Textarea,
  cx,
  formatDateTime,
  formatPrice,
} from "@vitaminvui/ui/v2";
import type { PaymentMethod, StudentOrder } from "@/lib/mock/v2/orders";
import { routes, sampleStudent } from "@/lib/v2/routes";
import { PricingSummary } from "./OrderParts";

/** Kết quả giả lập khi bấm "Gửi đơn" (bản xem trước). */
export type CheckoutDemo =
  | "ok"
  | "pending-exists" // 409 PENDING_ORDER_EXISTS → hộp thoại thay đơn (AC6)
  | "changed" // 409 CHECKOUT_CHANGED → cập nhật tổng (AC7)
  | "too-many" // 429 (AC8)
  | "disabled" // 503 PAYMENT_DISABLED (AC29)
  | "not-verified"; // 403 ACCOUNT_NOT_VERIFIED

const NOTE_MAX = 500;

/**
 * Form thanh toán (US-022 AC2–AC8): chọn phương thức (radio card, server quyết định danh sách), ghi chú cho
 * Quản trị viên, "Gửi đơn". Danh sách khóa render ở server và truyền vào `items`.
 *
 * TODO(dev): POST /checkout `{expected_total, payment_method, customer_note, replace_pending?}`:
 * - 201/200 → `router.push('/thanh-toan/da-gui/{order_code}')` (200 `reused=true`: cùng đơn, không thêm thư);
 * - 409 PENDING_ORDER_EXISTS (`errors.order_code`) → hộp thoại; "Đặt đơn mới" gửi lại với `replace_pending=true`;
 * - 409 CHECKOUT_CHANGED → thay items/pricing bằng `errors.preview`, cập nhật `expected_total`;
 * - 422 CART_EMPTY → về /gio-hang; 403 ACCOUNT_NOT_VERIFIED → /can-xac-thuc; 429 / 503: hiện `message`, không tự thử lại;
 * - MoMo (khi bật lại): giữ luồng US-005 (`payment.pay_url`, kiểm host allowlist S23).
 */
export function CheckoutForm({
  items,
  methods,
  pricing,
  couponCode,
  ttlHours,
  pendingOrder,
  demo = "ok",
  successCode,
  initialSubmitting = false,
}: {
  items: ReactNode;
  methods: PaymentMethod[];
  pricing: { subtotal: number; discount: number; total: number };
  couponCode: string | null;
  ttlHours: number;
  /** Đơn đang chờ của học sinh (cho hộp thoại AC6). */
  pendingOrder: StudentOrder;
  demo?: CheckoutDemo;
  successCode: string;
  initialSubmitting?: boolean;
}) {
  const router = useRouter();
  const zero = pricing.total === 0;
  const [method, setMethod] = useState<PaymentMethod>(methods[0] ?? "manual");
  const [note, setNote] = useState("");
  const [noteError, setNoteError] = useState<string>();
  const [submitting, setSubmitting] = useState(initialSubmitting);
  const [currentPricing, setCurrentPricing] = useState(pricing);
  const [currentCoupon, setCurrentCoupon] = useState(couponCode);
  const [error, setError] = useState<null | "changed" | "too-many" | "disabled">(null);
  const [confirmReplace, setConfirmReplace] = useState(false);
  const [replacing, setReplacing] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const alertRef = useRef<HTMLDivElement>(null);
  const noteId = useId();

  const manual = !zero && method === "manual";
  const submitLabel = zero ? "Hoàn tất đăng ký" : method === "momo" ? "Thanh toán qua MoMo" : "Gửi đơn";

  function focusAlert() {
    requestAnimationFrame(() => alertRef.current?.focus());
  }

  function submit(e?: FormEvent) {
    e?.preventDefault();
    if (/<\s*[a-z/!?]/i.test(note)) {
      setNoteError("Ghi chú chỉ gồm chữ thường, không chứa thẻ HTML (dấu < liền chữ).");
      document.getElementById(noteId)?.focus();
      return;
    }
    setSubmitting(true);
    setError(null);
    setTimeout(() => {
      setSubmitting(false);
      const outcome = attempt === 0 ? demo : "ok";
      setAttempt((n) => n + 1);
      if (outcome === "pending-exists") return setConfirmReplace(true);
      if (outcome === "changed") {
        setCurrentPricing({ subtotal: currentPricing.subtotal, discount: 0, total: currentPricing.subtotal });
        setCurrentCoupon(null);
        setError("changed");
        return focusAlert();
      }
      if (outcome === "too-many" || outcome === "disabled") {
        setError(outcome);
        return focusAlert();
      }
      if (outcome === "not-verified") return router.push(routes.needVerify);
      router.push(routes.orderSent(successCode));
    }, 900);
  }

  return (
    <form onSubmit={submit} noValidate className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
      <div className="flex min-w-0 flex-col gap-4">
        {error ? (
          <div ref={alertRef} tabIndex={-1} className="focus-ring rounded-card">
            {error === "changed" ? (
              <Alert tone="warning" title="Giỏ hàng vừa thay đổi" role="alert">
                Mã {couponCode} đã hết lượt nên được gỡ khỏi đơn. Tổng mới là <strong className="num">{formatPrice(currentPricing.total)}</strong>. Kiểm tra lại rồi bấm “{submitLabel}”.
              </Alert>
            ) : error === "too-many" ? (
              <Alert tone="danger" title="Bạn đã đặt quá nhiều đơn hôm nay">
                Vui lòng liên hệ Quản trị viên qua email <a className="focus-ring rounded font-semibold text-primary underline underline-offset-4" href="mailto:hotro@vitaminvui.vn">hotro@vitaminvui.vn</a> để được hỗ trợ. Từ 0:00 ngày mai bạn có thể đặt lại.
              </Alert>
            ) : (
              <Alert tone="info" title="Đặt mua đang tạm đóng" role="alert">
                Thanh toán đang tạm khoá. Giỏ hàng của bạn vẫn được giữ; bạn có thể quay lại sau.
              </Alert>
            )}
          </div>
        ) : null}

        <Sheet as="section" aria-labelledby="khoa-trong-don" padding="md">
          <div className="flex items-baseline justify-between gap-3">
            <h2 id="khoa-trong-don" className="text-heading font-extrabold tracking-heading text-ink">
              Khóa học trong đơn
            </h2>
            <Link href={routes.cart} className="focus-ring rounded text-sm font-semibold text-primary underline-offset-4 hover:underline">
              Sửa giỏ hàng
            </Link>
          </div>
          <div className="mt-2">{items}</div>
        </Sheet>

        {zero ? (
          <Alert tone="info" title="Đơn này được miễn phí nhờ mã giảm giá">
            Không cần thanh toán. Bấm “Hoàn tất đăng ký” để vào học ngay.
          </Alert>
        ) : (
          <Sheet as="section" padding="md">
            <fieldset>
              <legend className="text-heading font-extrabold tracking-heading text-ink">Phương thức thanh toán</legend>
              <div className="mt-4 flex flex-col gap-3">
                {methods.map((m) => (
                  <MethodOption key={m} method={m} checked={method === m} onSelect={() => setMethod(m)} />
                ))}
              </div>
            </fieldset>

            {manual ? (
              <div className="mt-5 flex flex-col gap-5 border-t border-line pt-5">
                <div>
                  <h3 className="text-lg font-semibold text-ink">Sau khi gửi đơn</h3>
                  <ol className="mt-2 flex flex-col gap-2 text-base text-ink">
                    {[
                      "Quản trị viên liên hệ bạn trong giờ hỗ trợ để hướng dẫn chuyển khoản.",
                      "Bạn chuyển khoản và ghi mã đơn trong nội dung chuyển khoản.",
                      "Quản trị viên xác nhận đã nhận tiền, khóa học mở ngay trong “Khóa học của tôi”.",
                    ].map((t, i) => (
                      <li key={t} className="flex gap-3">
                        <span aria-hidden="true" className="num flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-sm font-extrabold text-primary">
                          {i + 1}
                        </span>
                        <span className="leading-relaxed">{t}</span>
                      </li>
                    ))}
                  </ol>
                </div>

                <div className="rounded-control bg-sunken p-4">
                  <p className="text-sm font-semibold text-ink">Quản trị viên sẽ liên hệ bạn qua</p>
                  <ul className="mt-1 text-base text-ink">
                    <li>Email: {sampleStudent.email}</li>
                    <li className="num">Số điện thoại: {sampleStudent.phone}</li>
                  </ul>
                  <Link href={routes.account} className="focus-ring mt-2 inline-flex min-h-11 items-center rounded text-sm font-semibold text-primary underline underline-offset-4">
                    Sai thông tin? Sửa trong Tài khoản
                  </Link>
                </div>

                <Field
                  id={noteId}
                  label="Ghi chú cho Quản trị viên (không bắt buộc)"
                  hint="Ví dụ: “Gọi sau 18h” hoặc Zalo của bố mẹ. Không ghi mật khẩu hay mã OTP."
                  error={noteError}
                  aside={<span className={cx("num", note.length > NOTE_MAX ? "text-danger" : "text-ink-soft")}>{note.length}/{NOTE_MAX}</span>}
                >
                  <Textarea
                    rows={3}
                    maxLength={NOTE_MAX}
                    value={note}
                    onChange={(e) => {
                      setNote(e.target.value);
                      if (noteError) setNoteError(undefined);
                    }}
                  />
                </Field>
              </div>
            ) : null}
          </Sheet>
        )}
      </div>

      <aside aria-label="Tổng tiền và gửi đơn" className="flex flex-col gap-4 lg:sticky lg:top-24">
        <Sheet padding="md">
          <h2 className="text-lg font-semibold text-ink">Tổng tiền</h2>
          <PricingSummary pricing={currentPricing} couponCode={currentCoupon} className="mt-3" />
          <div className="mt-5 hidden lg:block">
            <SubmitButton label={submitLabel} submitting={submitting} disabled={error === "disabled"} />
          </div>
          <SubmitNote manual={manual} zero={zero} ttlHours={ttlHours} />
        </Sheet>
      </aside>

      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3 shadow-overlay lg:hidden">
        <div className="mx-auto flex max-w-2xl items-center gap-3">
          <div className="num flex-1">
            <p className="text-sm text-ink-soft">Tổng cộng</p>
            <p className="text-lg font-extrabold text-ink">{currentPricing.total === 0 ? "0đ" : formatPrice(currentPricing.total)}</p>
          </div>
          <SubmitButton label={submitLabel} submitting={submitting} disabled={error === "disabled"} compact />
        </div>
      </div>

      <Dialog
        open={confirmReplace}
        onClose={() => setConfirmReplace(false)}
        dismissible={!replacing}
        title="Bạn đang có đơn chờ duyệt"
        description={
          <>
            Đơn <strong className="num text-ink">{pendingOrder.code}</strong> ({pendingOrder.items.length} khóa, {formatPrice(pendingOrder.pricing.total)}), đặt lúc{" "}
            {formatDateTime(pendingOrder.created_at)}, đang chờ Quản trị viên duyệt. Đặt đơn mới sẽ huỷ đơn cũ.
          </>
        }
        size="md"
        footer={
          <>
            <Button variant="secondary" autoFocus disabled={replacing} onClick={() => setConfirmReplace(false)}>
              Giữ đơn cũ
            </Button>
            <Button
              loading={replacing}
              loadingText="Đang gửi đơn mới…"
              onClick={() => {
                setReplacing(true);
                setTimeout(() => router.push(routes.orderSent(successCode)), 900);
              }}
            >
              Huỷ đơn cũ, đặt đơn mới
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          <div className="flex gap-3 rounded-control bg-warning-soft p-3 text-sm text-ink">
            <IconAlertTriangle className="mt-0.5 text-warning" />
            <p>
              <span className="font-semibold">Đã chuyển khoản cho đơn cũ?</span> Hãy chọn “Giữ đơn cũ” và liên hệ Quản trị viên, để tiền của bạn được ghi đúng đơn.
            </p>
          </div>
          <ButtonLink href={routes.myOrder(pendingOrder.code)} variant="ghost" size="md" className="self-start">
            Xem đơn cũ
          </ButtonLink>
        </div>
      </Dialog>
    </form>
  );
}

function MethodOption({ method, checked, onSelect }: { method: PaymentMethod; checked: boolean; onSelect: () => void }) {
  const manual = method === "manual";
  return (
    <label
      className={cx(
        "relative flex cursor-pointer items-start gap-3 rounded-card border p-4 transition-colors duration-150",
        checked ? "border-primary bg-primary-soft" : "border-line-strong bg-surface hover:border-primary",
      )}
    >
      <input type="radio" name="payment_method" value={method} checked={checked} onChange={onSelect} className="focus-ring mt-1 size-5 shrink-0 cursor-pointer accent-primary" />
      <span className="flex flex-1 flex-col gap-1">
        <span className="flex flex-wrap items-center gap-2 text-base font-semibold text-ink">
          {manual ? "Liên hệ Quản trị viên" : "Ví MoMo"}
          {checked ? (
            <span className="inline-flex items-center gap-1 text-sm font-semibold text-primary">
              <IconCheck size={16} /> Đã chọn
            </span>
          ) : null}
        </span>
        <span className="text-sm text-ink-soft">
          {manual
            ? "Quản trị viên sẽ liên hệ hướng dẫn thanh toán và kích hoạt khóa học cho bạn. Chưa phải trả tiền trên website."
            : "Thanh toán ngay trên ứng dụng MoMo, khóa học mở tự động khi giao dịch thành công."}
        </span>
      </span>
      <span aria-hidden="true" className={cx("flex size-10 shrink-0 items-center justify-center rounded-full", manual ? "bg-surface text-primary" : "bg-surface text-ink")}>
        {manual ? <IconMessageCircle /> : <span className="text-xs font-extrabold">MoMo</span>}
      </span>
    </label>
  );
}

function SubmitButton({ label, submitting, disabled, compact = false }: { label: string; submitting: boolean; disabled: boolean; compact?: boolean }) {
  return (
    <Button type="submit" size="lg" block={!compact} loading={submitting} loadingText="Đang gửi đơn…" disabled={disabled}>
      {label}
    </Button>
  );
}

function SubmitNote({ manual, zero, ttlHours }: { manual: boolean; zero: boolean; ttlHours: number }) {
  if (zero) return null;
  return (
    <p className="mt-3 text-sm text-ink-soft">
      {manual
        ? `Bạn chưa phải trả tiền trên website. Đơn được giữ ${ttlHours} giờ để Quản trị viên liên hệ; quá hạn chưa duyệt thì đơn tự huỷ.`
        : "Bạn sẽ được chuyển sang MoMo để hoàn tất thanh toán."}
    </p>
  );
}
