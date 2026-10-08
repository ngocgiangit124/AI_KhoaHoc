"use client";

import { useCallback } from "react";
import { useRouter } from "next/navigation";
import { Breadcrumb, useToast } from "@vitaminvui/ui/v2";
import { ForbiddenView } from "@/components/shell/ForbiddenView";
import { useSession } from "@/lib/auth/SessionProvider";
import { canManageCoupons } from "@/lib/coupons/permissions";
import { COUPONS_PATH } from "@/lib/coupons/query";
import { useUnsavedChangesGuard } from "@/lib/unsaved/useUnsavedChangesGuard";
import { CouponForm } from "./CouponForm";

/** `/quan-tri/ma-giam-gia/tao` (US-013 §2.2). Lưu xong về danh sách kèm toast. */
export function CouponCreateScreen() {
  const router = useRouter();
  const toast = useToast();
  const { state } = useSession();
  const { dirtyRef, setDirty, dialog } = useUnsavedChangesGuard({
    guardHistory: true,
    description: "Mã giảm giá bạn đang nhập chưa được lưu. Rời trang này sẽ bỏ các thông tin đó.",
  });
  const onSaved = useCallback(() => {
    dirtyRef.current = false;
    toast.show({ tone: "success", title: "Đã lưu mã giảm giá" });
    router.push(COUPONS_PATH);
  }, [dirtyRef, toast, router]);

  if (state.kind !== "staff") return null;
  if (!canManageCoupons(state.user)) return <ForbiddenView />;
  return (
    <div className="flex flex-col">
      {dialog}
      <Breadcrumb items={[{ label: "Mã giảm giá", href: COUPONS_PATH }, { label: "Tạo mã" }]} />
      <h1 className="mt-3 text-title font-extrabold tracking-heading text-ink">Tạo mã giảm giá</h1>
      <div className="mt-6">
        <CouponForm mode="create" onSaved={onSaved} onDirtyChange={setDirty} />
      </div>
    </div>
  );
}
