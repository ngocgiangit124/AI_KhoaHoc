import Image from "next/image";
import { ButtonLink, IconArrowRight, cx } from "@vitaminvui/ui/v2";

export interface FounderPosterImage {
  /** Ảnh tĩnh trong `public/` (vd. `/trang-chu/nguoi-sang-lap.webp`). Tỉ lệ gốc 4:5. */
  src: string;
  alt: string;
  /** Kích thước gốc của file ảnh (px) — chỉ để trình duyệt biết tỉ lệ; khung hiển thị do khối quyết định. */
  width: number;
  height: number;
  /**
   * Điểm giữ lại khi khung cắt ảnh (CSS `object-position`). Mặc định "50% 30%": ưu tiên phần mặt ở
   * nửa trên. Chỉ đổi khi mặt trong ảnh PO gửi lệch nhiều khỏi vùng an toàn (design-system-v2 §12.6).
   */
  focus?: string;
}

export interface FounderPosterProps {
  image: FounderPosterImage;
  name: string;
  /** Vai trò, vd. "Người sáng lập VitaminVui". */
  role: string;
  /** Câu thông điệp, văn bản thuần. Khuyến nghị ≤ 160 ký tự; dài hơn 120 ký tự tự giảm một cấp chữ. */
  quote: string;
  /** Nút tuỳ chọn. Không truyền → không có nút. */
  action?: { label: string; href: string };
  /** Tiêu đề khu vực cho trình đọc màn hình (ẩn khỏi mắt nhìn). */
  heading?: string;
}

const LONG_QUOTE = 120;

/**
 * Poster người sáng lập trên trang chủ (US-019, quyết định PO 2026-10-06): nội dung tĩnh, đặt ngay sau
 * "Khóa học nổi bật". Bố cục chia đôi: ảnh chân dung tràn mép một bên, thông điệp trên nền mực tím một bên.
 * - Mobile: ảnh 4:5 ở trên, chữ ở dưới. 768–1023px: ảnh 5/12 + chữ 7/12; từ 1024px: 6/12 + 6/12; ảnh cao ít nhất 4:5 và
 *   cao theo cột chữ khi chữ dài hơn (`w-full` + `self-stretch`: ảnh cắt hai bên bằng object-cover, không tràn sang cột chữ).
 * - Chữ không bao giờ nằm đè lên ảnh → tương phản không phụ thuộc ảnh PO gửi.
 * - Thiếu ảnh/tên/câu → không render gì (không tiêu đề, không khoảng trắng).
 * Server Component, không có tương tác.
 */
export function FounderPoster({ image, name, role, quote, action, heading = "Lời nhắn từ người sáng lập" }: FounderPosterProps) {
  if (!image.src || !name.trim() || !quote.trim()) return null;
  const long = quote.trim().length > LONG_QUOTE;

  return (
    <section aria-labelledby="nguoi-sang-lap-title" className="mx-auto max-w-6xl px-4 pt-12 sm:px-6">
      <h2 id="nguoi-sang-lap-title" className="sr-only">
        {heading}
      </h2>
      {/* Viền focus trong khối đổi sang màu chữ trên nền tím (>= 7:1) thay vì màu tím mặc định. */}
      <div className="grid overflow-hidden rounded-sheet bg-primary text-on-primary [--vv-focus:var(--vv-on-primary)] md:grid-cols-12">
        <div className="relative aspect-[4/5] w-full bg-primary-soft md:col-span-5 md:self-stretch lg:col-span-6">
          <Image
            src={image.src}
            alt={image.alt}
            width={image.width}
            height={image.height}
            loading="lazy"
            sizes="(min-width: 1152px) 552px, (min-width: 1024px) 50vw, (min-width: 768px) 42vw, 100vw"
            className="absolute inset-0 size-full object-cover"
            style={{ objectPosition: image.focus ?? "50% 30%" }}
          />
        </div>

        <div className="flex flex-col justify-center gap-6 px-6 pb-8 pt-6 sm:px-10 sm:pb-10 md:col-span-7 md:p-8 lg:col-span-6 lg:p-14">
          <figure className="flex flex-col gap-6">
            <svg aria-hidden="true" viewBox="0 0 48 36" className="h-9 w-12 fill-accent md:h-12 md:w-16">
              <path d="M0 36V22C0 9.5 6.2 2.2 18.6 0l2 5.4C13.7 7.4 10.4 11.3 10 17h9v19H0Zm27 0V22C27 9.5 33.2 2.2 45.6 0l2 5.4C40.7 7.4 37.4 11.3 37 17h9v19H27Z" />
            </svg>
            <blockquote>
              <p
                className={cx(
                  "font-semibold tracking-heading text-pretty",
                  long ? "text-heading lg:text-heading-lg" : "text-title lg:text-title-lg",
                )}
              >
                {quote}
              </p>
            </blockquote>
            <figcaption className="flex flex-col gap-1 border-t border-on-primary/30 pt-5">
              <span className="text-xl font-extrabold leading-snug">{name}</span>
              <span className="text-base font-medium">{role}</span>
            </figcaption>
          </figure>
          {action ? (
            <div>
              <ButtonLink href={action.href} variant="secondary" size="lg" trailingIcon={<IconArrowRight size={18} />} className="max-w-full">
                {action.label}
              </ButtonLink>
            </div>
          ) : null}
        </div>
      </div>
    </section>
  );
}
