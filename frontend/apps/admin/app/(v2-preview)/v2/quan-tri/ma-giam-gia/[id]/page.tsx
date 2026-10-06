import { notFound } from "next/navigation";
import { Breadcrumb } from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { CouponForm } from "@/components/v2/CouponForm";
import { ForbiddenView } from "@/components/v2/ForbiddenView";
import { COUPONS } from "@/lib/mock/v2/ops";

export const dynamic = "force-dynamic";

/** Sửa mã giảm giá (US-013 §2.2–2.4, GET/PUT /admin/coupons/{id}). Mã đã dùng: khoá mã/loại/giá trị. */
export default async function CouponEditPreview({ params, searchParams }: PageProps<"/v2/quan-tri/ma-giam-gia/[id]">) {
  const { id } = await params;
  const sp = await searchParams;
  const coupon = COUPONS.find((c) => c.id === Number(id));
  if (!coupon) notFound();
  const role = roleFrom(sp["vai-tro"]);
  const roleQ = role === "admin" ? "" : `?vai-tro=${role}`;
  return (
    <AdminPreviewShell role={role} current="coupons" basePath={`/v2/quan-tri/ma-giam-gia/${coupon.id}`}>
      {role === "giao_vien" ? (
        <ForbiddenView homeHref="/v2/quan-tri/khoa-hoc?vai-tro=giao_vien" />
      ) : (
        <>
          <Breadcrumb items={[{ label: "Mã giảm giá", href: `/v2/quan-tri/ma-giam-gia${roleQ}` }, { label: coupon.code }]} />
          <h1 className="mt-3 font-mono text-title font-extrabold tracking-heading text-ink">{coupon.code}</h1>
          <div className="mt-6">
            <CouponForm coupon={coupon} />
          </div>
        </>
      )}
    </AdminPreviewShell>
  );
}
