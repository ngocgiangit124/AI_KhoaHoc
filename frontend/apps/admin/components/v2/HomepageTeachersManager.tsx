"use client";

import Link from "next/link";
import { useState } from "react";
import { Alert, Avatar, Badge, IconButton, IconChevronDown, IconChevronUp, IconPencil, Switch, cx } from "@vitaminvui/ui/v2";
import { HOMEPAGE_TEACHER_LIMIT, missingReasons, type TeacherProfile } from "@/lib/mock/v2/teacher-profiles";

/**
 * Admin/QLT chọn giáo viên hiện ở trang chủ (US-020 BR3, AC9–AC11).
 * - Bật người thứ 7 bị từ chối với thông báo rõ, trạng thái cũ giữ nguyên.
 * - Bật người chưa đủ điều kiện vẫn được, nhưng nhãn "Chưa hiện: lý do" (không hiển thị giả).
 * - Thứ tự = thứ tự danh sách "Đang bật"; nút Lên/Xuống (bàn phím dùng được, không cần kéo-thả).
 * - Không có thao tác đồng ý thay giáo viên; cột đồng ý chỉ để xem.
 * TODO(dev): PATCH bật/tắt + homepage_order; 422/409 khi vượt 6 (kiểm trong transaction ở backend).
 */
export function HomepageTeachersManager({ initial, editBase, editQuery = "" }: { initial: TeacherProfile[]; editBase: string; editQuery?: string }) {
  const editHref = (id: number) => `${editBase}/${id}${editQuery}`;
  const [items, setItems] = useState(initial);
  const [error, setError] = useState<string | null>(null);

  const enabled = items.filter((t) => t.show_on_homepage).sort((a, b) => (a.homepage_order ?? 999) - (b.homepage_order ?? 999) || a.id - b.id);
  const others = items.filter((t) => !t.show_on_homepage);

  function renumber(list: TeacherProfile[]) {
    const order = new Map(list.map((t, i) => [t.id, i + 1]));
    setItems((prev) => prev.map((t) => (order.has(t.id) ? { ...t, homepage_order: order.get(t.id) ?? null } : t)));
  }

  function toggle(t: TeacherProfile, next: boolean) {
    if (next && enabled.length >= HOMEPAGE_TEACHER_LIMIT) {
      setError(`Trang chủ chỉ hiển thị tối đa ${HOMEPAGE_TEACHER_LIMIT} giáo viên. Hãy tắt bớt một người trước.`);
      return;
    }
    setError(null);
    setItems((prev) =>
      prev.map((x) => (x.id === t.id ? { ...x, show_on_homepage: next, homepage_order: next ? enabled.length + 1 : null } : x)),
    );
  }

  function move(index: number, dir: -1 | 1) {
    const list = [...enabled];
    const target = index + dir;
    if (target < 0 || target >= list.length) return;
    const a = list[index] as TeacherProfile;
    list[index] = list[target] as TeacherProfile;
    list[target] = a;
    renumber(list);
  }

  const row = (t: TeacherProfile, index?: number) => {
    const reasons = missingReasons(t, false);
    const consent = t.public_profile_consent_at !== null;
    return (
      <li key={t.id} className="flex flex-col gap-3 border-b border-line px-3 py-3 last:border-b-0 sm:flex-row sm:items-center sm:px-4">
        <div className="flex min-w-0 flex-1 items-center gap-3">
          {index !== undefined ? (
            <span className="num w-6 shrink-0 text-center text-base font-extrabold text-primary" aria-label={`Vị trí ${index + 1}`}>
              {index + 1}
            </span>
          ) : null}
          <Avatar name={t.name} />
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-ink">{t.name}</p>
            <p className="truncate text-xs text-ink-soft">
              {t.headline ?? "Chưa có dòng chuyên môn"} · {t.published_courses_count} khóa đang bán
            </p>
            <div className="mt-1 flex flex-wrap gap-1.5">
              <Badge size="sm" tone={consent ? "success" : "warning"}>
                {consent ? "Đã đồng ý" : "Chưa đồng ý"}
              </Badge>
              {t.show_on_homepage ? (
                reasons.length === 0 ? (
                  <Badge size="sm" tone="primary" dot>
                    Đang hiện
                  </Badge>
                ) : (
                  <Badge size="sm" tone="warning" dot>
                    {`Chưa hiện: ${reasons.join(", ")}`}
                  </Badge>
                )
              ) : reasons.length ? (
                <span className="text-xs text-ink-soft">Thiếu: {reasons.join(", ")}</span>
              ) : null}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-1 pl-9 sm:pl-0">
          {index !== undefined ? (
            <>
              <IconButton size="sm" label={`Đưa ${t.name} lên`} icon={<IconChevronUp size={18} />} disabled={index === 0} onClick={() => move(index, -1)} />
              <IconButton size="sm" label={`Đưa ${t.name} xuống`} icon={<IconChevronDown size={18} />} disabled={index === enabled.length - 1} onClick={() => move(index, 1)} />
            </>
          ) : null}
          <Switch checked={t.show_on_homepage} label={`Hiển thị ${t.name} trên trang chủ`} onCheckedChange={(next) => toggle(t, next)} />
          <Link href={editHref(t.id)} className="focus-ring inline-flex h-9 items-center gap-1.5 rounded-control px-2 text-sm font-semibold text-primary hover:bg-primary-soft">
            <IconPencil size={16} />
            Sửa hồ sơ
          </Link>
        </div>
      </li>
    );
  };

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center gap-3">
        <Badge tone={enabled.length >= HOMEPAGE_TEACHER_LIMIT ? "warning" : "primary"} size="md">
          {`Đang bật ${enabled.length}/${HOMEPAGE_TEACHER_LIMIT}`}
        </Badge>
        {enabled.length >= HOMEPAGE_TEACHER_LIMIT ? <span className="text-sm text-ink-soft">Đã đủ {HOMEPAGE_TEACHER_LIMIT} người. Tắt một người trước khi bật người khác.</span> : null}
      </div>
      {error ? (
        <Alert tone="danger" title={error}>
          Trạng thái các giáo viên không thay đổi.
        </Alert>
      ) : null}

      <section aria-labelledby="dang-bat" className="rounded-card border border-line bg-surface">
        <h2 id="dang-bat" className="border-b border-line px-4 py-3 text-sm font-semibold text-ink">
          Đang bật — thứ tự trên trang chủ
        </h2>
        {enabled.length ? <ol>{enabled.map((t, i) => row(t, i))}</ol> : <p className="px-4 py-4 text-sm text-ink-soft">Chưa bật giáo viên nào. Khu vực giáo viên sẽ ẩn trên trang chủ.</p>}
      </section>

      <section aria-labelledby="khac" className={cx("rounded-card border border-line bg-surface")}>
        <h2 id="khac" className="border-b border-line px-4 py-3 text-sm font-semibold text-ink">
          Giáo viên khác
        </h2>
        {others.length ? <ul>{others.map((t) => row(t))}</ul> : <p className="px-4 py-4 text-sm text-ink-soft">Mọi giáo viên đều đang bật.</p>}
      </section>
    </div>
  );
}
