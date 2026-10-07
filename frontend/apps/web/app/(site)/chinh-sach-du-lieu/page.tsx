import type { Metadata } from "next";
import { IconShieldCheck } from "@vitaminvui/ui/v2";
import { InfoPlaceholder } from "@/components/shell/InfoPlaceholder";

export const metadata: Metadata = { title: "Chính sách xử lý dữ liệu cá nhân — VitaminVui" };

export const dynamic = "force-dynamic";

/** `/chinh-sach-du-lieu` thuộc V2 (pháp chế). Giữ chỗ để ô đồng ý ở form đăng ký có đích. */
export default function PrivacyPlaceholder() {
  return (
    <InfoPlaceholder
      icon={<IconShieldCheck size={32} />}
      title="Chính sách xử lý dữ liệu cá nhân"
      description="Nội dung chính sách đang được hoàn thiện cùng bộ phận pháp chế và sẽ đăng ở đây trước khi website chính thức hoạt động."
    />
  );
}
