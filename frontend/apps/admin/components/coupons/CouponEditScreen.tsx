"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Alert, Breadcrumb, Button, ButtonLink, ConfirmDialog, Dialog, EmptyState, IconSearch, LoadingRegion, ProgressBar, Skeleton, formatCount, formatDateTime, useToast } from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { useSession } from "@/lib/auth/SessionProvider";
import { deleteCoupon, getCoupon, setCouponActive } from "@/lib/coupons/api";
import { IN_USE_MESSAGE, couponActionError, isForbidden, isInUse, isNotFound } from "@/lib/coupons/errors";
import { canManageCoupons } from "@/lib/coupons/permissions";
import { COUPONS_PATH } from "@/lib/coupons/query";
import type { Coupon } from "@/lib/coupons/types";
import { useUnsavedChangesGuard } from "@/lib/unsaved/useUnsavedChangesGuard";
import { CouponForm } from "./CouponForm";
import { CouponStateBadge } from "./CouponStateBadge";
import { discountText, isEmptyScope } from "./CouponsScreen";

type Data = { coupon: Coupon; base: Coupon; version: number };
type Dlg = "toggle" | "delete" | "in-use" | null;

/**
 * `/quan-tri/ma-giam-gia/{id}` — sửa mã, xem lượt dùng/phạm vi, tắt-bật và xoá (US-013 §2.2–2.4, FA7).
 * `coupon` = bản mới nhất (cột phải, khoá trường); `base` = bản làm giá trị ban đầu của form (chỉ đổi khi lưu/tải lại toàn bộ).
 */
export function CouponEditScreen({ id }: { id: number }) {
  const router = useRouter();
  const toast = useToast();
  const { state } = useSession();
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [loadKey, setLoadKey] = useState(0);
  const [dialog, setDialog] = useState<Dlg>(null);
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const asideOnly = useRef(false);
  const { dirtyRef, setDirty, dialog: unsavedDialog } = useUnsavedChangesGuard({
    guardHistory: true,
    description: "Thay đổi của mã giảm giá chưa được lưu. Rời trang này sẽ bỏ các thay đổi đó.",
  });
  const allowed = state.kind === "staff" && canManageCoupons(state.user);

  useEffect(() => {
    if (!allowed) return;
    const controller = new AbortController();
    const keepForm = asideOnly.current;
    asideOnly.current = false;
    getCoupon(id, controller.signal)
      .then((coupon) => {
        setError(null);
        setData((prev) => (keepForm && prev ? { ...prev, coupon } : { coupon, base: coupon, version: (prev?.version ?? 0) + 1 }));
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted) return;
        if (isNotFound(err) || isForbidden(err)) setData(null);
        setError(err);
      });
    return () => controller.abort();
  }, [id, loadKey, allowed]);

  const reload = useCallback((onlyAside = false) => {
    asideOnly.current = onlyAside;
    setLoadKey((n) => n + 1);
  }, []);
  const markGone = useCallback(() => {
    setData(null);
    setError(new Error("gone"));
  }, []);
  const onSaved = useCallback((saved: Coupon) => {
    toast.show({ tone: "success", title: "Đã lưu mã giảm giá" });
    setData((prev) => ({ coupon: saved, base: saved, version: (prev?.version ?? 0) + 1 }));
  }, [toast]);

  if (state.kind !== "staff") return null;
  if (!allowed || (error && isForbidden(error))) return <ForbiddenView />;

  const back = (
    <ButtonLink href={COUPONS_PATH} variant="secondary" size="sm" className="max-sm:h-11">
      Về danh sách mã giảm giá
    </ButtonLink>
  );
  if (!data && error) {
    if (isNotFound(error) || (error instanceof Error && error.message === "gone")) {
      return (
        <div data-testid="coupon-not-found" className="mx-auto max-w-xl py-8">
          <EmptyState headingLevel="h1" icon={<IconSearch size={32} />} title="Không tìm thấy mã giảm giá" description="Mã có thể đã bị xoá." action={back} />
        </div>
      );
    }
    return (
      <div className="flex flex-col gap-4">
        <Breadcrumb items={[{ label: "Mã giảm giá", href: COUPONS_PATH }, { label: "Sửa mã" }]} />
        <Alert
          tone="danger"
          title="Không tải được mã giảm giá"
          action={
            <Button size="sm" variant="secondary" className="max-sm:h-11" onClick={() => reload()}>
              Thử lại
            </Button>
          }
        >
          {couponActionError(error)}
        </Alert>
      </div>
    );
  }
  if (!data) {
    return (
      <LoadingRegion className="flex flex-col gap-4">
        <Skeleton className="h-5 w-48" />
        <Skeleton className="h-9 w-80 max-w-full" />
        <Skeleton className="h-96 w-full" />
      </LoadingRegion>
    );
  }

  const { coupon } = data;
  const isActive = coupon.status === "active";
  const used = coupon.used_count > 0;
  const emptyScope = isEmptyScope({ is_restricted: coupon.is_restricted, courses_count: coupon.courses?.length, subjects_count: coupon.subjects?.length });

  async function toggle() {
    if (busyRef.current) return;
    busyRef.current = true;
    setBusy(true);
    try {
      const next = await setCouponActive(coupon.id, !isActive);
      setData((prev) => (prev ? { ...prev, coupon: next } : prev));
      toast.show({ tone: "success", title: isActive ? "Đã vô hiệu hoá mã giảm giá" : "Đã bật lại mã giảm giá" });
      setDialog(null);
    } catch (err) {
      toast.show({ tone: "danger", title: couponActionError(err) });
      setDialog(null);
      if (isNotFound(err)) markGone();
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  async function remove() {
    if (busyRef.current) return;
    busyRef.current = true;
    setBusy(true);
    try {
      await deleteCoupon(coupon.id);
      dirtyRef.current = false;
      toast.show({ tone: "success", title: "Đã xoá mã giảm giá" });
      router.push(COUPONS_PATH);
    } catch (err) {
      if (isInUse(err)) {
        setDialog("in-use");
        reload(true);
      } else {
        toast.show({ tone: "danger", title: couponActionError(err) });
        setDialog(null);
        if (isNotFound(err)) markGone();
      }
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  const aside = (
    <>
      <section aria-labelledby="cp-tinh-trang" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
        <div className="flex items-center justify-between gap-2">
          <h2 id="cp-tinh-trang" className="text-base font-semibold text-ink">
            Tình trạng
          </h2>
          <CouponStateBadge state={coupon.state} />
        </div>
        <ProgressBar
          size="lg"
          value={coupon.max_uses ? (coupon.used_count / coupon.max_uses) * 100 : 0}
          label="Lượt đã dùng"
          valueText={coupon.max_uses ? `${formatCount(coupon.used_count)}/${formatCount(coupon.max_uses)}` : `${formatCount(coupon.used_count)} (không giới hạn)`}
        />
        <p className="text-sm text-ink-soft">
          Giảm <span className="num font-semibold text-ink">{discountText(coupon)}</span>
          {coupon.updated_at ? ` · Cập nhật ${formatDateTime(coupon.updated_at)}` : ""}
        </p>
        <Button variant="secondary" size="sm" className="max-sm:h-11" disabled={busy} onClick={() => setDialog("toggle")}>
          {isActive ? "Vô hiệu hoá" : "Bật lại mã"}
        </Button>
        <p className="text-sm text-ink-soft">Vô hiệu hoá không ảnh hưởng đơn hàng đã tạo.</p>
      </section>

      <section aria-labelledby="cp-pham-vi-luu" className="flex flex-col gap-2 rounded-card border border-line bg-surface p-5">
        <h2 id="cp-pham-vi-luu" className="text-base font-semibold text-ink">
          Phạm vi đang lưu
        </h2>
        {!coupon.is_restricted ? (
          <p className="text-sm text-ink">Toàn bộ khóa học.</p>
        ) : (
          <>
            {coupon.subjects && coupon.subjects.length > 0 ? (
              <div>
                <p className="text-sm font-semibold text-ink">Chuyên đề ({coupon.subjects.length})</p>
                <ul className="list-disc pl-5 text-sm text-ink">
                  {coupon.subjects.map((s) => (
                    <li key={s.id}>{s.name}</li>
                  ))}
                </ul>
              </div>
            ) : null}
            {coupon.courses && coupon.courses.length > 0 ? (
              <div>
                <p className="text-sm font-semibold text-ink">Khóa học ({coupon.courses.length})</p>
                <ul className="list-disc pl-5 text-sm text-ink">
                  {coupon.courses.map((c) => (
                    <li key={c.id} className="break-words">
                      {c.title}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
          </>
        )}
      </section>

      <section aria-labelledby="cp-xoa" className="flex flex-col gap-2 rounded-card border border-danger/40 bg-surface p-5">
        <h2 id="cp-xoa" className="text-base font-semibold text-danger">
          Xoá mã
        </h2>
        {used ? (
          <>
            <p className="text-sm text-ink">Không thể xoá vì mã đã được sử dụng. Hãy vô hiệu hoá thay thế.</p>
            <Button variant="danger" size="sm" className="max-sm:h-11" disabled>
              Xoá mã
            </Button>
          </>
        ) : (
          <>
            <p className="text-sm text-ink">Mã chưa từng được dùng nên có thể xoá. Không hoàn tác được.</p>
            <Button variant="danger" size="sm" className="max-sm:h-11" disabled={busy} onClick={() => setDialog("delete")}>
              Xoá mã
            </Button>
          </>
        )}
      </section>
    </>
  );

  return (
    <div className="flex flex-col">
      {unsavedDialog}
      <Breadcrumb items={[{ label: "Mã giảm giá", href: COUPONS_PATH }, { label: coupon.code }]} />
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <h1 className="break-all font-mono text-title font-extrabold tracking-heading text-ink">{coupon.code}</h1>
        <CouponStateBadge state={coupon.state} />
      </div>
      {emptyScope ? (
        <Alert tone="warning" className="mt-4" title="Phạm vi trống">
          Mã không còn áp dụng cho khóa nào vì phạm vi đã bị xoá. Hãy chọn lại phạm vi.
        </Alert>
      ) : null}
      <div className="mt-6">
        <CouponForm key={coupon.id} mode="edit" base={data.base} usedCount={coupon.used_count} version={data.version} onSaved={onSaved} onGone={markGone} onStale={() => reload(false)} onDirtyChange={setDirty} aside={aside} />
      </div>

      <ConfirmDialog
        open={dialog === "toggle"}
        onClose={() => (busy ? undefined : setDialog(null))}
        onConfirm={() => void toggle()}
        loading={busy}
        loadingText="Đang xử lý…"
        title={isActive ? `Vô hiệu hoá mã ${coupon.code}?` : `Bật lại mã ${coupon.code}?`}
        description={isActive ? "Học sinh sẽ không áp dụng được mã này nữa, nhưng đơn đã tạo không bị ảnh hưởng. Bạn có thể bật lại sau." : "Mã dùng được trở lại trong thời hạn và số lượt đã đặt."}
        confirmLabel={isActive ? "Vô hiệu hoá" : "Bật lại"}
        tone={isActive ? "danger" : "primary"}
      />
      <ConfirmDialog
        open={dialog === "delete"}
        onClose={() => (busy ? undefined : setDialog(null))}
        onConfirm={() => void remove()}
        loading={busy}
        loadingText="Đang xoá…"
        title={`Xoá mã ${coupon.code}?`}
        description="Hành động này không thể hoàn tác."
        confirmLabel="Xoá mã"
        tone="danger"
      />
      <Dialog open={dialog === "in-use"} title="Không thể xoá mã giảm giá" size="sm" onClose={() => setDialog(null)} footer={<Button onClick={() => setDialog(null)}>Đã hiểu</Button>}>
        <p className="text-base text-ink">{IN_USE_MESSAGE}</p>
      </Dialog>
    </div>
  );
}
