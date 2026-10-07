import { jsonLd } from "@vitaminvui/api-client";

/**
 * Chèn JSON-LD có nonce CSP (ADR-004 §2.6). `dangerouslySetInnerHTML` là bắt buộc vì React
 * escape nội dung text trong <script> (làm hỏng JSON). An toàn vì chỉ nhận OBJECT dữ liệu
 * có cấu trúc rồi tự serialize qua `jsonLd()` (escape "<" — S23); KHÔNG nhận chuỗi HTML.
 * File này nằm trong allowlist ESLint (eslint.config.mjs) cùng `CourseDescription.tsx`.
 */
export function JsonLd({ data, nonce }: { data: Record<string, unknown>; nonce: string | undefined }) {
  return <script type="application/ld+json" nonce={nonce} dangerouslySetInnerHTML={{ __html: jsonLd(data) }} />;
}
