import { Badge, IconCheckCircle, IconCircle } from "@vitaminvui/ui/v2";
import { checklistFor, notShownLabel } from "@/lib/teacher-profiles/reasons";
import type { TeacherProfile } from "@/lib/teacher-profiles/types";

/** Trạng thái hiển thị ở trang chủ + mục kiểm điều kiện (US-020 BR2, AC1/AC9). Tính theo bản ĐÃ LƯU trên server. */
export function ProfileStatusCard({ profile }: { profile: TeacherProfile }) {
  const { visible, reasons } = profile.homepage_status;
  const items = checklistFor(reasons);
  return (
    <section aria-labelledby="trang-thai-trang-chu" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id="trang-thai-trang-chu" className="text-base font-semibold text-ink">
          Trang chủ
        </h2>
        {visible ? (
          <Badge tone="success" dot size="sm">
            Đang hiển thị
          </Badge>
        ) : (
          <Badge tone="warning" dot size="sm">
            Chưa hiển thị
          </Badge>
        )}
      </div>
      <ul className="flex flex-col gap-1.5">
        {items.map((c) => (
          <li key={c.key} className="flex items-center gap-2 text-sm">
            {c.ok ? <IconCheckCircle size={18} className="text-success" /> : <IconCircle size={18} className="text-line-strong" />}
            <span className={c.ok ? "text-ink" : "font-semibold text-ink"}>{c.label}</span>
            <span className="sr-only">{c.ok ? ": đạt" : ": chưa đạt"}</span>
          </li>
        ))}
      </ul>
      {!visible ? (
        <p className="text-sm text-ink-soft" data-testid="not-shown-reasons">
          {notShownLabel(reasons)}. Thiếu một điều kiện thì không hiện, học sinh không thấy thông báo lỗi nào.
        </p>
      ) : null}
      <p className="text-xs text-ink-soft">Trạng thái tính theo bản đã lưu, thay đổi chưa lưu chưa được tính.</p>
    </section>
  );
}
