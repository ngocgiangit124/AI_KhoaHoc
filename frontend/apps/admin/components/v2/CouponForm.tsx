"use client";

import { useState, type FormEvent } from "react";
import {
  Alert,
  Badge,
  Button,
  Checkbox,
  ConfirmDialog,
  Field,
  IconInfo,
  ProgressBar,
  TextInput,
  cx,
  useToast,
} from "@vitaminvui/ui/v2";
import { ADMIN_COURSES, SUBJECTS } from "@/lib/mock/v2/data";
import { COUPON_STATE_LABEL, type Coupon } from "@/lib/mock/v2/ops";

type Scope = "all" | "subjects" | "courses";
/** Giá khóa rẻ nhất đang bán (để phát hiện mã "giảm hết" — S18). Bản thật do server kiểm, UI chỉ báo sớm. */
const CHEAPEST_PRICE = Math.min(...ADMIN_COURSES.filter((c) => c.status === "published" && c.price > 0).map((c) => c.price));

function toLocal(iso: string | null) {
  return iso ? iso.slice(0, 16) : "";
}

/**
 * Tạo/sửa mã giảm giá (US-013, FA7; POST/PUT /admin/coupons — PUT thay toàn bộ).
 * - Mã đã dùng (`used_count > 0`): mã, loại, giá trị chỉ đọc kèm câu giải thích (422 COUPON_LOCKED).
 * - Mã 100% hoặc giảm tiền ≥ giá khóa rẻ nhất: bắt buộc giới hạn lượt + ngày hết hạn (S18).
 * - Phạm vi: toàn bộ / theo chuyên đề / theo khóa (≤ 200, có ô tìm khóa). API cho phép kết hợp cả khóa và
 *   chuyên đề (hợp), UI theo US-013 chỉ chọn một kiểu — chờ PO nếu cần kết hợp.
 */
export function CouponForm({ coupon }: { coupon: Coupon | null }) {
  const toast = useToast();
  const locked = (coupon?.used_count ?? 0) > 0;
  const [code, setCode] = useState(coupon?.code ?? "");
  const [type, setType] = useState<Coupon["discount_type"]>(coupon?.discount_type ?? "percent");
  const [value, setValue] = useState(coupon ? String(coupon.discount_value) : "");
  const [maxUses, setMaxUses] = useState(coupon?.max_uses ? String(coupon.max_uses) : "");
  const [from, setFrom] = useState(toLocal(coupon?.valid_from ?? null));
  const [until, setUntil] = useState(toLocal(coupon?.valid_until ?? null));
  const [scope, setScope] = useState<Scope>(coupon?.is_restricted ? (coupon.courses_count ? "courses" : "subjects") : "all");
  const [courseQ, setCourseQ] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [toggleOpen, setToggleOpen] = useState(false);
  const [active, setActive] = useState(coupon?.status !== "inactive");

  const v = Number(value);
  const highRisk = (type === "percent" && v === 100) || (type === "fixed_amount" && v >= CHEAPEST_PRICE);

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    const next: Record<string, string> = {};
    if (!/^[A-Z0-9_-]{4,50}$/.test(code.trim().toUpperCase())) next.code = "Mã gồm 4–50 ký tự: chữ, số, gạch ngang hoặc gạch dưới.";
    if (!(v >= 1)) next.value = "Nhập giá trị giảm lớn hơn 0.";
    else if (type === "percent" && v > 100) next.value = "Giá trị giảm không được vượt quá 100%.";
    if (until && from && until < from) next.until = "Ngày kết thúc phải sau ngày bắt đầu.";
    if (highRisk && !maxUses) next.maxUses = "Mã giảm 100% (hoặc giảm hết giá khóa rẻ nhất) bắt buộc có giới hạn lượt dùng.";
    if (highRisk && !until) next.until = "Mã giảm 100% (hoặc giảm hết giá khóa rẻ nhất) bắt buộc có ngày hết hạn.";
    if (coupon && maxUses && Number(maxUses) < coupon.used_count) next.maxUses = `Không được nhỏ hơn số lượt đã dùng (${coupon.used_count}).`;
    setErrors(next);
    if (Object.keys(next).length) return;
    setSaving(true);
    setTimeout(() => {
      setSaving(false);
      toast.show({ tone: "success", title: "Đã lưu mã giảm giá" });
    }, 700);
  }

  return (
    <form noValidate onSubmit={onSubmit} className="grid gap-6 lg:grid-cols-[1fr_320px]">
      <div className="flex flex-col gap-6">
        <section aria-labelledby="ma" className="flex flex-col gap-5 rounded-card border border-line bg-surface p-5">
          <h2 id="ma" className="text-base font-semibold text-ink">
            Mã và mức giảm
          </h2>
          {locked ? (
            <p className="flex items-start gap-2 rounded-control bg-sunken p-3 text-sm text-ink">
              <IconInfo size={16} className="mt-0.5 shrink-0 text-info" />
              Mã đã được sử dụng nên không đổi được mã, loại hoặc giá trị giảm. Bạn vẫn sửa được tên, thời hạn, giới hạn lượt và phạm vi.
            </p>
          ) : null}
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Mã giảm giá" required error={errors.code} hint="Tự viết hoa. Học sinh nhập không phân biệt hoa/thường.">
              <TextInput size="sm" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} maxLength={50} disabled={locked} className="font-mono uppercase" />
            </Field>
            <Field label="Tên gợi nhớ (nội bộ)">
              <TextInput size="sm" defaultValue={coupon?.name ?? ""} maxLength={255} />
            </Field>
          </div>
          <fieldset>
            <legend className="mb-1 text-sm font-semibold text-ink">Loại giảm giá</legend>
            <div className="grid gap-2 sm:grid-cols-2">
              {(
                [
                  { v: "percent", label: "Phần trăm (%)" },
                  { v: "fixed_amount", label: "Số tiền cố định (đ)" },
                ] as const
              ).map((o) => (
                <label
                  key={o.v}
                  className={cx(
                    "flex min-h-11 items-center gap-2 rounded-control border px-3 text-sm font-semibold has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-focus",
                    locked ? cx("cursor-not-allowed border-line bg-sunken", type === o.v ? "text-ink" : "text-ink-soft") : "cursor-pointer",
                    !locked && type === o.v ? "border-primary bg-primary-soft text-primary" : !locked ? "border-line-strong text-ink" : "",
                  )}
                >
                  <input type="radio" name="discount_type" checked={type === o.v} disabled={locked} onChange={() => setType(o.v)} className="accent-primary" />
                  {o.label}
                </label>
              ))}
            </div>
          </fieldset>
          <Field label={type === "percent" ? "Giảm (%)" : "Giảm (đồng)"} required error={errors.value} hint={type === "percent" ? "1–100." : "Tối đa 100.000.000đ."}>
            <TextInput size="sm" inputMode="numeric" value={value} onChange={(e) => setValue(e.target.value.replace(/\D/g, ""))} disabled={locked} className="num max-w-48" />
          </Field>
          {highRisk ? (
            <Alert tone="warning" title="Mã giảm hết giá trị đơn">
              Mã giảm 100% (hoặc giảm hết giá khóa rẻ nhất) bắt buộc có giới hạn lượt dùng và ngày hết hạn để tránh lộ mã và giữ chỗ ảo.
            </Alert>
          ) : null}
        </section>

        <section aria-labelledby="han" className="flex flex-col gap-5 rounded-card border border-line bg-surface p-5">
          <h2 id="han" className="text-base font-semibold text-ink">
            Thời hạn và lượt dùng
          </h2>
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Bắt đầu" hint="Để trống = bắt đầu ngay khi lưu.">
              <TextInput size="sm" type="datetime-local" value={from} onChange={(e) => setFrom(e.target.value)} />
            </Field>
            <Field label="Kết thúc" required={highRisk} error={errors.until} hint="Để trống = không hết hạn.">
              <TextInput size="sm" type="datetime-local" value={until} onChange={(e) => setUntil(e.target.value)} />
            </Field>
          </div>
          <Field label="Tổng số lượt dùng tối đa" required={highRisk} error={errors.maxUses} hint="Để trống = không giới hạn.">
            <TextInput size="sm" inputMode="numeric" value={maxUses} onChange={(e) => setMaxUses(e.target.value.replace(/\D/g, ""))} className="num max-w-48" />
          </Field>
          <p className="text-sm text-ink-soft">Mỗi học sinh chỉ dùng mã này 1 lần. Lượt chỉ được tính khi đơn đã thanh toán.</p>
        </section>

        <section aria-labelledby="pham-vi" className="flex flex-col gap-4 rounded-card border border-line bg-surface p-5">
          <h2 id="pham-vi" className="text-base font-semibold text-ink">
            Phạm vi áp dụng
          </h2>
          <fieldset className="flex flex-col gap-1">
            <legend className="sr-only">Phạm vi</legend>
            {(
              [
                { v: "all", label: "Toàn bộ khóa học" },
                { v: "subjects", label: "Theo chuyên đề" },
                { v: "courses", label: "Theo khóa học cụ thể" },
              ] as const
            ).map((o) => (
              <label key={o.v} className="flex min-h-11 cursor-pointer items-center gap-3 text-base text-ink">
                <input type="radio" name="scope" checked={scope === o.v} onChange={() => setScope(o.v)} className="size-5 accent-primary" />
                {o.label}
              </label>
            ))}
          </fieldset>
          {scope === "subjects" ? (
            <div className="grid gap-x-4 rounded-control border border-line p-3 sm:grid-cols-2">
              {SUBJECTS.map((s) => (
                <Checkbox key={s.id} id={`cs-${s.id}`} label={s.name} defaultChecked={coupon?.subjects?.some((x) => x.id === s.id)} className="min-h-10 py-1.5" />
              ))}
            </div>
          ) : null}
          {scope === "courses" ? (
            <div className="flex flex-col gap-2 rounded-control border border-line p-3">
              <label htmlFor="course-q" className="sr-only">
                Tìm khóa học
              </label>
              <TextInput id="course-q" size="sm" type="search" placeholder="Tìm khóa học" value={courseQ} onChange={(e) => setCourseQ(e.target.value)} />
              <div className="max-h-64 overflow-y-auto">
                {ADMIN_COURSES.filter((c) => c.status !== "draft" && c.title.toLowerCase().includes(courseQ.toLowerCase())).map((c) => (
                  <Checkbox key={c.id} id={`cc-${c.id}`} label={c.title} defaultChecked={coupon?.courses?.some((x) => x.id === c.id)} className="min-h-10 py-1.5" />
                ))}
              </div>
            </div>
          ) : null}
        </section>

        <div className="sticky bottom-0 z-10 -mx-4 flex justify-end gap-3 border-t border-line bg-surface px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
          <Button type="submit" size="sm" loading={saving} loadingText="Đang lưu…">
            {coupon ? "Lưu thay đổi" : "Tạo mã giảm giá"}
          </Button>
        </div>
      </div>

      {coupon ? (
        <aside className="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
          <section className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
            <div className="flex items-center justify-between gap-2">
              <h2 className="text-base font-semibold text-ink">Tình trạng</h2>
              <Badge size="sm" dot tone={active ? (coupon.state === "active" ? "success" : "warning") : "neutral"}>
                {active ? COUPON_STATE_LABEL[coupon.state === "inactive" ? "active" : coupon.state] : COUPON_STATE_LABEL.inactive}
              </Badge>
            </div>
            <ProgressBar
              value={coupon.max_uses ? (coupon.used_count / coupon.max_uses) * 100 : 0}
              label="Lượt đã dùng"
              valueText={coupon.max_uses ? `${coupon.used_count}/${coupon.max_uses}` : `${coupon.used_count} (không giới hạn)`}
            />
            <Button variant="secondary" size="sm" onClick={() => setToggleOpen(true)}>
              {active ? "Tắt mã" : "Bật lại mã"}
            </Button>
            <p className="text-sm text-ink-soft">Tắt mã không ảnh hưởng đơn đã tạo.</p>
          </section>
          <section className="flex flex-col gap-2 rounded-card border border-danger/40 bg-surface p-5">
            <h2 className="text-base font-semibold text-danger">Xoá mã</h2>
            {locked ? (
              <>
                <p className="text-sm text-ink">Không thể xoá vì mã đã được sử dụng. Hãy tắt mã thay thế.</p>
                <Button variant="danger" size="sm" disabled>
                  Xoá mã
                </Button>
              </>
            ) : (
              <>
                <p className="text-sm text-ink">Mã chưa từng được dùng nên có thể xoá. Không hoàn tác được.</p>
                <Button variant="danger" size="sm">
                  Xoá mã
                </Button>
              </>
            )}
          </section>
        </aside>
      ) : null}

      <ConfirmDialog
        open={toggleOpen}
        onClose={() => setToggleOpen(false)}
        onConfirm={() => {
          setToggleOpen(false);
          setActive(!active);
          toast.show({ tone: "success", title: active ? "Đã tắt mã giảm giá" : "Đã bật lại mã giảm giá" });
        }}
        title={active ? `Tắt mã ${coupon?.code ?? ""}?` : `Bật lại mã ${coupon?.code ?? ""}?`}
        description={active ? "Học sinh sẽ không áp dụng được mã này nữa. Bạn có thể bật lại sau." : "Mã sẽ dùng được trở lại trong thời hạn đã đặt."}
        confirmLabel={active ? "Tắt mã" : "Bật lại"}
      />
    </form>
  );
}
