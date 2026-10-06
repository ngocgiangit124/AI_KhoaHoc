import { Breadcrumb } from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { CouponForm } from "@/components/v2/CouponForm";
import { ForbiddenView } from "@/components/v2/ForbiddenView";

export const dynamic = "force-dynamic";

/** Tạo mã giảm giá (US-013 §2.2, POST /admin/coupons). */
export default async function CouponCreatePreview({ searchParams }: PageProps<"/v2/quan-tri/ma-giam-gia/tao">) {
  const sp = await searchParams;
  const role = roleFrom(sp["vai-tro"]);
  const roleQ = role === "admin" ? "" : `?vai-tro=${role}`;
  return (
    <AdminPreviewShell role={role} current="coupons" basePath="/v2/quan-tri/ma-giam-gia/tao">
      {role === "giao_vien" ? (
        <ForbiddenView homeHref="/v2/quan-tri/khoa-hoc?vai-tro=giao_vien" />
      ) : (
        <>
          <Breadcrumb items={[{ label: "Mã giảm giá", href: `/v2/quan-tri/ma-giam-gia${roleQ}` }, { label: "Tạo mã" }]} />
          <h1 className="mt-3 text-title font-extrabold tracking-heading text-ink">Tạo mã giảm giá</h1>
          <div className="mt-6">
            <CouponForm coupon={null} />
          </div>
        </>
      )}
    </AdminPreviewShell>
  );
}
