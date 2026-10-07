import type { Metadata } from "next";
import { AccountGateRoute } from "@/components/auth/AccountGateRoute";

export const metadata: Metadata = { title: "Cần xác thực tài khoản — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

/** Màn chặn "Cần xác thực tài khoản" (403 `ACCOUNT_NOT_VERIFIED`, US-001 §2.4 / AC9) dạng trang. */
export default function NeedVerifyPage() {
  return (
    <div className="px-4 py-10 sm:px-6 sm:py-16">
      <AccountGateRoute page="verify" />
    </div>
  );
}
