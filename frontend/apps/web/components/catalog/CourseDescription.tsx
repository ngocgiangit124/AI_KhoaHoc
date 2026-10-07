import { sanitizeCourseDescription } from "@/lib/catalog/sanitize";

/**
 * Component DUY NHẤT được render HTML thô (`courses.description`) — luôn qua DOMPurify
 * (`sanitizeCourseDescription`). Nằm trong allowlist ESLint của `no-restricted-syntax`
 * (eslint.config.mjs). KHÔNG thêm nguồn HTML khác vào đây.
 */
export function CourseDescription({ html }: { html: string }) {
  const clean = sanitizeCourseDescription(html);
  if (!clean.trim()) return null;
  return (
    <div
      className="max-w-prose space-y-3 text-base leading-relaxed text-ink [&_a]:text-primary [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-line-strong [&_blockquote]:pl-3 [&_blockquote]:text-ink-soft [&_h2]:text-heading [&_h2]:font-extrabold [&_h3]:text-lg [&_h3]:font-semibold [&_h4]:font-semibold [&_ol]:list-decimal [&_ol]:pl-6 [&_ul]:list-disc [&_ul]:pl-6"
      dangerouslySetInnerHTML={{ __html: clean }}
    />
  );
}
