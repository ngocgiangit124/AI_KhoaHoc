import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { ForbiddenView } from "@/components/v2/ForbiddenView";
import { StaffManager } from "@/components/v2/StaffManager";
import { STAFF_ACCOUNTS } from "@/lib/mock/v2/ops";

export const dynamic = "force-dynamic";

/** Tài khoản staff (US-016, GET /admin/staff). Chỉ Admin; QLT/GV nhận trang 403. */
export default async function StaffPreview({ searchParams }: PageProps<"/v2/quan-tri/tai-khoan">) {
  const sp = await searchParams;
  const role = roleFrom(sp["vai-tro"]);
  return (
    <AdminPreviewShell role={role} current="staff" basePath="/v2/quan-tri/tai-khoan">
      {role !== "admin" ? (
        <ForbiddenView homeHref={`/v2/quan-tri/khoa-hoc?vai-tro=${role}`} />
      ) : (
        <>
          <h1 className="text-title font-extrabold tracking-heading text-ink">Tài khoản staff</h1>
          <p className="mt-1 max-w-3xl text-sm text-ink-soft">Tạo tài khoản cho Admin, Quản lý trang, Giáo viên; khoá, mở khoá, đặt lại mật khẩu, đổi vai trò. Mọi thao tác được ghi nhật ký.</p>
          <div className="mt-6">
            <StaffManager initial={STAFF_ACCOUNTS} />
          </div>
        </>
      )}
    </AdminPreviewShell>
  );
}
