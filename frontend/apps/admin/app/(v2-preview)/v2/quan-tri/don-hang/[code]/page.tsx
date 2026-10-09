import Link from "next/link";
import { notFound } from "next/navigation";
import {
  Alert,
  Badge,
  ButtonLink,
  CopyButton,
  IconChevronLeft,
  IconMail,
  IconPhone,
  IconRotateCcw,
  LoadingRegion,
  Skeleton,
  cx,
  formatDateTime,
  formatPrice,
} from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { ForbiddenView } from "@/components/v2/ForbiddenView";
import { AdminStatusBadge, DeadlineCell, adminStatus, methodLabel } from "@/components/v2/orders/OrderBadges";
import { InternalNotes } from "@/components/v2/orders/InternalNotes";
import { OrderActions } from "@/components/v2/orders/OrderActions";
import { STAFF } from "@/lib/mock/v2/data";
import { PREVIEW_NOW, REVIEW_REASON_LABEL, SAMPLE_LATE_WARNINGS, findOrder, type AdminOrderDetail, type StatusLog } from "@/lib/mock/v2/orders";

export const dynamic = "force-dynamic";

const STATES = [
  { label: "Mặc định" },
  { key: "dang-tai", label: "Đang tải" },
  { key: "loi", label: "Lỗi tải" },
];

/** Đơn mẫu + tình huống lỗi (bản xem trước). `q` = biến thể `trang-thai` cần cho tình huống đó. */
const SAMPLES: Array<{ code: string; label: string; q?: string }> = [
  { code: "VV2610077K3QPM", label: "Chờ duyệt (có mã, ghi chú HS)" },
  { code: "VV261005H2KD8N", label: "Sắp hết hạn + khóa ngừng bán" },
  { code: "VV261006P9MM3C", label: "Tài khoản bị khoá, không SĐT" },
  { code: "VV2610023M8RTA", label: "Đã duyệt" },
  { code: "VV260916T2WQ6F", label: "Duyệt muộn, cần xem lại" },
  { code: "VV260920B7HX2K", label: "Tự huỷ (hết hạn), duyệt muộn được" },
  { code: "VV260925Q1ZD4H", label: "QTV huỷ (có lý do)" },
  { code: "VV260825M3TQ9D", label: "HS tự huỷ, quá 30 ngày" },
  { code: "VV260830F5PB7N", label: "Đã hoàn tiền" },
  { code: "VV260710X8NV4R", label: "Tài khoản đã xoá" },
  { code: "VV2610077K3QPM", q: "xung-dot", label: "Bấm Duyệt → 409: HS vừa tự huỷ" },
  { code: "VV2610073R8XNE", q: "da-xu-ly", label: "Bấm Duyệt → 409: người khác đã duyệt" },
  { code: "VV260920B7HX2K", q: "canh-bao-muon", label: "Duyệt muộn có cảnh báo" },
  { code: "VV260925Q1ZD4H", q: "khoa-da-xoa", label: "Duyệt muộn → 409: khóa đã xoá" },
];

function logText(l: StatusLog): string {
  if (l.from === null) return "Học sinh đặt đơn (Liên hệ Quản trị viên)";
  if (l.to === "paid") return l.from === "cancelled" ? "Duyệt muộn: đã nhận tiền" : "Duyệt: đã nhận tiền";
  if (l.to === "refunded") return "Đánh dấu hoàn tiền";
  return adminStatus(l.to, l.reason).label;
}
function actorText(l: StatusLog): string {
  if (l.actor_type === "system") return "Hệ thống";
  if (l.actor_type === "staff") return l.actor_name ?? "Quản trị viên";
  return l.actor_name ? `Học sinh ${l.actor_name}` : "Học sinh";
}

/** Áp kết quả giả lập sau thao tác (bản xem trước). TODO(dev): dữ liệu thật sau router.refresh(). */
function applyOutcome(o: AdminOrderDetail, outcome: string | undefined, me: { id: number; name: string }): { order: AdminOrderDetail; alert: React.ReactNode } {
  const log = (to: StatusLog["to"], reason: StatusLog["reason"], actor: StatusLog["actor_type"], name: string | null, at: string, detail?: string): StatusLog => ({
    from: o.status, to, reason, actor_type: actor, actor_name: name, created_at: at, detail,
  });
  switch (outcome) {
    case "da-duyet":
      return {
        order: { ...o, status: "paid", status_reason: "manual_confirmed", paid_at: PREVIEW_NOW, confirmed_by: me, payment_reference: "FT26281987654 " + o.code, status_logs: [...o.status_logs, log("paid", "manual_confirmed", "staff", me.name, PREVIEW_NOW, "Mã giao dịch: FT26281987654 " + o.code)] },
        alert: <Alert tone="success" title="Đã duyệt đơn">Học sinh đã được mở khóa học và nhận email xác nhận.</Alert>,
      };
    case "duyet-muon":
      return {
        order: { ...o, status: "paid", status_reason: "manual_confirmed", needs_review: true, needs_review_reasons: ["late_payment"], paid_at: PREVIEW_NOW, confirmed_by: me, status_logs: [...o.status_logs, log("paid", "manual_confirmed", "staff", me.name, PREVIEW_NOW, "Duyệt muộn")] },
        alert: <Alert tone="success" title="Đã duyệt muộn">Học sinh đã được mở khóa học. Đơn được gắn cờ “Cần xem lại”.</Alert>,
      };
    case "da-huy": {
      const reason = "Quản trị viên đã gọi điện và nhắn Zalo nhưng chưa liên lạc được với bạn. Khi sẵn sàng, bạn có thể đặt lại đơn từ giỏ hàng.";
      return {
        order: { ...o, status: "cancelled", status_reason: "admin_cancelled", cancelled_at: PREVIEW_NOW, cancel_reason_public: reason, approval_window_until: "2026-11-07T20:00:00+07:00", status_logs: [...o.status_logs, log("cancelled", "admin_cancelled", "staff", me.name, PREVIEW_NOW, reason)] },
        alert: <Alert tone="success" title="Đã huỷ đơn">Học sinh nhận email kèm lý do. Lượt mã giảm giá đã được nhả.</Alert>,
      };
    }
    case "hoan-tien":
      return {
        order: { ...o, status: "refunded", status_reason: null, status_logs: [...o.status_logs, log("refunded", null, "staff", me.name, PREVIEW_NOW)] },
        alert: <Alert tone="success" title="Đã đánh dấu hoàn tiền">Quyền học các khóa trong đơn đã được thu hồi.</Alert>,
      };
    case "xung-dot":
      return {
        order: { ...o, status: "cancelled", status_reason: "user_cancelled", cancelled_at: "2026-10-08T19:59:00+07:00", approval_window_until: "2026-11-07T19:59:00+07:00", status_logs: [...o.status_logs, log("cancelled", "user_cancelled", "user", o.student.name, "2026-10-08T19:59:00+07:00")] },
        alert: (
          <Alert tone="warning" title="Chưa duyệt: đơn vừa đổi trạng thái" role="alert">
            Học sinh đã tự huỷ đơn lúc 19:59 trước khi bạn bấm duyệt. Trang đã tải lại trạng thái mới. Nếu bạn đã nhận tiền cho đơn này, dùng “Duyệt muộn”.
          </Alert>
        ),
      };
    case "da-xu-ly": {
      const mai = { id: 2, name: "Đỗ Thị Mai" };
      return {
        order: { ...o, status: "paid", status_reason: "manual_confirmed", paid_at: "2026-10-08T19:59:00+07:00", confirmed_by: mai, status_logs: [...o.status_logs, log("paid", "manual_confirmed", "staff", mai.name, "2026-10-08T19:59:00+07:00")] },
        alert: (
          <Alert tone="info" title="Đơn đã được người khác xử lý" role="alert">
            Đỗ Thị Mai đã duyệt đơn này lúc 19:59. Không cần thao tác thêm; học sinh chỉ được mở khóa một lần.
          </Alert>
        ),
      };
    }
    case "khoa-da-xoa":
      return {
        order: o,
        alert: (
          <Alert tone="danger" title="Không duyệt được: có khóa đã bị xoá" role="alert">
            Khóa “{o.items[0]?.title}” đã bị xoá nên không thể mở cho học sinh. Đơn giữ nguyên trạng thái. Nếu học sinh đã chuyển tiền, hãy hoàn tiền ngoài hệ thống và ghi chú lại.
          </Alert>
        ),
      };
    default:
      return { order: o, alert: null };
  }
}

/**
 * Chi tiết đơn quản trị (US-010 §2.2 + US-022 AC16–AC26). Email/SĐT ĐẦY ĐỦ (mỗi lần mở ghi audit `order.view_pii`).
 * Không bao giờ hiện thông tin phụ huynh.
 * TODO(dev): GET /admin/orders/{code}; 404 → trang không tìm thấy; GV → 403.
 */
export default async function OrderDetailPreview({ params, searchParams }: PageProps<"/v2/quan-tri/don-hang/[code]">) {
  const { code } = await params;
  const sp = await searchParams;
  const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
  const role = roleFrom(sp["vai-tro"]);
  const state = one(sp["trang-thai"]);
  const outcome = one(sp["ket-qua"]);
  const found = findOrder(code);
  if (!found) notFound();

  const me = { id: STAFF[role].id, name: STAFF[role].name };
  const { order: o, alert } = applyOutcome(found, outcome, me);
  const base = `/v2/quan-tri/don-hang/${code}`;
  const roleQ = role === "admin" ? "" : `vai-tro=${role}`;
  const withQ = (...parts: string[]) => {
    const q = [roleQ, ...parts].filter(Boolean).join("&");
    return q ? `${base}?${q}` : base;
  };
  const listHref = `/v2/quan-tri/don-hang${roleQ ? `?${roleQ}` : ""}`;
  const windowOpen = !!o.approval_window_until && o.approval_window_until > PREVIEW_NOW && o.status === "cancelled" && o.status_reason !== "account_deleted";
  const unpublished = o.items.filter((i) => i.course_status === "unpublished").map((i) => i.title);
  const cancelLabel = o.status === "cancelled" ? adminStatus(o.status, o.status_reason).label : null;
  const s = o.student_detail;

  const section = "rounded-card border border-line bg-surface p-4 sm:p-5";
  const h2 = "text-lg font-semibold text-ink";

  let content: React.ReactNode;
  if (state === "dang-tai") {
    content = (
      <LoadingRegion label="Đang tải đơn hàng…" className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="flex flex-col gap-4">
          <Skeleton className="h-36 w-full rounded-card" />
          <Skeleton className="h-56 w-full rounded-card" />
        </div>
        <Skeleton className="h-72 w-full rounded-card" />
      </LoadingRegion>
    );
  } else if (state === "loi") {
    content = (
      <Alert
        className="mt-4"
        tone="danger"
        title="Không tải được đơn hàng"
        action={
          <ButtonLink href={withQ()} size="sm" variant="secondary" leadingIcon={<IconRotateCcw size={16} />}>
            Tải lại
          </ButtonLink>
        }
      >
        Kiểm tra kết nối rồi tải lại trang.
      </Alert>
    );
  } else {
    content = (
      <>
        <div className="mt-4 flex flex-col gap-3">
          {alert}
          {o.needs_review ? (
            <Alert tone="danger" role="status" title="Cần xem lại">
              <ul className="list-disc pl-5">
                {o.needs_review_reasons.map((r) => (
                  <li key={r}>{REVIEW_REASON_LABEL[r]}</li>
                ))}
              </ul>
            </Alert>
          ) : null}
          {s.account_status === "locked" ? (
            <Alert tone="warning" title="Tài khoản học sinh đang bị khoá">
              Vẫn duyệt hoặc huỷ được và học sinh vẫn nhận email; học sinh chỉ vào học được khi tài khoản được mở lại.
            </Alert>
          ) : null}
          {s.account_status === "deleted" ? (
            <Alert tone="info" title="Tài khoản đã xoá">
              Không còn thông tin liên hệ. Đơn được giữ làm chứng từ; không gửi email cho tài khoản này.
            </Alert>
          ) : null}
          {o.status === "pending" && unpublished.length ? (
            <Alert tone="warning" title="Có khóa đã ngừng bán">
              {unpublished.map((t) => `“${t}”`).join(", ")} đã ngừng bán sau khi đặt. Duyệt vẫn mở khóa cho học sinh theo giá đã chốt.
            </Alert>
          ) : null}
        </div>

        <div className="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
          <div className="flex min-w-0 flex-col gap-4">
            <section aria-labelledby="hoc-sinh" className={section}>
              <h2 id="hoc-sinh" className={h2}>
                Học sinh
              </h2>
              {s.account_status === "deleted" ? (
                <p className="mt-2 text-sm text-ink-soft">Tài khoản đã xoá (ẩn danh hoá).</p>
              ) : (
                <>
                  <p className="mt-2 text-base font-semibold text-ink">
                    {s.name}
                    {s.grade_level ? <span className="font-normal text-ink-soft"> · Lớp {s.grade_level}</span> : null}
                  </p>
                  <dl className="mt-3 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[110px_1fr]">
                    <dt className="text-ink-soft">Email</dt>
                    <dd className="flex flex-wrap items-center gap-2 break-all text-ink">{s.email ?? "—"}</dd>
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
                      <ButtonLink href={`tel:${s.phone}`} size="sm" variant="secondary" leadingIcon={<IconPhone size={16} />}>
                        Gọi
                      </ButtonLink>
                    ) : null}
                    {s.email ? (
                      <ButtonLink href={`mailto:${s.email}?subject=${encodeURIComponent(`VitaminVui — đơn ${o.code}`)}`} size="sm" variant="secondary" leadingIcon={<IconMail size={16} />}>
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
                  <p className="mt-1 whitespace-pre-line break-words rounded-control bg-sunken p-3 text-sm text-ink">{o.customer_note}</p>
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
                    {o.items.map((i) => (
                      <tr key={i.course_id} className="border-t border-line align-top">
                        <td className="py-2.5 pr-3">
                          <p className="font-medium text-ink">{i.title}</p>
                          <p className="flex flex-wrap items-center gap-1.5 text-xs text-ink-soft">
                            Lớp {i.grade_level}
                            {i.course_status === "unpublished" ? (
                              <Badge tone="warning" size="sm">
                                Ngừng bán
                              </Badge>
                            ) : i.course_status === "deleted" ? (
                              <Badge tone="danger" size="sm">
                                Đã xoá
                              </Badge>
                            ) : null}
                          </p>
                        </td>
                        <td className="num py-2.5 pr-3 text-right">{formatPrice(i.price)}</td>
                        <td className="num hidden py-2.5 pr-3 text-right sm:table-cell">{i.discount_amount ? `−${formatPrice(i.discount_amount)}` : "—"}</td>
                        <td className="num py-2.5 text-right font-semibold">{formatPrice(i.final_amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <dl className="num mt-3 flex flex-col gap-1.5 border-t border-line pt-3 text-sm">
                <div className="flex justify-between gap-4">
                  <dt className="text-ink-soft">Tạm tính</dt>
                  <dd>{formatPrice(o.pricing.subtotal)}</dd>
                </div>
                {o.coupon ? (
                  <div className="flex justify-between gap-4">
                    <dt className="text-ink-soft">
                      Mã giảm giá <span className="font-semibold text-ink">{o.coupon.code}</span>
                    </dt>
                    <dd className="text-success">−{formatPrice(o.coupon.discount_amount)}</dd>
                  </div>
                ) : null}
                <div className="flex items-baseline justify-between gap-4">
                  <dt className="font-semibold text-ink">Tổng cần thu</dt>
                  <dd className="text-heading font-extrabold tracking-heading text-ink">{formatPrice(o.pricing.total)}</dd>
                </div>
              </dl>
            </section>

            <section aria-labelledby="lich-su" className={section}>
              <h2 id="lich-su" className={h2}>
                Lịch sử trạng thái
              </h2>
              <ol className="mt-3 flex flex-col">
                {o.status_logs.map((l, i, all) => (
                  <li key={l.created_at + l.to} className="relative flex gap-3 pb-4 last:pb-0">
                    <span aria-hidden="true" className="relative flex w-3 justify-center">
                      <span className={cx("mt-1.5 size-2.5 rounded-full", l.to === "paid" ? "bg-success" : l.to === "cancelled" ? "bg-ink-soft" : l.to === "refunded" ? "bg-info" : "bg-warning")} />
                      {i < all.length - 1 ? <span className="absolute top-4 bottom-[-0.25rem] w-px bg-line-strong" /> : null}
                    </span>
                    <div className="min-w-0 flex-1 text-sm">
                      <p className="font-semibold text-ink">{logText(l)}</p>
                      <p className="num text-ink-soft">
                        {actorText(l)} · {formatDateTime(l.created_at)}
                      </p>
                      {l.detail ? <p className="mt-1 whitespace-pre-line break-words text-ink">{l.detail}</p> : null}
                    </div>
                  </li>
                ))}
              </ol>
            </section>
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
                <dd className="num text-ink">{formatDateTime(o.created_at)}</dd>
                {o.status === "pending" && o.expires_at ? (
                  <>
                    <dt className="text-ink-soft">Hạn chờ</dt>
                    <dd className="flex flex-col gap-1 text-ink">
                      <span className="num">{formatDateTime(o.expires_at)}</span>
                      <DeadlineCell expiresAt={o.expires_at} />
                    </dd>
                  </>
                ) : null}
                {o.status === "cancelled" && o.cancelled_at ? (
                  <>
                    <dt className="text-ink-soft">Huỷ lúc</dt>
                    <dd className="num text-ink">{formatDateTime(o.cancelled_at)}</dd>
                  </>
                ) : null}
                {o.cancel_reason_public && o.status === "cancelled" ? (
                  <>
                    <dt className="text-ink-soft">Lý do gửi HS</dt>
                    <dd className="whitespace-pre-line break-words text-ink">{o.cancel_reason_public}</dd>
                  </>
                ) : null}
                {o.confirmed_by && o.paid_at ? (
                  <>
                    <dt className="text-ink-soft">Duyệt bởi</dt>
                    <dd className="text-ink">
                      {o.confirmed_by.name}
                      <span className="num block text-ink-soft">{formatDateTime(o.paid_at)}</span>
                    </dd>
                  </>
                ) : null}
                {o.payment_reference ? (
                  <>
                    <dt className="text-ink-soft">Mã giao dịch</dt>
                    <dd className="break-all font-mono text-xs text-ink">{o.payment_reference}</dd>
                  </>
                ) : null}
              </dl>
            </section>

            <section aria-labelledby="ghi-chu-noi-bo" className={section}>
              <div className="flex items-center gap-2">
                <h2 id="ghi-chu-noi-bo" className={h2}>
                  Ghi chú nội bộ
                </h2>
                <Badge size="sm">Học sinh không thấy</Badge>
              </div>
              <div className="mt-3">
                <InternalNotes key={outcome ?? "x"} initial={o.notes} author={me} now={PREVIEW_NOW} />
              </div>
            </section>
          </aside>
        </div>
      </>
    );
  }

  return (
    <AdminPreviewShell role={role} current="orders" basePath={base} states={STATES} state={state === "dang-tai" || state === "loi" ? state : undefined}>
      {role === "giao_vien" ? (
        <ForbiddenView homeHref="/v2/quan-tri/khoa-hoc?vai-tro=giao_vien" />
      ) : (
        <>
          <nav aria-label="Đơn mẫu (bản xem trước)" className="mb-4 flex flex-wrap items-center gap-1.5 rounded-card border border-dashed border-line-strong bg-sunken p-2 text-sm">
            <span className="px-1 text-ink-soft">Đơn mẫu:</span>
            {SAMPLES.map((x) => {
              const q = [roleQ, x.q ? `trang-thai=${x.q}` : ""].filter(Boolean).join("&");
              const active = x.code === code && (x.q ?? "") === (state ?? "") && !outcome;
              return (
              <Link
                key={x.code + (x.q ?? "")}
                href={`/v2/quan-tri/don-hang/${x.code}${q ? `?${q}` : ""}`}
                aria-current={active ? "true" : undefined}
                className={cx(
                  "focus-ring inline-flex min-h-8 items-center rounded-full border px-2.5 font-medium",
                  active ? "border-primary bg-primary text-on-primary" : "border-line-strong bg-surface text-ink hover:border-primary",
                )}
              >
                {x.label}
              </Link>
              );
            })}
          </nav>

          <Link href={listHref} className="focus-ring -ml-1 inline-flex min-h-9 items-center gap-1 rounded px-1 text-sm font-semibold text-primary">
            <IconChevronLeft size={16} /> Đơn hàng
          </Link>
          <div className="mt-1 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div className="flex flex-col gap-2">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="text-title font-extrabold tracking-heading text-ink">
                  Đơn <span className="num">{o.code}</span>
                </h1>
                <CopyButton value={o.code} label="Sao chép mã" variant="ghost" size="sm" copiedMessage={`Đã sao chép mã đơn ${o.code}`} />
              </div>
              <div className="flex flex-wrap items-center gap-2 text-sm text-ink-soft">
                <AdminStatusBadge order={o} size="md" />
                <span className="num">
                  {formatPrice(o.pricing.total)} · {o.items.length} khóa
                </span>
              </div>
            </div>
            {state === "dang-tai" || state === "loi" ? null : (
              <OrderActions
                key={outcome ?? "x"}
                code={o.code}
                total={o.pricing.total}
                studentName={s.account_status === "deleted" ? "Học sinh" : s.name}
                itemsCount={o.items.length}
                status={o.status}
                cancelLabel={cancelLabel}
                cancelledAt={o.cancelled_at}
                approvalWindowUntil={o.approval_window_until}
                windowOpen={windowOpen}
                unpublishedTitles={unpublished}
                lateWarnings={state === "canh-bao-muon" ? SAMPLE_LATE_WARNINGS : []}
                outcomes={{
                  approve: withQ(`ket-qua=${state === "xung-dot" ? "xung-dot" : state === "da-xu-ly" ? "da-xu-ly" : "da-duyet"}`),
                  late: withQ(`ket-qua=${state === "khoa-da-xoa" ? "khoa-da-xoa" : "duyet-muon"}`),
                  cancel: withQ("ket-qua=da-huy"),
                  refund: withQ("ket-qua=hoan-tien"),
                }}
              />
            )}
          </div>
          {content}
        </>
      )}
    </AdminPreviewShell>
  );
}
