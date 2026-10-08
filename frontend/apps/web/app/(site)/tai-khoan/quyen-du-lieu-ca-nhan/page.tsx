import type { Metadata } from "next";
import { PrivacyDataView } from "@/components/privacy/PrivacyDataView";

export const metadata: Metadata = { title: "Quyền dữ liệu cá nhân — VitaminVui", robots: { index: false, follow: false } };

export const dynamic = "force-dynamic";

export default function PrivacyDataPage() {
  return (
    <div className="mx-auto w-full max-w-2xl px-4 pb-14 pt-6 sm:px-6">
      <PrivacyDataView />
    </div>
  );
}
