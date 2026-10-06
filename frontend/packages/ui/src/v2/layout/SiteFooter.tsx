import { UiLink } from "../Link";
import { Logo } from "../Logo";

export interface FooterGroup {
  title: string;
  links: Array<{ href: string; label: string }>;
}

/** Chân trang gọn: logo + câu mô tả + 2–3 nhóm liên kết. Không mạng xã hội/số liệu trang trí. */
export function SiteFooter({ homeHref, groups, note }: { homeHref: string; groups: FooterGroup[]; note?: string }) {
  return (
    <footer className="border-t border-line bg-surface">
      <div className="mx-auto grid max-w-6xl gap-8 px-4 py-10 sm:px-6 md:grid-cols-[1.5fr_repeat(3,1fr)]">
        <div className="flex flex-col gap-3">
          <UiLink href={homeHref} className="focus-ring w-fit rounded-control" aria-label="VitaminVui — Trang chủ">
            <Logo />
          </UiLink>
          <p className="max-w-xs text-sm text-ink-soft">Khóa học Toán lớp 6–12 trực tuyến: video bài giảng, trắc nghiệm sau mỗi bài và tiến độ học của riêng bạn.</p>
        </div>
        {groups.map((g) => (
          <div key={g.title} className="flex flex-col gap-2">
            <h2 className="text-sm font-semibold text-ink">{g.title}</h2>
            <ul className="flex flex-col gap-1">
              {g.links.map((l) => (
                <li key={l.href + l.label}>
                  <UiLink href={l.href} className="focus-ring inline-flex min-h-9 items-center rounded text-sm text-ink-soft hover:text-primary hover:underline">
                    {l.label}
                  </UiLink>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      {note ? <p className="border-t border-line px-4 py-4 text-center text-sm text-ink-soft">{note}</p> : null}
    </footer>
  );
}
