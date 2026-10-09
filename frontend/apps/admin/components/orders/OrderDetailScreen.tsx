"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  CopyButton,
  EmptyState,
  IconChevronLeft,
  IconMail,
  IconPhone,
  IconReceipt,
  IconRotateCcw,
  LoadingRegion,
  Skeleton,
  cx,
  formatPrice,
} from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { useSession } from "@/lib/auth/SessionProvider";
import { getOrder } from "@/lib/orders/api";
import { conflictNotice, detailErrorKind, detailErrorMessage, type ActionFailure, type Notice, type OrderAction } from "@/lib/orders/errors";
import { REVIEW_REASON_LABEL, SOON_HOURS, formatDay, formatWhen, logSentence, mailHref, methodLabel, telHref, warningText } from "@/lib/orders/format";
import { canViewOrders } from "@/lib/orders/permissions";
import { usePendingOrders } from "@/lib/orders/PendingOrders";
import { ORDERS_PATH } from "@/lib/orders/query";
import type { OrderDetail } from "@/lib/orders/schemas";
import { AdminStatusBadge, DeadlineCell } from "./OrderBadges";
import { InternalNotes } from "./InternalNotes";
import { OrderActions } from "./OrderActions";

type Load = { kind: "loading" } | { kind: "ready"; order: OrderDetail } | { kind: "forbidden" } | { kind: "not_found" } | { kind: "error"; message: string };

const section = "rounded-card border border-line bg-surface p-4 sm:p-5";
const h2 = "text-lg font-semibold text-ink";

const COURSE_STATUS_BADGE: Record<string, { label: string; tone: "warning" | "danger" | "neutral" } | undefined> = {
  unpublished: { label: "Ngừng bán", tone: "warning" },
  deleted: { label: "Đã xoá", tone: "danger" },
  draft: { label: "Bản nháp", tone: "neutral" },
};

/**
 * Chi tiết đơn quản trị (US-022 AC16–AC26). Gọi `GET /admin/orders/{code}` ĐÚNG MỘT LẦN khi mở (mỗi lần gọi ghi
 * audit `order.view_pii`): không poll, không lưu storage. Sau thao tác thành công dùng chính phản hồi 200; chỉ tải
 * lại khi gặp 409 để biết ai đã xử lý. Mọi văn bản người dùng nhập hiển thị dạng text (không HTML, không tự linkify).
 */
export function OrderDetailScreen({ code }: { code: string }) {
  const { state } = useSession();
  const pending = usePendingOrders();
  const allowed = state.kind === "staff" && canViewOrders(state.user);
  const [load, setLoad] = useState<Load>({ kind: "loading" });
  const [notice, setNotice] = useState<Notice | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  // Mốc "bây giờ" chụp lúc tải / sau thao tác / tải lại (không chạy từng giây).
  const [now, setNow] = useState(() => Date.now());
  const noticeRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!allowed) return;
    let stale = false;
    getOrder(code)
      .then((order) => !stale && setLoad({ kind: "ready", order }))
      .catch((err: unknown) => {
        if (stale) return;
        const k = detailErrorKind(err);
        setLoad(k === "forbidden" ? { kind: "forbidden" } : k === "not_found" ? { kind: "not_found" } : { kind: "error", message: detailErrorMessage(err) });
      });
    return () => {
      stale = true;
    };
  }, [code, allowed, reloadKey]);

  useEffect(() => {
    if (notice) noticeRef.current?.scrollIntoView?.({ block: "nearest", behavior: "smooth" });
  }, [notice]);

  const onUpdated = useCallback(
    (order: OrderDetail, n: Notice) => {
      setNow(Date.now());
      setLoad({ kind: "ready", order });
      setNotice(n);
      pending.refresh();
    },
    [pending],
  );

  /** 409: tải lại đơn (1 lần) rồi báo rõ ai/điều gì đã đổi. Không tự thử lại thao tác. */
  const onConflict = useCallback(
    async (action: OrderAction, failure: ActionFailure) => {
      if (failure.kind === "forbidden") return setLoad({ kind: "forbidden" });
      if (failure.kind === "not_found") return setLoad({ kind: "not_found" });
      try {
        const fresh = await getOrder(code);
        setNow(Date.now());
        setLoad({ kind: "ready", order: fresh });
        setNotice(conflictNotice(action, failure, fresh));
      } catch {
        setNotice(conflictNotice(action, failure, null));
      }
      pending.refresh();
    },
    [code, pending],
  );

  if (state.kind !== "staff") return null;
  if (!allowed || load.kind === "forbidden") return <ForbiddenView />;

  const back = (
    <Link href={ORDERS_PATH} className="focus-ring -ml-1 inline-flex min-h-11 items-center gap-1 rounded px-1 text-sm font-semibold text-primary sm:min-h-9">
      <IconChevronLeft size={16} /> Đơn hàng
    </Link>
  );

  if (load.kind === "not_found") {
    return (
      <div className="flex flex-col gap-3">
        {back}
        <EmptyState
          headingLevel="h1"
          icon={<IconReceipt size={32} />}
          title="Không tìm thấy đơn hàng"
          description={`Không có đơn nào mang mã ${code}.`}
          action={<ButtonLink href={ORDERS_PATH}>Về danh sách đơn</ButtonLink>}
        />
      </div>
    );
  }

  if (load.kind === "loading" || load.kind === "error") {
    return (
      <div className="flex flex-col gap-3">
        {back}
        <h1 className="text-title font-extrabold tracking-heading text-ink">
          Đơn <span className="num">{code}</span>
        </h1>
        {load.kind === "error" ? (
          <Alert
            tone="danger"
            title="Không tải được đơn hàng"
            action={
              <Button
                size="sm"
                variant="secondary"
                className="max-sm:h-11"
                leadingIcon={<IconRotateCcw size={16} />}
                onClick={() => {
                  setNow(Date.now());
                  setLoad({ kind: "loading" });
                  setReloadKey((n) => n + 1);
                }}
              >
                Tải lại
              </Button>
            }
          >
            {load.message}
          </Alert>
        ) : (
          <LoadingRegion label="Đang tải đơn hàng…" className="mt-2 grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div className="flex flex-col gap-4">
              <Skeleton className="h-36 w-full rounded-card" />
              <Skeleton className="h-56 w-full rounded-card" />
            </div>
            <Skeleton className="h-72 w-full rounded-card" />
          </LoadingRegion>
        )}
      </div>
    );
  }

  const o = load.order;
  const s = o.student;
  const warnings = o.approval.warnings.filter((w) => w.code !== "ACCOUNT_LOCKED" && w.code !== "ACCOUNT_DELETED");
  const showWarnings = o.status === "pending" || o.status === "cancelled";
  const cancelLog = o.status === "cancelled" ? [...o.status_logs].reverse().find((l) => l.to === "cancelled") : undefined;

  return (
    <div className="flex flex-col gap-3">
      {back}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex min-w-0 flex-col gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-title font-extrabold tracking-heading text-ink">
              Đơn <span className="num break-all">{o.code}</span>
            </h1>
            <CopyButton value={o.code} label="Sao chép mã" variant="ghost" size="sm" copiedMessage={`Đã sao chép mã đơn ${o.code}`} />
          </div>
          <div className="flex flex-wrap items-center gap-2 text-sm text-ink-soft">
            <AdminStatusBadge order={o} size="md" />
            <span className="num">
              {formatPrice(o.total)} · {o.items.length} khóa
            </span>
          </div>
        </div>
        <OrderActions order={o} onUpdated={onUpdated} onConflict={onConflict} />
      </div>

      <div className="mt-1 flex flex-col gap-3">
        {notice ? (
          <div ref={noticeRef} data-testid="order-notice">
            <Alert tone={notice.tone} title={notice.title} role={notice.tone === "success" ? "status" : "alert"}>
              {notice.body}
            </Alert>
          </div>
        ) : null}
        {o.needs_review ? (
          <Alert tone="danger" role="status" title="Cần xem lại">
            {o.needs_review_reasons.length ? (
              <ul className="list-disc pl-5">
                {o.needs_review_reasons.map((r) => (
                  <li key={r}>{REVIEW_REASON_LABEL[r] ?? r}</li>
                ))}
              </ul>
            ) : (
              "Đơn được gắn cờ để người có thẩm quyền xem lại."
            )}
          </Alert>
        ) : null}
        {s.is_deleted ? (
          <Alert tone="info" title="Tài khoản đã xoá">
            Không còn thông tin liên hệ. Đơn được giữ làm chứng từ; không gửi email cho tài khoản này.
          </Alert>
        ) : s.account_status === "locked" ? (
          <Alert tone="warning" title="Tài khoản học sinh đang bị khoá">
            Vẫn duyệt hoặc huỷ được; học sinh chỉ vào học được khi tài khoản được mở lại.
          </Alert>
        ) : null}
        {showWarnings
          ? warnings.map((w, i) => (
              <Alert
                key={`${w.code}-${w.course_id ?? i}`}
                tone={w.code === "COURSE_DELETED" ? "danger" : "warning"}
                title={w.code === "COURSE_UNPUBLISHED" ? "Có khóa đã ngừng bán" : w.code === "COURSE_DELETED" ? "Có khóa đã bị xoá" : w.code === "ALREADY_OWNED" ? "Học sinh đã sở hữu khóa này" : "Lưu ý"}
              >
                {warningText(w)}
              </Alert>
            ))
          : null}
      </div>

      <div className="mt-1 grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
        <div className="flex min-w-0 flex-col gap-4">
          <section aria-labelledby="hoc-sinh" className={section}>
            <h2 id="hoc-sinh" className={h2}>
              Học sinh
            </h2>
            {s.is_deleted ? (
              <p className="mt-2 text-sm text-ink-soft">Tài khoản đã xoá (ẩn danh hoá).</p>
            ) : (
              <>
                <p className="mt-2 break-words text-base font-semibold text-ink">{s.name}</p>
                <dl className="mt-3 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[110px_1fr]">
                  <dt className="text-ink-soft">Email</dt>
                  <dd className="flex flex-wrap items-center gap-2 break-all text-ink">
                    {s.email ?? "—"}
                    {s.email && !s.email_verified ? (
                      <Badge tone="warning" size="sm">
                        Chưa xác thực
                      </Badge>
                    ) : null}
                  </dd>
                  <dt className="text-ink-soft">Số điện thoại</dt>
                  <dd className="flex flex-wrap items-center gap-2 text-ink">
                    <span className="num">{s.phone ?? "Chưa có"}</span>
                    {s.phone && !s.phone_verified ? (
                      <Badge tone="warning" size="sm">
                        Chưa xác thực
                      </Badge>
                    ) : null}
                  </dd>
                </dl>
                <div className="mt-3 flex flex-wrap gap-2">
                  {s.phone ? (
                    <ButtonLink href={telHref(s.phone)} size="sm" variant="secondary" className="max-sm:h-11" leadingIcon={<IconPhone size={16} />}>
                      Gọi
                    </ButtonLink>
                  ) : null}
                  {s.email ? (
                    <ButtonLink href={mailHref(s.email, o.code)} size="sm" variant="secondary" className="max-sm:h-11" leadingIcon={<IconMail size={16} />}>
                      Gửi email
                    </ButtonLink>
                  ) : null}
                </div>
                <p className="mt-3 text-xs text-ink-soft">Lần xem thông tin liên hệ này đã được ghi vào nhật ký thao tác.</p>
              </>
            )}
            {o.customer_note ? (
              <div className="mt-4 border-t border-line pt-4">
                <h3 className="text-sm font-semibold text-ink">Ghi chú của học sinh</h3>
                <p data-testid="customer-note" className="mt-1 whitespace-pre-line break-words rounded-control bg-sunken p-3 text-sm text-ink">
                  {o.customer_note}
                </p>
              </div>
            ) : null}
          </section>

          <section aria-labelledby="khoa-hoc" className={section}>
            <h2 id="khoa-hoc" className={h2}>
              Khóa học trong đơn <span className="num font-normal text-ink-soft">({o.items.length})</span>
            </h2>
            <div className="mt-3 overflow-x-auto">
              <table className="w-full border-collapse text-left text-sm">
                <caption className="sr-only">Khóa học, giá chốt lúc đặt đơn</caption>
                <thead>
                  <tr className="text-ink-soft">
                    <th scope="col" className="py-2 pr-3 font-semibold">
                      Khóa học
                    </th>
                    <th scope="col" className="py-2 pr-3 text-right font-semibold">
                      Giá chốt
                    </th>
                    <th scope="col" className="hidden py-2 pr-3 text-right font-semibold sm:table-cell">
                      Giảm
                    </th>
                    <th scope="col" className="py-2 text-right font-semibold">
                      Thành tiền
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {o.items.map((i) => {
                    const badge = COURSE_STATUS_BADGE[i.course_status];
                    return (
                      <tr key={i.course_id} className="border-t border-line align-top">
                        <td className="py-2.5 pr-3">
                          <p className="break-words font-medium text-ink">{i.title}</p>
                          <p className="flex flex-wrap items-center gap-1.5 text-xs text-ink-soft">
                            {badge ? (
                              <Badge tone={badge.tone} size="sm">
                                {badge.label}
                              </Badge>
                            ) : null}
                            {i.current_price !== null && i.current_price !== i.unit_price ? <span className="num">Giá hiện tại {formatPrice(i.current_price)}</span> : null}
                          </p>
                        </td>
                        <td className="num py-2.5 pr-3 text-right">{formatPrice(i.unit_price)}</td>
                        <td className="num hidden py-2.5 pr-3 text-right sm:table-cell">{i.discount_amount ? `−${formatPrice(i.discount_amount)}` : "—"}</td>
                        <td className="num py-2.5 text-right font-semibold">{formatPrice(i.final_amount)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <dl className="num mt-3 flex flex-col gap-1.5 border-t border-line pt-3 text-sm">
              <div className="flex justify-between gap-4">
                <dt className="text-ink-soft">Tạm tính</dt>
                <dd>{formatPrice(o.subtotal)}</dd>
              </div>
              {o.coupon_code || o.discount > 0 ? (
                <div className="flex justify-between gap-4">
                  <dt className="text-ink-soft">
                    Mã giảm giá {o.coupon_code ? <span className="font-semibold text-ink">{o.coupon_code}</span> : null}
                  </dt>
                  <dd className="text-success">−{formatPrice(o.discount)}</dd>
                </div>
              ) : null}
              <div className="flex items-baseline justify-between gap-4">
                <dt className="font-semibold text-ink">Tổng cần thu</dt>
                <dd className="text-heading font-extrabold tracking-heading text-ink">{formatPrice(o.total)}</dd>
              </div>
            </dl>
          </section>

          <section aria-labelledby="lich-su" className={section}>
            <h2 id="lich-su" className={h2}>
              Lịch sử trạng thái
            </h2>
            <ol className="mt-3 flex flex-col" data-testid="status-logs">
              {o.status_logs.map((l, i, all) => (
                <li key={`${l.created_at}-${l.to}-${i}`} className="relative flex gap-3 pb-4 last:pb-0">
                  <span aria-hidden="true" className="relative flex w-3 justify-center">
                    <span className={cx("mt-1.5 size-2.5 rounded-full", l.to === "paid" ? "bg-success" : l.to === "cancelled" ? "bg-ink-soft" : l.to === "refunded" ? "bg-info" : "bg-warning")} />
                    {i < all.length - 1 ? <span className="absolute bottom-[-0.25rem] top-4 w-px bg-line-strong" /> : null}
                  </span>
                  <div className="min-w-0 flex-1 text-sm">
                    <p className="font-semibold text-ink">{logSentence(l)}</p>
                    {l === cancelLog && o.cancel_reason ? <p className="mt-1 whitespace-pre-line break-words text-ink">Lý do gửi học sinh: {o.cancel_reason}</p> : null}
                  </div>
                </li>
              ))}
            </ol>
          </section>

          {o.attempts.length ? (
            <section aria-labelledby="cong" className={section}>
              <h2 id="cong" className={h2}>
                Lần thanh toán qua cổng
              </h2>
              <ul className="mt-3 flex flex-col divide-y divide-line text-sm">
                {o.attempts.map((a) => (
                  <li key={a.id} className="flex flex-col gap-0.5 py-2 first:pt-0">
                    <p className="num font-medium text-ink">
                      {a.gateway} · {formatPrice(a.amount)} · {a.status}
                    </p>
                    <p className="num text-ink-soft">{formatWhen(a.created_at)}</p>
                    {a.result_message ? <p className="whitespace-pre-line break-words text-ink">{a.result_message}</p> : null}
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
        </div>

        <aside aria-label="Thông tin đơn và ghi chú nội bộ" className="flex flex-col gap-4 lg:sticky lg:top-20">
          <section aria-labelledby="thong-tin" className={section}>
            <h2 id="thong-tin" className={h2}>
              Thông tin đơn
            </h2>
            <dl className="mt-3 grid grid-cols-[110px_1fr] gap-x-3 gap-y-2.5 text-sm">
              <dt className="text-ink-soft">Phương thức</dt>
              <dd className="text-ink">{methodLabel(o.payment_method)}</dd>
              <dt className="text-ink-soft">Đặt lúc</dt>
              <dd className="num text-ink">{formatWhen(o.created_at)}</dd>
              {o.status === "pending" && o.expires_at ? (
                <>
                  <dt className="text-ink-soft">Hạn chờ</dt>
                  <dd className="flex flex-col gap-1 text-ink">
                    <span className="num">{formatWhen(o.expires_at)}</span>
                    <DeadlineCell expiresAt={o.expires_at} expiringSoon={o.payment_method === "manual" && new Date(o.expires_at).getTime() - now < SOON_HOURS * 3_600_000} now={now} />
                  </dd>
                </>
              ) : null}
              {o.status === "cancelled" && o.cancelled_at ? (
                <>
                  <dt className="text-ink-soft">Huỷ lúc</dt>
                  <dd className="num text-ink">{formatWhen(o.cancelled_at)}</dd>
                </>
              ) : null}
              {o.status === "cancelled" && o.cancel_reason ? (
                <>
                  <dt className="text-ink-soft">Lý do gửi HS</dt>
                  <dd className="whitespace-pre-line break-words text-ink">{o.cancel_reason}</dd>
                </>
              ) : null}
              {o.approval.approval_window_until && o.status === "cancelled" ? (
                <>
                  <dt className="text-ink-soft">Duyệt muộn tới</dt>
                  <dd className="num text-ink">{formatDay(o.approval.approval_window_until)}</dd>
                </>
              ) : null}
              {o.confirmed_by ? (
                <>
                  <dt className="text-ink-soft">Duyệt bởi</dt>
                  <dd className="text-ink" data-testid="confirmed-by">
                    {o.confirmed_by.name}
                    {o.paid_at ? <span className="num block text-ink-soft">lúc {formatWhen(o.paid_at)}</span> : null}
                  </dd>
                </>
              ) : o.paid_at ? (
                <>
                  <dt className="text-ink-soft">Thanh toán lúc</dt>
                  <dd className="num text-ink">{formatWhen(o.paid_at)}</dd>
                </>
              ) : null}
              {o.payment_reference ? (
                <>
                  <dt className="text-ink-soft">Mã giao dịch</dt>
                  <dd className="break-all font-mono text-xs text-ink">{o.payment_reference}</dd>
                </>
              ) : null}
              {o.refunded_at ? (
                <>
                  <dt className="text-ink-soft">Hoàn tiền</dt>
                  <dd className="text-ink">
                    {o.refunded_by ? `${o.refunded_by.name} ` : ""}
                    <span className="num text-ink-soft">lúc {formatWhen(o.refunded_at)}</span>
                    {o.refund_note ? <span className="mt-0.5 block whitespace-pre-line break-words">{o.refund_note}</span> : null}
                  </dd>
                </>
              ) : null}
            </dl>
          </section>

          <section aria-labelledby="ghi-chu-noi-bo" className={section}>
            <div className="flex flex-wrap items-center gap-2">
              <h2 id="ghi-chu-noi-bo" className={h2}>
                Ghi chú nội bộ
              </h2>
              <Badge size="sm">Học sinh không thấy</Badge>
            </div>
            <div className="mt-3">
              <InternalNotes
                code={o.code}
                notes={o.notes}
                onAdded={(note) => setLoad((prev) => (prev.kind === "ready" ? { kind: "ready", order: { ...prev.order, notes: [...prev.order.notes, note] } } : prev))}
              />
            </div>
          </section>
        </aside>
      </div>
    </div>
  );
}
