import Link from "next/link";
import { IconArrowRight, Logo } from "@vitaminvui/ui/v2";
import { ADMIN_SCREENS } from "@/lib/mock/v2/screens";

export const dynamic = "force-dynamic";

/** Mục lục bản xem trước quản trị v2. */
export default function V2AdminIndex() {
  return (
    <main id="noi-dung" className="mx-auto w-full max-w-4xl flex-1 px-4 pb-16 pt-8 sm:px-6">
      <Logo tagline="Quản trị · Xem trước v2" />
      <h1 className="mt-6 text-title-lg font-extrabold tracking-heading text-ink">Mục lục màn quản trị</h1>
      <p className="mt-2 max-w-2xl text-base text-ink-soft">Dữ liệu mẫu, chưa nối API. Trên mỗi màn, dải “Xem trước v2” cho đổi vai trò và trạng thái.</p>
      <ul className="mt-6 divide-y divide-line rounded-card border border-line bg-surface">
        {ADMIN_SCREENS.map((r) => (
          <li key={r.path}>
            <Link href={r.path} className="focus-ring flex min-h-16 items-center gap-4 rounded-card px-4 py-3 hover:bg-primary-soft">
              <span className="flex-1">
                <span className="block text-base font-semibold text-ink">
                  {r.title} <span className="text-sm font-medium text-ink-soft">· {r.story}</span>
                </span>
                <span className="block text-sm text-ink-soft">{r.note}</span>
              </span>
              <IconArrowRight className="text-ink-soft" />
            </Link>
          </li>
        ))}
      </ul>
    </main>
  );
}
