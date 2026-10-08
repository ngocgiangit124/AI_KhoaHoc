"use client";

import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from "react";
import { Alert, Button, ButtonLink, Field, IconInfo, TextInput, cx, formatPrice } from "@vitaminvui/ui/v2";
import { createCoupon, updateCoupon } from "@/lib/coupons/api";
import { classifyCouponFormError } from "@/lib/coupons/errors";
import {
  CODE_MAX,
  EMPTY_VALUES,
  FIELD_LABELS,
  FIELD_ORDER,
  FIXED_MAX,
  HIGH_RISK_MESSAGE,
  NAME_MAX,
  buildCouponBody,
  isHighRisk,
  isoToVnLocal,
  parseIntStrict,
  sameValues,
  valuesFromCoupon,
  validateCouponForm,
  type CouponFormValues,
  type FieldErrors,
  type FieldKey,
} from "@/lib/coupons/form";
import { cheapestPublishedPrice } from "@/lib/coupons/options";
import { COUPONS_PATH } from "@/lib/coupons/query";
import type { Coupon } from "@/lib/coupons/types";
import { CouponScopeSelector } from "./CouponScopeSelector";

export interface CouponFormProps {
  mode: "create" | "edit";
  /** Bản đã lưu dùng làm giá trị ban đầu (sửa). */
  base?: Coupon;
  /** Mã đã dùng (theo dữ liệu mới nhất): khoá mã/loại/giá trị. */
  usedCount?: number;
  /** Tăng sau mỗi lần lưu/tải lại: form nạp lại giá trị từ `base`. */
  version?: number;
  onSaved: (coupon: Coupon) => void;
  /** Mã đã bị xoá từ nơi khác (404 khi lưu). */
  onGone?: () => void;
  /** COUPON_LOCKED: dữ liệu đã cũ, màn hình tải lại mã. */
  onStale?: () => void;
  onDirtyChange?: (dirty: boolean) => void;
  /** Cột phải (màn sửa: tình trạng, xoá). Nằm trong `<form>` nên chỉ dùng nút `type="button"`. */
  aside?: ReactNode;
}

const CARD = "flex flex-col gap-5 rounded-card border border-line bg-surface p-5";
const FIELD_IDS: Record<FieldKey, string> = {
  code: "cp-code",
  name: "cp-name",
  discount_type: "cp-discount_type",
  discount_value: "cp-discount_value",
  max_uses: "cp-max_uses",
  valid_from: "cp-valid_from",
  valid_until: "cp-valid_until",
  subject_ids: "cp-subject_ids",
  course_ids: "cp-course_ids",
};

/**
 * Form tạo/sửa mã giảm giá (US-013 §2.2, FA7), hình thức theo `components/v2/CouponForm`.
 * Mã đã dùng: mã/loại/giá trị chỉ đọc. Lỗi 422 hiện dưới đúng ô + hộp tóm tắt ở đầu form; dữ liệu nhập được giữ nguyên (trừ khi gặp COUPON_LOCKED: form nạp lại theo bản server).
 */
export function CouponForm({ mode, base, usedCount = 0, version = 0, onSaved, onGone, onStale, onDirtyChange, aside }: CouponFormProps) {
  const locked = mode === "edit" && usedCount > 0;
  const initial = useMemo(() => (base ? valuesFromCoupon(base) : EMPTY_VALUES), [base]);
  const [values, setValues] = useState<CouponFormValues>(initial);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [summaryTick, setSummaryTick] = useState(0);
  const [seenVersion, setSeenVersion] = useState(version);
  // COUPON_LOCKED (mã vừa có người dùng): màn hình tải lại bản mới, form nạp lại TOÀN BỘ theo bản đó (bỏ thay đổi chưa lưu) nhưng giữ banner giải thích.
  const [keepBanner, setKeepBanner] = useState(false);
  const [cheapest, setCheapest] = useState<number | null>(null);
  const pendingRef = useRef(false);
  const formRef = useRef<HTMLFormElement>(null);
  const summaryRef = useRef<HTMLDivElement>(null);

  // Nạp lại từ dữ liệu server sau khi lưu/tải lại (điều chỉnh state ngay lúc render, không dùng effect).
  if (seenVersion !== version) {
    setSeenVersion(version);
    setValues(initial);
    setErrors({});
    if (keepBanner) setKeepBanner(false);
    else setBanner(null);
  }

  // Sửa mà xoá trống "Bắt đầu" = giữ nguyên (gửi null) nên không tính là thay đổi.
  const dirty = !sameValues(mode === "edit" && values.from === "" ? { ...values, from: initial.from } : values, initial);
  useEffect(() => {
    onDirtyChange?.(dirty);
  }, [dirty, onDirtyChange]);
  useEffect(() => () => onDirtyChange?.(false), [onDirtyChange]);
  useEffect(() => {
    if (summaryTick > 0) summaryRef.current?.focus();
  }, [summaryTick]);

  // Giá khóa rẻ nhất đang bán: chỉ cần khi chọn giảm số tiền cố định (báo sớm mã "giảm hết", S18). Lỗi tải → bỏ qua, server vẫn kiểm.
  const hasNumber = parseIntStrict(values.value) !== null;
  const needCheapest = values.type === "fixed_amount" && !locked && hasNumber;
  useEffect(() => {
    if (!needCheapest || cheapest !== null) return;
    const controller = new AbortController();
    cheapestPublishedPrice(controller.signal)
      .then((p) => setCheapest(p))
      .catch(() => undefined);
    return () => controller.abort();
  }, [needCheapest, cheapest]);

  const highRisk = !locked && isHighRisk(values, cheapest);
  const clear = (...keys: FieldKey[]) => setErrors((e) => (keys.some((k) => e[k]) ? Object.fromEntries(Object.entries(e).filter(([k]) => !keys.includes(k as FieldKey))) : e));
  const set = <K extends keyof CouponFormValues>(key: K, v: CouponFormValues[K]) => setValues((cur) => ({ ...cur, [key]: v }));

  function showFailure(fields: FieldErrors, bannerText: string | null) {
    setErrors(fields);
    setBanner(bannerText);
    setSummaryTick((n) => n + 1);
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (pendingRef.current) return;
    const invalid = validateCouponForm(values, { mode, locked, usedCount, cheapest, nowLocal: isoToVnLocal(new Date().toISOString()) });
    if (Object.keys(invalid).length > 0) {
      showFailure(invalid, null);
      return;
    }
    if (mode === "edit" && !dirty) {
      setErrors({});
      setBanner("Chưa có thay đổi nào để lưu.");
      return;
    }
    pendingRef.current = true;
    setPending(true);
    setBanner(null);
    setErrors({});
    try {
      const body = buildCouponBody(values, {
        mode,
        initial: base ? initial : null,
        initialIso: base ? { from: base.valid_from, until: base.valid_until } : undefined,
      });
      const saved = mode === "create" ? await createCoupon(body) : await updateCoupon(base!.id, body);
      onSaved(saved);
    } catch (err) {
      const failure = classifyCouponFormError(err);
      showFailure(failure.fields, failure.banner);
      if (failure.gone) onGone?.();
      if (failure.stale) {
        setKeepBanner(true);
        onStale?.();
      }
    } finally {
      pendingRef.current = false;
      setPending(false);
    }
  }

  const errorList = FIELD_ORDER.filter((k) => errors[k]).map((k) => [k, errors[k]!] as const);
  const percent = values.type === "percent";
  const valueNumber = parseIntStrict(values.value);

  function jumpTo(k: FieldKey) {
    const el = formRef.current?.querySelector<HTMLElement>(`#${FIELD_IDS[k]}`);
    el?.scrollIntoView?.({ block: "center" });
    const target = el?.matches("input,select,textarea,button") ? el : (el?.querySelector<HTMLElement>("input,button") ?? el);
    target?.focus();
  }

  return (
    <form ref={formRef} onSubmit={(ev) => void onSubmit(ev)} noValidate aria-busy={pending} className={cx("grid gap-6", aside ? "lg:grid-cols-[1fr_320px]" : "")}>
      {errorList.length > 0 || banner ? (
        <div ref={summaryRef} id="tom-tat-loi" tabIndex={-1} className="focus-ring rounded-card lg:col-span-full">
          <Alert tone="danger" title={errorList.length > 0 ? `Chưa lưu được — còn ${errorList.length} chỗ cần sửa` : "Chưa lưu được"}>
            {banner ? <p>{banner}</p> : null}
            {errorList.length > 0 ? (
              <ul className="list-disc pl-5">
                {errorList.map(([k, msg]) => (
                  <li key={k}>
                    <a
                      href={`#${FIELD_IDS[k]}`}
                      onClick={(ev) => {
                        ev.preventDefault();
                        jumpTo(k);
                      }}
                      className="inline-flex min-h-11 items-center font-semibold text-danger underline underline-offset-2 sm:min-h-0"
                    >
                      {FIELD_LABELS[k]}
                    </a>
                    : {msg}
                  </li>
                ))}
              </ul>
            ) : null}
          </Alert>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-col gap-6">
        <section aria-labelledby="cp-ma" className={CARD}>
          <h2 id="cp-ma" className="text-base font-semibold text-ink">
            Mã và mức giảm
          </h2>
          {locked ? (
            <p className="flex items-start gap-2 rounded-control bg-sunken p-3 text-sm text-ink">
              <IconInfo size={16} className="mt-0.5 shrink-0 text-info" />
              Mã đã được sử dụng nên không đổi được mã, loại hoặc giá trị giảm. Bạn vẫn sửa được tên, thời hạn, giới hạn lượt và phạm vi.
            </p>
          ) : null}
          <div className="grid gap-5 sm:grid-cols-2">
            <Field id={FIELD_IDS.code} label="Mã giảm giá" required error={errors.code} hint="Tự viết hoa. Học sinh nhập không phân biệt hoa/thường.">
              <TextInput
                size="sm"
                name="code"
                value={values.code}
                maxLength={CODE_MAX + 10}
                autoComplete="off"
                autoCapitalize="characters"
                spellCheck={false}
                disabled={locked || pending}
                className="font-mono uppercase max-sm:h-11"
                placeholder={mode === "create" ? "Ví dụ: TOAN2026" : undefined}
                onChange={(ev) => {
                  set("code", ev.target.value.toUpperCase());
                  clear("code");
                }}
              />
            </Field>
            <Field id={FIELD_IDS.name} label="Tên gợi nhớ (nội bộ)" error={errors.name} hint="Chỉ admin thấy, học sinh không thấy.">
              <TextInput
                size="sm"
                name="name"
                value={values.name}
                maxLength={NAME_MAX + 20}
                autoComplete="off"
                disabled={pending}
                className="max-sm:h-11"
                onChange={(ev) => {
                  set("name", ev.target.value);
                  clear("name");
                }}
              />
            </Field>
          </div>

          <fieldset id={FIELD_IDS.discount_type} tabIndex={-1} disabled={locked || pending} className="outline-none">
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
                    locked ? cx("cursor-not-allowed border-line bg-sunken", values.type === o.v ? "text-ink" : "text-ink-soft") : "cursor-pointer",
                    !locked && values.type === o.v ? "border-primary bg-primary-soft text-primary" : !locked ? "border-line-strong text-ink" : "",
                  )}
                >
                  <input
                    type="radio"
                    name="discount_type"
                    value={o.v}
                    checked={values.type === o.v}
                    onChange={() => {
                      set("type", o.v);
                      clear("discount_value", "discount_type");
                    }}
                    className="accent-primary"
                  />
                  {o.label}
                </label>
              ))}
            </div>
          </fieldset>

          <Field
            id={FIELD_IDS.discount_value}
            label={percent ? "Giảm (%)" : "Giảm (đồng)"}
            required
            error={errors.discount_value}
            hint={percent ? "Số nguyên từ 1 đến 100." : valueNumber !== null && valueNumber > 0 ? formatPrice(valueNumber) : `Số nguyên, tối đa ${formatPrice(FIXED_MAX)}.`}
          >
            <TextInput
              size="sm"
              name="discount_value"
              inputMode="numeric"
              autoComplete="off"
              value={values.value}
              disabled={locked || pending}
              className="num max-w-48 max-sm:h-11"
              onChange={(ev) => {
                set("value", ev.target.value.replace(/[\s.,]/g, ""));
                clear("discount_value");
              }}
            />
          </Field>
          {highRisk ? (
            <Alert tone="warning" title="Mã giảm hết giá trị đơn">
              {HIGH_RISK_MESSAGE}
            </Alert>
          ) : null}
        </section>

        <section aria-labelledby="cp-han" className={CARD}>
          <h2 id="cp-han" className="text-base font-semibold text-ink">
            Thời hạn và lượt dùng
          </h2>
          <div className="grid gap-5 sm:grid-cols-2">
            <Field id={FIELD_IDS.valid_from} label="Bắt đầu" error={errors.valid_from} hint={mode === "create" ? "Giờ Việt Nam. Để trống = bắt đầu ngay khi lưu." : "Giờ Việt Nam. Để trống = giữ nguyên."}>
              <TextInput
                size="sm"
                type="datetime-local"
                name="valid_from"
                value={values.from}
                disabled={pending}
                className="max-sm:h-11"
                onChange={(ev) => {
                  set("from", ev.target.value);
                  clear("valid_from", "valid_until");
                }}
              />
            </Field>
            <Field id={FIELD_IDS.valid_until} label="Kết thúc" required={highRisk} error={errors.valid_until} hint="Giờ Việt Nam. Để trống = không hết hạn.">
              <TextInput
                size="sm"
                type="datetime-local"
                name="valid_until"
                value={values.until}
                disabled={pending}
                className="max-sm:h-11"
                onChange={(ev) => {
                  set("until", ev.target.value);
                  clear("valid_until");
                }}
              />
            </Field>
          </div>
          <Field
            id={FIELD_IDS.max_uses}
            label="Tổng số lượt dùng tối đa"
            required={highRisk}
            error={errors.max_uses}
            hint={mode === "edit" && usedCount > 0 ? `Đã dùng ${usedCount} lượt. Để trống = không giới hạn.` : "Để trống = không giới hạn."}
          >
            <TextInput
              size="sm"
              name="max_uses"
              inputMode="numeric"
              autoComplete="off"
              value={values.maxUses}
              disabled={pending}
              className="num max-w-48 max-sm:h-11"
              onChange={(ev) => {
                set("maxUses", ev.target.value.replace(/[\s.,]/g, ""));
                clear("max_uses");
              }}
            />
          </Field>
          <p className="text-sm text-ink-soft">Mỗi học sinh chỉ được sử dụng mã này 1 lần. Lượt dùng chỉ được tính khi đơn đã thanh toán.</p>
        </section>

        <section aria-labelledby="cp-pham-vi" className={CARD}>
          <h2 id="cp-pham-vi" className="text-base font-semibold text-ink">
            Phạm vi áp dụng
          </h2>
          <CouponScopeSelector
            scope={values.scope}
            onScope={(s) => {
              set("scope", s);
              clear("subject_ids", "course_ids");
            }}
            subjectIds={values.subjectIds}
            onSubjects={(ids) => set("subjectIds", ids)}
            courses={values.courses}
            onCourses={(c) => set("courses", c)}
            knownSubjects={base?.subjects}
            legacyBoth={initial.scope === "both"}
            errors={errors}
            onClearError={(k) => clear(k)}
            disabled={pending}
          />
        </section>
      </div>

      {aside ? <div className="flex min-w-0 flex-col gap-4 lg:sticky lg:top-6 lg:self-start">{aside}</div> : null}

      <div className="sticky bottom-0 z-10 -mx-4 flex justify-end gap-3 border-t border-line bg-surface px-4 py-3 sm:-mx-6 sm:px-6 lg:col-span-full lg:-mx-8 lg:px-8">
        {mode === "create" ? (
          <ButtonLink href={COUPONS_PATH} variant="secondary" size="sm" className="max-sm:h-11">
            Huỷ
          </ButtonLink>
        ) : (
          <Button
            type="button"
            variant="secondary"
            size="sm"
            className="max-sm:h-11"
            disabled={pending || !dirty}
            onClick={() => {
              setValues(initial);
              setErrors({});
              setBanner(null);
            }}
          >
            Huỷ thay đổi
          </Button>
        )}
        <Button type="submit" size="sm" className="max-sm:h-11" loading={pending} loadingText="Đang lưu…">
          {mode === "create" ? "Tạo mã giảm giá" : "Lưu thay đổi"}
        </Button>
      </div>
    </form>
  );
}
