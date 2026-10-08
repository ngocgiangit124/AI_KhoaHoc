"use client";

import { useEffect } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Alert, Button, Sheet } from "@vitaminvui/ui/v2";
import { useAuth } from "@/lib/auth/AuthProvider";
import { routes } from "@/lib/routes";
import { ConsentsSection } from "./ConsentsSection";
import { DataExportSection } from "./DataExportSection";
import { DeleteAccountSection } from "./DeleteAccountSection";
import { ParentContactSection } from "./ParentContactSection";

const H2 = "text-heading font-extrabold tracking-heading text-ink";

/** `/tai-khoan/quyen-du-lieu-ca-nhan`: 4 khối. Khách -> đăng nhập rồi quay lại; `/auth/me` lỗi -> Thử lại. Quyền thật do API kiểm. */
export function PrivacyDataView() {
  const router = useRouter();
  const { state, refresh } = useAuth();

  useEffect(() => {
    if (state.status === "guest") router.replace(`${routes.login}?next=${routes.privacyData}`);
  }, [state, router]);

  if (state.status === "error") {
    return (
      <Sheet>
        <Alert tone="danger" title="Không tải được thông tin tài khoản" action={<Button variant="secondary" onClick={() => void refresh()}>Thử lại</Button>}>
          Vui lòng kiểm tra kết nối và thử lại.
        </Alert>
      </Sheet>
    );
  }
  if (state.status !== "user") return <p className="text-base text-ink-soft">Đang tải…</p>;

  // `key` theo cờ chấp nhận chính sách: bấm đồng ý ở banner thì danh sách đồng ý tải lại.
  const consentKey = String(state.user.needs_policy_acceptance === true);

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link href={routes.account} className="focus-ring inline-flex min-h-11 items-center rounded text-base font-semibold text-primary hover:underline">
          ‹ Tài khoản
        </Link>
        <h1 className="text-title font-extrabold tracking-heading text-ink">Quyền dữ liệu cá nhân</h1>
        <p className="mt-1 text-base text-ink-soft">
          Xem bạn đã đồng ý gì, quản lý thông tin phụ huynh, tải hoặc xoá dữ liệu của mình. Xem thêm{" "}
          <Link href={routes.privacy} className="focus-ring rounded font-semibold text-primary hover:underline">
            Chính sách xử lý dữ liệu cá nhân
          </Link>
          .
        </p>
      </div>

      <Sheet as="section" aria-labelledby="dong-y">
        <h2 id="dong-y" className={`${H2} mb-4`}>Đồng ý</h2>
        <ConsentsSection key={consentKey} />
      </Sheet>

      <Sheet as="section" aria-labelledby="phu-huynh">
        <h2 id="phu-huynh" className={`${H2} mb-4`}>Thông tin phụ huynh</h2>
        <ParentContactSection />
      </Sheet>

      <Sheet as="section" aria-labelledby="tai-du-lieu">
        <h2 id="tai-du-lieu" className={`${H2} mb-4`}>Tải dữ liệu</h2>
        <DataExportSection />
      </Sheet>

      <Sheet as="section" aria-labelledby="xoa-tai-khoan">
        <h2 id="xoa-tai-khoan" className={`${H2} mb-4`}>Xoá tài khoản</h2>
        <DeleteAccountSection />
      </Sheet>
    </div>
  );
}
