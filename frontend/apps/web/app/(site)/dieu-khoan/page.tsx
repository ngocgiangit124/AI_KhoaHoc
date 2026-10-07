import type { Metadata } from "next";
import { IconFileText } from "@vitaminvui/ui/v2";
import { InfoPlaceholder } from "@/components/shell/InfoPlaceholder";

export const metadata: Metadata = { title: "Điều khoản sử dụng — VitaminVui" };

export const dynamic = "force-dynamic";

/** `/dieu-khoan` thuộc V2 (pháp chế soạn nội dung). Giữ chỗ để ô đồng ý ở form đăng ký có đích. */
export default function TermsPlaceholder() {
  return (
    <InfoPlaceholder
      icon={<IconFileText size={32} />}
      title="Điều khoản sử dụng"
      description="Nội dung điều khoản đang được hoàn thiện cùng bộ phận pháp chế và sẽ đăng ở đây trước khi website chính thức hoạt động."
    />
  );
}
