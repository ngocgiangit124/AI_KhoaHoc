import type { Metadata } from "next";
import { AccountGateRoute } from "@/components/auth/AccountGateRoute";

export const metadata: Metadata = { title: "Chờ phụ huynh xác nhận — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

/** Màn chặn "Chờ phụ huynh xác nhận" (403 `PARENT_CONSENT_REQUIRED`, US-017 BR6) dạng trang. */
export default function ParentPendingPage() {
  return (
    <div className="px-4 py-10 sm:px-6 sm:py-16">
      <AccountGateRoute page="parent" />
    </div>
  );
}
