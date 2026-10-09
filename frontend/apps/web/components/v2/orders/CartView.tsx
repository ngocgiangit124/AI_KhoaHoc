"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  CourseCover,
  Field,
  IconArrowRight,
  IconTicket,
  IconTrash,
  IconX,
  Sheet,
  TextInput,
  cx,
  formatPrice,
  useToast,
} from "@vitaminvui/ui/v2";
import { COUPON_ERRORS, coupon as sampleCoupon, type Cart, type CartItem } from "@/lib/mock/v2/orders";
import { routes } from "@/lib/v2/routes";
import { PriceCell, PricingSummary } from "./OrderParts";

/** Tính lại tiền trong bản xem trước. TODO(dev): luôn dùng `pricing` server trả về sau mỗi thao tác giỏ. */
function reprice(items: CartItem[], coupon: Cart["coupon"]): Cart["pricing"] {
  const ok = items.filter((i) => !i.unavailable);
  const subtotal = ok.reduce((s, i) => s + i.price, 0);
  const discount = coupon && ok.some((i) => coupon.applies_to_course_ids.includes(i.course_id)) ? coupon.discount_amount : 0;
  return { subtotal, discount, total: subtotal - discount };
}

/**
 * Giỏ hàng (US-004 + US-022): xoá khóa, nhập/gỡ mã giảm giá, tổng tiền, sang bước thanh toán.
 * Mobile: tóm tắt nằm cuối danh sách + thanh dính đáy (tổng + nút); desktop: cột phải dính.
 * TODO(dev): DELETE /cart/items/{course}, PUT/DELETE /cart/coupon; hiện `notices` server trả (COUPON_REMOVED...).
 */
export function CartView({ initial, initialCouponError, initialCouponInput = "" }: { initial: Cart; initialCouponError?: string; initialCouponInput?: string }) {
  const toast = useToast();
  const [items, setItems] = useState(initial.items);
  const [coupon, setCoupon] = useState(initial.coupon);
  const [code, setCode] = useState(initialCouponInput);
  const [couponError, setCouponError] = useState(initialCouponError);
  const [applying, setApplying] = useState(false);
  const [notices, setNotices] = useState(initial.notices);

  const pricing = reprice(items, coupon);
  const available = items.filter((i) => !i.unavailable);
  const canContinue = available.length > 0;

  function remove(item: CartItem) {
    const next = items.filter((i) => i.course_id !== item.course_id);
    setItems(next);
    let removedCoupon = false;
    if (coupon && !next.some((i) => !i.unavailable && coupon.applies_to_course_ids.includes(i.course_id))) {
      setCoupon(null);
      removedCoupon = true;
    }
    setNotices(
      removedCoupon && coupon
        ? [{ code: "COUPON_REMOVED", message: `Mã ${coupon.code} đã được gỡ vì không còn khóa nào trong giỏ dùng được mã này.` }]
        : notices.filter((n) => n.code !== "ITEMS_UNAVAILABLE" || next.some((i) => i.unavailable)),
    );
    toast.show({ tone: "success", title: "Đã xoá khỏi giỏ hàng", description: item.title });
  }

  function apply(e: FormEvent) {
    e.preventDefault();
    const value = code.trim().toUpperCase();
    if (!value) {
      setCouponError("Nhập mã giảm giá trước khi bấm Áp dụng.");
      return;
    }
    setApplying(true);
    setTimeout(() => {
      setApplying(false);
      if (value === sampleCoupon.code) {
        setCoupon(sampleCoupon);
        setCouponError(undefined);
        setCode("");
        toast.show({ tone: "success", title: `Đã áp dụng mã ${value}` });
      } else {
        setCouponError(COUPON_ERRORS.COUPON_INVALID);
      }
    }, 500);
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
      <div className="flex min-w-0 flex-col gap-4">
        {notices.map((n) => (
          <Alert key={n.code} tone="warning">
            {n.message}
          </Alert>
        ))}

        <Sheet as="section" aria-labelledby="khoa-trong-gio" padding="none">
          <h2 id="khoa-trong-gio" className="px-5 pt-5 text-heading font-extrabold tracking-heading text-ink sm:px-6">
            Khóa học trong giỏ <span className="num text-ink-soft">({items.length})</span>
          </h2>
          <ul className="mt-2 divide-y divide-line px-5 sm:px-6">
            {items.map((it) => (
              <li key={it.course_id} className="flex gap-3 py-4">
                <Link href={routes.course(it.slug)} tabIndex={-1} aria-hidden="true" className="w-20 shrink-0 sm:w-28">
                  <CourseCover title={it.title} gradeLevel={it.grade_level} subjectSlug={it.slug} size="thumb" />
                </Link>
                <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                  <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:gap-3">
                    <div className="min-w-0 flex-1">
                      <Link
                        href={routes.course(it.slug)}
                        className={cx("focus-ring rounded text-base font-semibold leading-snug hover:text-primary", it.unavailable ? "text-ink-soft" : "text-ink")}
                      >
                        {it.title}
                      </Link>
                      <p className="text-sm text-ink-soft">Lớp {it.grade_level}</p>
                    </div>
                    {it.unavailable ? null : (
                      <PriceCell price={it.price} discount={it.discount_amount ?? 0} final={it.final_amount ?? it.price} />
                    )}
                  </div>
                  {it.unavailable ? (
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge tone="warning" size="sm">
                        Ngừng bán
                      </Badge>
                      <span className="text-sm text-ink-soft">Không tính vào đơn.</span>
                    </div>
                  ) : coupon && coupon.applies_to_course_ids.includes(it.course_id) ? (
                    <p className="text-sm font-medium text-success">Đã áp dụng mã {coupon.code}</p>
                  ) : null}
                  <div>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="-ml-3 h-11 sm:h-9"
                      leadingIcon={<IconTrash size={16} />}
                      onClick={() => remove(it)}
                      aria-label={`Xoá khóa ${it.title} khỏi giỏ`}
                    >
                      Xoá
                    </Button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </Sheet>
      </div>

      <aside aria-label="Mã giảm giá và tổng tiền" className="flex flex-col gap-4 lg:sticky lg:top-24">
        <Sheet as="section" aria-labelledby="ma-giam-gia" padding="md">
          <h2 id="ma-giam-gia" className="flex items-center gap-2 text-lg font-semibold text-ink">
            <IconTicket className="text-primary" />
            Mã giảm giá
          </h2>
          {coupon ? (
            <div className="mt-3 flex items-start gap-3 rounded-control bg-success-soft p-3">
              <div className="flex-1">
                <p className="num font-semibold text-ink">{coupon.code}</p>
                <p className="text-sm text-ink">{coupon.name}</p>
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="h-11 sm:h-9"
                leadingIcon={<IconX size={16} />}
                onClick={() => {
                  setCoupon(null);
                  toast.show({ tone: "info", title: `Đã gỡ mã ${coupon.code}` });
                }}
              >
                Gỡ mã
              </Button>
            </div>
          ) : (
            <form onSubmit={apply} className="mt-3 flex flex-col gap-2" noValidate>
              <div className="flex items-start gap-2">
                <Field label="Nhập mã" error={couponError} className="flex-1">
                  <TextInput
                    value={code}
                    onChange={(e) => {
                      setCode(e.target.value);
                      if (couponError) setCouponError(undefined);
                    }}
                    autoCapitalize="characters"
                    autoComplete="off"
                    spellCheck={false}
                    maxLength={50}
                    className="uppercase"
                  />
                </Field>
                <Button type="submit" variant="secondary" className="mt-6.5" loading={applying} loadingText="Đang áp dụng…">
                  Áp dụng
                </Button>
              </div>
              <p className="text-sm text-ink-soft">Mỗi đơn dùng một mã. Thử mã mẫu: VITAMIN50.</p>
            </form>
          )}
        </Sheet>

        <Sheet as="section" padding="md" aria-labelledby="tom-tat" className="hidden lg:block">
          <h2 id="tom-tat" className="text-lg font-semibold text-ink">
            Tóm tắt
          </h2>
          <PricingSummary pricing={pricing} couponCode={coupon?.code} className="mt-3" />
          <ContinueButton enabled={canContinue} className="mt-5" />
          <NextStepNote enabled={canContinue} />
        </Sheet>

        {/* Mobile/tablet: tóm tắt đầy đủ trong dòng chảy trang; nút dính đáy ở dưới. */}
        <Sheet as="section" padding="md" aria-label="Tóm tắt" className="lg:hidden">
          <PricingSummary pricing={pricing} couponCode={coupon?.code} />
          <NextStepNote enabled={canContinue} />
        </Sheet>
      </aside>

      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3 shadow-overlay lg:hidden">
        <div className="mx-auto flex max-w-2xl items-center gap-3">
          <div className="num flex-1">
            <p className="text-sm text-ink-soft">Tổng cộng</p>
            <p className="text-lg font-extrabold text-ink">{pricing.total === 0 ? "0đ" : formatPrice(pricing.total)}</p>
          </div>
          <ContinueButton enabled={canContinue} />
        </div>
      </div>
    </div>
  );
}

function ContinueButton({ enabled, className }: { enabled: boolean; className?: string }) {
  if (!enabled) {
    return (
      <Button size="lg" disabled className={className} block>
        Tiếp tục đặt mua
      </Button>
    );
  }
  return (
    <ButtonLink href={routes.checkout} size="lg" block className={className} trailingIcon={<IconArrowRight size={18} />}>
      Tiếp tục đặt mua
    </ButtonLink>
  );
}

function NextStepNote({ enabled }: { enabled: boolean }) {
  return (
    <p className="mt-3 text-sm text-ink-soft">
      {enabled
        ? "Bước sau: chọn cách thanh toán và gửi đơn. Bạn chưa phải trả tiền ở bước này."
        : "Giỏ không còn khóa nào đang bán. Xoá khóa ngừng bán rồi chọn khóa khác để tiếp tục."}
    </p>
  );
}
