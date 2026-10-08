import type { Metadata } from "next";
import { PolicyPage } from "@/components/policy/PolicyPage";
import { TermsContent } from "@/components/policy/TermsContent";
import { fetchPublicConfig } from "@/lib/catalog/api";

export const metadata: Metadata = { title: "Điều khoản sử dụng — VitaminVui" };

export const dynamic = "force-dynamic";

/** Bản TẠM chờ pháp chế (ADR-006); phiên bản hiện hành lấy từ `/config/public`. */
export default async function TermsPage() {
  const config = await fetchPublicConfig();
  return (
    <PolicyPage title="Điều khoản sử dụng" version={config.policy_version}>
      <TermsContent />
    </PolicyPage>
  );
}
