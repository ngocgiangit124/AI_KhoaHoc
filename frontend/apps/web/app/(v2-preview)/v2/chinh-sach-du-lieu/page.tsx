import { IconShieldCheck } from "@vitaminvui/ui/v2";
import { InfoPage } from "@/components/v2/info/InfoPage";

export const dynamic = "force-dynamic";

/** `/chinh-sach-du-lieu` thuộc V2 (pháp chế). */
export default function PrivacyPlaceholder() {
  return (
    <InfoPage
      icon={<IconShieldCheck size={32} />}
      title="Chính sách xử lý dữ liệu cá nhân"
      description="Nội dung chính sách đang được hoàn thiện cùng bộ phận pháp chế và sẽ đăng ở đây trước khi website chính thức hoạt động."
    />
  );
}
