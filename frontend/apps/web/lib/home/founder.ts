import type { FounderPosterProps } from "@/components/v2/home/FounderPoster";
import { routes } from "@/lib/routes";

/**
 * Nội dung khối "Người sáng lập" trên trang chủ (US-019 BR10, design-system-v2 §12.7).
 *
 * Nội dung tạm (PO 2026-10-07) — thay khi PO gửi ảnh/tên/câu thật.
 * - Ảnh là hình minh hoạ tự vẽ (giáo viên cách điệu bên bảng ô ly), KHÔNG phải ảnh người thật, không giống ai cụ thể.
 * - Chủ thể chung "Đội ngũ sáng lập", không đặt họ tên người thật; không số liệu, không hứa kết quả (BR5).
 * - Khi có nội dung thật: đặt ảnh 4:5 (1600×2000, WebP/JPG) vào `public/trang-chu/`, sửa `src`/`width`/`height`/`alt`,
 *   `name`, `role`, `quote` (≤ 160 ký tự, đẹp nhất ≤ 120); đổi `heading` về mặc định nếu là một người.
 * - Muốn ẩn khối: đặt `founderPoster = null` (component cũng tự trả `null` khi thiếu ảnh/tên/câu).
 */
export const founderPoster: FounderPosterProps | null = {
  image: {
    src: "/trang-chu/nguoi-sang-lap-minh-hoa.svg",
    alt: "Hình minh hoạ: giáo viên cách điệu đứng bên bảng kẻ ô ly, cầm bút chỉ vào lời giải phương trình bậc hai, bên cạnh là đồ thị parabol",
    width: 1600,
    height: 2000,
    focus: "50% 32%",
  },
  name: "Đội ngũ sáng lập VitaminVui",
  role: "Những người làm VitaminVui",
  quote: "Toán dễ hiểu hơn khi được giảng chậm, rõ từng bước. Chúng tôi làm VitaminVui để đi cùng các em từ lớp 6 đến lớp 12.",
  action: { label: "Xem khóa học", href: routes.catalog },
  heading: "Lời nhắn từ đội ngũ sáng lập",
};
