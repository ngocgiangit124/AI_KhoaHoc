"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useId, useRef, useState, type FormEvent } from "react";
import {
  Alert,
  Button,
  ButtonLink,
  Dialog,
  Field,
  IconAlertTriangle,
  IconCheck,
  IconChevronLeft,
  IconMessageCircle,
  Sheet,
  Skeleton,
  Textarea,
  cx,
  formatDateTime,
  formatPrice,
} from "@vitaminvui/ui/v2";
import { RequireUser, PageSkeletonRegion } from "@/components/my/RequireUser";
import { useAuth } from "@/lib/auth/AuthProvider";
import { fetchCheckoutPreview, submitCheckout } from "@/lib/orders/api";
import { changeReasonText, classifyCheckoutError, limitResetText } from "@/lib/orders/errors";
import { methodLabel } from "@/lib/orders/format";
import { NOTE_MAX, noteForRequest, validateNote } from "@/lib/orders/note";
import type { CheckoutPreview, PaymentMethodOption, PendingOrderConflict } from "@/lib/orders/schemas";
import { usePaymentConfig } from "@/lib/orders/usePaymentConfig";
import { routes } from "@/lib/routes";
import { fromCartItem, OrderItemRows, PricingSummary } from "./OrderParts";
import { OrdersNotice } from "./OrdersNotice";
import { useOrderLoad } from "./useOrderLoad";

type FormAlert =
  | { kind: "changed"; text: string }
  | { kind: "limit"; message: string; reset: string }
  | { kind: "disabled"; message: string }
  | { kind: "error"; message: string };

function CheckoutSkeleton() {
  return (
    <PageSkeletonRegion label="Đang tải trang thanh toán…">
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <Sheet padding="md" className="flex flex-col gap-4">
          <Skeleton className="h-7 w-56" />
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-16 w-full" />
        </Sheet>
        <Sheet padding="md" className="flex flex-col gap-3">
          <Skeleton className="h-6 w-32" />
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-13 w-full" />
        </Sheet>
      </div>
    </PageSkeletonRegion>
  );
}

/**
 * Form thanh toán (US-022 AC2–AC8): chọn phương thức (radio card render từ `payment_methods` của server), ghi chú cho Quản trị viên,
 * "Gửi đơn". Chặn bấm kép bằng ref (mất mạng bấm lại an toàn: server dùng lại đơn đang chờ cùng nội dung).
 * FW3-MoMo (chưa làm): luồng `payment.pay_url` + kiểm host allowlist khi `payment_methods` có cổng.
 */
export function CheckoutForm({ initial }: { initial: CheckoutPreview }) {
  const router = useRouter();
  const { state: auth } = useAuth();
  const [preview, setPreview] = useState(initial);
  const [method, setMethod] = useState<string>(() => defaultMethod(initial));
  const [note, setNote] = useState("");
  const [noteError, setNoteError] = useState<string>();
  const [submitting, setSubmitting] = useState(false);
  const [alert, setAlert] = useState<FormAlert | null>(null);
  const [conflict, setConflict] = useState<PendingOrderConflict | null>(null);
  const [replacing, setReplacing] = useState(false);
  const lock = useRef(false);
  const alertRef = useRef<HTMLDivElement>(null);
  const noteId = useId();

  const user = auth.status === "user" ? auth.user : null;
  const items = preview.items.map(fromCartItem);
  const pricing = preview.pricing;
  const zero = pricing.total === 0;
  const methods = preview.payment_methods;
  const selected = methods.find((m) => m.code === method) ?? methods[0];
  const manual = !zero && selected?.code === "manual";
  const noMethod = !zero && methods.length === 0;
  const disabled = !preview.can_checkout || noMethod || alert?.kind === "disabled";
  const submitLabel = zero ? "Hoàn tất đăng ký" : manual ? "Gửi đơn" : "Đặt mua";
  const paymentCfg = usePaymentConfig();
  const ttl = paymentCfg.status === "ready" ? paymentCfg.config.manual_payment?.pending_ttl_hours : undefined;

  useEffect(() => {
    // Giỏ không còn khóa nào hợp lệ (vào thẳng /thanh-toan, hoặc đã xoá hết) -> về giỏ (AC3).
    if (preview.items.length === 0) router.replace(routes.cart);
  }, [preview.items.length, router]);

  function focusAlert() {
    requestAnimationFrame(() => alertRef.current?.focus());
  }

  async function submit(replacePending = false) {
    if (lock.current) return;
    const invalid = manual ? validateNote(note) : null;
    if (invalid) {
      setNoteError(invalid);
      document.getElementById(noteId)?.focus();
      return;
    }
    lock.current = true;
    setSubmitting(true);
    setAlert(null);
    try {
      const customerNote = manual ? noteForRequest(note) : undefined;
      const result = await submitCheckout({
        expected_total: pricing.total,
        ...(zero || !selected ? {} : { payment_method: selected.code }),
        ...(customerNote ? { customer_note: customerNote } : {}),
        ...(replacePending ? { replace_pending: true } : {}),
      });
      // Giữ khoá nút trong lúc chuyển trang để không gửi thêm lần nữa.
      router.push(result.reused ? `${routes.orderSent(result.order_code)}?dung-lai=1` : routes.orderSent(result.order_code));
      return;
    } catch (err) {
      const failure = classifyCheckoutError(err);
      setConflict(null);
      switch (failure.kind) {
        case "pending_exists":
          setConflict(failure.conflict);
          break;
        case "changed":
          if (failure.preview) {
            setPreview(failure.preview);
            setMethod((m) => (failure.preview?.payment_methods.some((x) => x.code === m) ? m : defaultMethod(failure.preview as CheckoutPreview)));
          }
          setAlert({ kind: "changed", text: changeReasonText(failure.reasons) });
          focusAlert();
          break;
        case "limit":
          setAlert({ kind: "limit", message: failure.message, reset: limitResetText(failure.resetsAt) });
          focusAlert();
          break;
        case "disabled":
          setAlert({ kind: "disabled", message: failure.message });
          focusAlert();
          break;
        case "cart_empty":
          router.replace(routes.cart);
          return;
        case "not_verified":
          router.push(routes.needVerify);
          return;
        case "field":
          if (failure.field === "customer_note") {
            setNoteError(failure.message);
            document.getElementById(noteId)?.focus();
          } else {
            setAlert({ kind: "error", message: failure.message });
            focusAlert();
          }
          break;
        default:
          setAlert({ kind: "error", message: failure.message });
          focusAlert();
      }
    }
    lock.current = false;
    setSubmitting(false);
    setReplacing(false);
  }

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    void submit(false);
  }

  return (
    <form onSubmit={onSubmit} noValidate className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
      <div className="flex min-w-0 flex-col gap-4">
        <div ref={alertRef} tabIndex={-1} className="focus-ring rounded-card empty:hidden">
          {alert?.kind === "changed" ? (
            <Alert tone="warning" title="Giỏ hàng vừa thay đổi" role="alert">
              {alert.text} Tổng mới là <strong className="num">{formatPrice(pricing.total)}</strong>. Kiểm tra lại rồi bấm “{submitLabel}”.
            </Alert>
          ) : alert?.kind === "limit" ? (
            <Alert tone="danger" title="Bạn đã đặt quá nhiều đơn hôm nay" role="alert">
              {alert.message} {alert.reset}
            </Alert>
          ) : alert?.kind === "disabled" ? (
            <Alert tone="info" title="Đặt mua đang tạm đóng" role="alert">
              {alert.message} Giỏ hàng của bạn vẫn được giữ; bạn có thể quay lại sau.
            </Alert>
          ) : alert?.kind === "error" ? (
            <Alert tone="danger" title="Chưa gửi được đơn" role="alert">
              {alert.message}
            </Alert>
          ) : null}
        </div>

        {preview.pending_order ? (
          <Alert
            tone="info"
            title={`Bạn đang có đơn ${preview.pending_order.code} chờ duyệt`}
            action={
              <ButtonLink href={routes.myOrder(preview.pending_order.code)} variant="secondary" size="sm">
                Xem đơn
              </ButtonLink>
            }
          >
            Nếu bạn đã chuyển khoản cho đơn đó, đừng đặt đơn mới — hãy chờ Quản trị viên xác nhận. Đặt đơn khác nội dung sẽ huỷ đơn cũ.
          </Alert>
        ) : null}

        {preview.removed_items.length > 0 ? (
          <Alert tone="warning" title="Có khóa không còn bán nên không vào đơn">
            {preview.removed_items.map((i) => i.title).join("; ")}
          </Alert>
        ) : null}

        {preview.notices
          .filter((n) => n.code !== "PAYMENT_DISABLED")
          .map((n) => (
            <Alert key={n.code} tone="warning">
              {n.message}
            </Alert>
          ))}
        {noMethod && preview.items.length > 0 ? (
          <Alert tone="info" title="Đặt mua đang tạm đóng">
            Chưa có phương thức thanh toán nào đang mở. Giỏ hàng của bạn vẫn được giữ; bạn có thể quay lại sau.
          </Alert>
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
          <div className="mt-2">
            <OrderItemRows items={items} />
          </div>
        </Sheet>

        {zero ? (
          <Alert tone="info" title="Đơn này được miễn phí nhờ mã giảm giá">
            Không cần thanh toán. Bấm “Hoàn tất đăng ký” để vào học ngay.
          </Alert>
        ) : methods.length > 0 ? (
          <Sheet as="section" padding="md">
            <fieldset>
              <legend className="text-heading font-extrabold tracking-heading text-ink">Phương thức thanh toán</legend>
              <div className="mt-4 flex flex-col gap-3">
                {methods.map((m) => (
                  <MethodOption key={m.code} option={m} checked={selected?.code === m.code} onSelect={() => setMethod(m.code)} />
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
                  <ul className="mt-1 break-all text-base text-ink">
                    <li>Email: {user?.email ?? "—"}</li>
                    <li className="num">Số điện thoại: {user?.phone ?? "—"}</li>
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
                  aside={
                    <span className={cx("num", note.length > NOTE_MAX ? "text-danger" : "text-ink-soft")}>
                      {note.length}/{NOTE_MAX}
                    </span>
                  }
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
        ) : null}
      </div>

      <aside aria-label="Tổng tiền và gửi đơn" className="flex flex-col gap-4 lg:sticky lg:top-24">
        <Sheet padding="md">
          <h2 className="text-lg font-semibold text-ink">Tổng tiền</h2>
          <PricingSummary pricing={pricing} couponCode={preview.coupon?.code} className="mt-3" />
          <div className="mt-5 hidden lg:block">
            <SubmitButton label={submitLabel} submitting={submitting} disabled={disabled} />
          </div>
          <SubmitNote manual={manual} zero={zero} ttlHours={ttl} />
        </Sheet>
      </aside>

      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3 shadow-overlay lg:hidden">
        <div className="mx-auto flex max-w-2xl items-center gap-3">
          <div className="num flex-1">
            <p className="text-sm text-ink-soft">Tổng cộng</p>
            <p className="text-lg font-extrabold text-ink">{pricing.total === 0 ? "0đ" : formatPrice(pricing.total)}</p>
          </div>
          <SubmitButton label={submitLabel} submitting={submitting} disabled={disabled} compact />
        </div>
      </div>

      <Dialog
        open={conflict !== null}
        onClose={() => setConflict(null)}
        dismissible={!replacing}
        title="Bạn đang có đơn chờ duyệt"
        description={
          conflict ? (
            <>
              Đơn <strong className="num text-ink">{conflict.order_code}</strong> ({conflict.items_count} khóa, {formatPrice(conflict.total)}), đặt lúc{" "}
              {formatDateTime(conflict.created_at)}, đang chờ Quản trị viên duyệt. Đặt đơn mới sẽ huỷ đơn cũ.
            </>
          ) : null
        }
        size="md"
        footer={
          <>
            <Button variant="secondary" autoFocus disabled={replacing} onClick={() => conflict && router.push(routes.myOrder(conflict.order_code))}>
              Giữ đơn cũ
            </Button>
            <Button
              loading={replacing}
              loadingText="Đang gửi đơn mới…"
              onClick={() => {
                setReplacing(true);
                void submit(true);
              }}
            >
              Huỷ đơn cũ, đặt đơn mới
            </Button>
          </>
        }
      >
        <div className="flex gap-3 rounded-control bg-warning-soft p-3 text-sm text-ink">
          <IconAlertTriangle className="mt-0.5 shrink-0 text-warning" />
          <p>
            <span className="font-semibold">Đã chuyển khoản cho đơn cũ?</span> Hãy chọn “Giữ đơn cũ” và liên hệ Quản trị viên, để tiền của bạn được ghi đúng đơn.
          </p>
        </div>
      </Dialog>
    </form>
  );
}

function defaultMethod(p: CheckoutPreview): string {
  const first = p.payment_methods[0]?.code ?? "manual";
  return p.default_payment_method && p.payment_methods.some((m) => m.code === p.default_payment_method) ? p.default_payment_method : first;
}

function MethodOption({ option, checked, onSelect }: { option: PaymentMethodOption; checked: boolean; onSelect: () => void }) {
  const manual = option.code === "manual";
  return (
    <label
      className={cx(
        "relative flex cursor-pointer items-start gap-3 rounded-card border p-4 transition-colors duration-150",
        checked ? "border-primary bg-primary-soft" : "border-line-strong bg-surface hover:border-primary",
      )}
    >
      <input type="radio" name="payment_method" value={option.code} checked={checked} onChange={onSelect} className="focus-ring mt-1 size-5 shrink-0 cursor-pointer accent-primary" />
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="flex flex-wrap items-center gap-2 text-base font-semibold text-ink">
          {option.label || methodLabel(option.code)}
          {checked ? (
            <span className="inline-flex items-center gap-1 text-sm font-semibold text-primary">
              <IconCheck size={16} /> Đã chọn
            </span>
          ) : null}
        </span>
        {option.description ? <span className="text-sm text-ink-soft">{option.description}</span> : null}
        {manual ? <span className="text-sm text-ink-soft">Chưa phải trả tiền trên website.</span> : null}
      </span>
      <span aria-hidden="true" className={cx("flex size-10 shrink-0 items-center justify-center rounded-full bg-surface", manual ? "text-primary" : "text-ink")}>
        {manual ? <IconMessageCircle /> : <span className="text-xs font-extrabold">{option.code.slice(0, 4).toUpperCase()}</span>}
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

function SubmitNote({ manual, zero, ttlHours }: { manual: boolean; zero: boolean; ttlHours: number | undefined }) {
  if (zero) return null;
  if (!manual) return null;
  return (
    <p className="mt-3 text-sm text-ink-soft">
      Bạn chưa phải trả tiền trên website.{" "}
      {ttlHours ? `Đơn được giữ ${ttlHours} giờ để Quản trị viên liên hệ; quá hạn chưa duyệt thì đơn tự huỷ.` : "Quá hạn chưa duyệt thì đơn tự huỷ."}
    </p>
  );
}

function CheckoutContent() {
  const [state, retry] = useOrderLoad(fetchCheckoutPreview, 0);
  const router = useRouter();
  const empty = state.status === "ok" && state.data.items.length === 0;
  useEffect(() => {
    if (empty) router.replace(routes.cart);
  }, [empty, router]);
  if (state.status === "loading" || empty) return <CheckoutSkeleton />;
  if (state.status === "failed") return <OrdersNotice kind={state.kind} onRetry={retry} what="checkout" />;
  return <CheckoutForm initial={state.data} />;
}

/** `/thanh-toan` (US-022): `GET /checkout/preview` rồi `POST /checkout`. Khách -> đăng nhập; chưa xác thực -> thông báo + liên kết xác thực. */
export function CheckoutScreen() {
  return (
    <div className="mx-auto w-full max-w-6xl px-4 pb-28 pt-4 sm:px-6 lg:pb-14">
      <Link href={routes.cart} className="focus-ring -ml-1 inline-flex min-h-11 items-center gap-1 rounded px-1 text-sm font-semibold text-primary">
        <IconChevronLeft size={18} /> Quay lại giỏ hàng
      </Link>
      <h1 className="mt-1 text-title font-extrabold tracking-heading text-ink md:text-title-lg">Thanh toán</h1>
      <div className="mt-6">
        <RequireUser next={routes.checkout} skeleton={<CheckoutSkeleton />}>
          <CheckoutContent />
        </RequireUser>
      </div>
    </div>
  );
}
