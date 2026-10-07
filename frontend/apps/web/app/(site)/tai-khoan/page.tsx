import type { Metadata } from "next";
import { AccountView } from "@/components/account/AccountView";

export const metadata: Metadata = { title: "Tài khoản — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

export default function AccountPage() {
  return (
    <div className="mx-auto w-full max-w-2xl px-4 pb-14 pt-6 sm:px-6">
      <AccountView />
    </div>
  );
}
