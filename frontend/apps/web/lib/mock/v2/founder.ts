import type { FounderPosterProps } from "@/components/v2/home/FounderPoster";
import { founderPoster } from "@/lib/home/founder";
import { routes } from "@/lib/v2/routes";

/**
 * Biến thể xem trước cho khối "Người sáng lập" (`/v2?nsl=`). Mặc định dùng đúng nội dung tạm của app thật
 * (`lib/home/founder.ts`, PO 2026-10-07); chỉ đổi đích nút sang danh mục bản xem trước để không rời `/v2`.
 */
const base: FounderPosterProps = {
  ...(founderPoster as FounderPosterProps),
  action: { label: "Xem khóa học", href: routes.catalog },
};

export const founderSample: FounderPosterProps = base;

/** Câu dài (> 120 ký tự, ≤ 160) → chữ nhỏ hơn một cấp. */
export const founderSampleLong: FounderPosterProps = {
  ...base,
  quote:
    "Toán dễ hiểu hơn khi được giảng chậm, rõ từng bước. Chúng tôi làm VitaminVui để các em lớp 6–12 xem lại từng bài bao nhiêu lần cũng được, cho tới khi hiểu.",
};

/**
 * Ảnh mẫu có khung vùng an toàn cho mặt (§12.7 "Yêu cầu ảnh PO gửi") — chỉ để PO/nextjs-dev đối chiếu khi
 * đặt ảnh chân dung thật; KHÔNG dùng ở trang thật.
 */
export const founderSafeZoneGuide: FounderPosterProps = {
  ...base,
  image: {
    src: "/v2/mau/nguoi-sang-lap-mau.svg",
    alt: "Ảnh mẫu có khung vùng an toàn cho khuôn mặt (chỉ dùng ở bản xem trước)",
    width: 1600,
    height: 2000,
  },
};
