import Link from "next/link";
import { ThemeSwitch, cx } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

export interface PreviewVariant {
  label: string;
  href: string;
  current?: boolean;
}

/**
 * Dải công cụ CHỈ có ở bản xem trước: về mục lục, chọn biến thể trạng thái của màn hình, đổi sáng/tối.
 * Không thuộc thiết kế sản phẩm — nextjs-dev bỏ khi dựng route thật.
 */
export function PreviewBar({
  variants = [],
  note,
  groups = [],
}: {
  variants?: PreviewVariant[];
  note?: string;
  /** Nhiều nhóm biến thể độc lập (ví dụ trang chủ: khóa nổi bật × giáo viên). */
  groups?: Array<{ label: string; variants: PreviewVariant[] }>;
}) {
  const all = variants.length ? [{ label: "Trạng thái", variants }, ...groups] : groups;
  return (
    <div className="border-b border-dashed border-line-strong bg-sunken text-sm">
      <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2 sm:px-6">
        <span className="font-semibold text-ink">Xem trước v2</span>
        <Link href={routes.index} className="focus-ring rounded font-semibold text-primary underline underline-offset-4">
          Mục lục
        </Link>
        {all.map((g) => (
          <nav key={g.label} aria-label={`Biến thể: ${g.label}`} className="flex flex-wrap items-center gap-1.5">
            <span className="text-ink-soft">{g.label}:</span>
            {g.variants.map((v) => (
              <Link
                key={v.href}
                href={v.href}
                aria-current={v.current ? "true" : undefined}
                scroll={false}
                className={cx(
                  "focus-ring inline-flex min-h-8 items-center rounded-full border px-2.5 font-medium",
                  v.current ? "border-primary bg-primary text-on-primary" : "border-line-strong bg-surface text-ink hover:border-primary",
                )}
              >
                {v.label}
              </Link>
            ))}
          </nav>
        ))}
        {note ? <span className="text-ink-soft">{note}</span> : null}
        <ThemeSwitch className="ml-auto" />
      </div>
    </div>
  );
}
