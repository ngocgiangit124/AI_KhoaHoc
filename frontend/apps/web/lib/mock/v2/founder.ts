import type { FounderPosterProps } from "@/components/v2/home/FounderPoster";
import { routes } from "@/lib/v2/routes";

/**
 * NỘI DUNG MẪU cho khối "Người sáng lập" (trang chủ, quyết định PO 2026-10-06).
 * Bản thật: nội dung cố định trong frontend (không API, không màn quản trị) — PO gửi ảnh + họ tên + vai trò + câu
 * thông điệp, nextjs-dev đặt ảnh vào `apps/web/public/trang-chu/` và thay các giá trị dưới đây.
 * Ảnh dưới đây là hình minh hoạ tự vẽ, KHÔNG phải ảnh người thật; tên và câu chữ là mẫu.
 */
const image = {
  src: "/v2/mau/nguoi-sang-lap-mau.svg",
  alt: "Ảnh minh hoạ mẫu: chân dung cách điệu người sáng lập (chưa phải ảnh thật)",
  width: 1600,
  height: 2000,
} as const;

export const founderSample: FounderPosterProps = {
  image,
  name: "Nguyễn Minh An (tên mẫu)",
  role: "Người sáng lập VitaminVui",
  quote: "Mình làm VitaminVui để em nào cũng có một người giảng lại từng bước, bao nhiêu lần cũng được, cho tới khi hiểu.",
  action: { label: "Xem khóa học", href: routes.catalog },
};

/** Biến thể xem trước: câu dài (> 120 ký tự) → chữ nhỏ hơn một cấp. */
export const founderSampleLong: FounderPosterProps = {
  ...founderSample,
  quote:
    "Hồi đi học, mình từng sợ Toán chỉ vì bỏ lỡ một bài rồi không ai giảng lại. VitaminVui ra đời để em nào cũng có thể xem lại từng bước, làm bài ngay sau khi học và biết chắc mình đã hiểu tới đâu.",
};
