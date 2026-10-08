import type { Metadata } from "next";
import { PolicyPage } from "@/components/policy/PolicyPage";
import { PrivacyContent } from "@/components/policy/PrivacyContent";
import { fetchPublicConfig } from "@/lib/catalog/api";

export const metadata: Metadata = { title: "Chính sách xử lý dữ liệu cá nhân — VitaminVui" };

export const dynamic = "force-dynamic";

/** Bản TẠM chờ pháp chế (ADR-006); phiên bản hiện hành lấy từ `/config/public`. */
export default async function PrivacyPage() {
  const config = await fetchPublicConfig();
  return (
    <PolicyPage title="Chính sách xử lý dữ liệu cá nhân" version={config.policy_version}>
      <PrivacyContent />
    </PolicyPage>
  );
}
