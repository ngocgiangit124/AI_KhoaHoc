"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { Button, Checkbox, Dialog, IconSliders, Select, cx } from "@vitaminvui/ui/v2";
import type { Subject } from "@/lib/mock/v2/types";
import { routes } from "@/lib/v2/routes";

export type SortKey = "newest" | "popular" | "featured";

export const SORT_LABELS: Record<SortKey, string> = {
  featured: "Nổi bật",
  newest: "Mới nhất",
  popular: "Nhiều học sinh nhất",
};

export interface CatalogQuery {
  grade?: number;
  subject_ids: number[];
  q?: string;
  sort: SortKey;
}

function toHref(q: CatalogQuery) {
  return routes.catalogQuery({ grade: q.grade, subject_ids: q.subject_ids, q: q.q, sort: q.sort === "newest" ? undefined : q.sort });
}

/** Danh sách chuyên đề dạng checkbox (OR — khớp ít nhất 1, đúng T10). */
function SubjectList({ subjects, selected, onToggle, idPrefix }: { subjects: Subject[]; selected: number[]; onToggle: (id: number) => void; idPrefix: string }) {
  return (
    <fieldset>
      <legend className="mb-1 text-sm font-semibold text-ink">Chuyên đề</legend>
      <div className="flex flex-col">
        {subjects.map((s) => (
          <Checkbox key={s.id} id={`${idPrefix}-subject-${s.id}`} label={s.name} checked={selected.includes(s.id)} onChange={() => onToggle(s.id)} />
        ))}
      </div>
    </fieldset>
  );
}

/**
 * Bộ lọc danh mục. Mọi giá trị nằm trên URL (`grade`, `subject_ids`, `q`, `sort`) — F5/chia sẻ link
 * giữ nguyên. Desktop: thanh bên đổi là áp dụng ngay. Mobile: bottom-sheet, bấm "Xem kết quả" mới áp dụng.
 */
export function CatalogFilters({ subjects, query, variant }: { subjects: Subject[]; query: CatalogQuery; variant: "sidebar" | "sheet" }) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<CatalogQuery>(query);

  function go(next: CatalogQuery) {
    startTransition(() => router.push(toHref(next), { scroll: false }));
  }

  function toggle(list: number[], id: number) {
    return list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
  }

  if (variant === "sidebar") {
    return (
      <div className={cx("flex flex-col gap-5", pending && "opacity-70")} aria-busy={pending || undefined}>
        <SubjectList subjects={subjects} selected={query.subject_ids} onToggle={(id) => go({ ...query, subject_ids: toggle(query.subject_ids, id) })} idPrefix="sb" />
      </div>
    );
  }

  const activeCount = query.subject_ids.length + (query.sort !== "newest" ? 1 : 0);
  return (
    <>
      <Button
        variant="secondary"
        leadingIcon={<IconSliders size={18} />}
        onClick={() => {
          setDraft(query);
          setOpen(true);
        }}
        aria-haspopup="dialog"
      >
        Bộ lọc{activeCount ? ` (${activeCount})` : ""}
      </Button>
      <Dialog
        open={open}
        onClose={() => setOpen(false)}
        title="Bộ lọc"
        sheetOnMobile
        footer={
          <>
            <Button variant="ghost" onClick={() => setDraft({ ...draft, subject_ids: [], sort: "newest" })}>
              Bỏ chọn tất cả
            </Button>
            <Button
              onClick={() => {
                setOpen(false);
                go(draft);
              }}
            >
              Xem kết quả
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-6">
          <SubjectList subjects={subjects} selected={draft.subject_ids} onToggle={(id) => setDraft({ ...draft, subject_ids: toggle(draft.subject_ids, id) })} idPrefix="sh" />
          <div className="flex flex-col gap-1.5">
            <label htmlFor="sheet-sort" className="text-sm font-semibold text-ink">
              Sắp xếp
            </label>
            <Select id="sheet-sort" value={draft.sort} onChange={(e) => setDraft({ ...draft, sort: e.target.value as SortKey })}>
              {(Object.keys(SORT_LABELS) as SortKey[]).map((k) => (
                <option key={k} value={k}>
                  {SORT_LABELS[k]}
                </option>
              ))}
            </Select>
          </div>
        </div>
      </Dialog>
    </>
  );
}

/** Ô sắp xếp (desktop): đổi là áp dụng ngay. */
export function SortSelect({ query }: { query: CatalogQuery }) {
  const router = useRouter();
  return (
    <div className="flex items-center gap-2">
      <label htmlFor="catalog-sort" className="whitespace-nowrap text-sm font-medium text-ink-soft">
        Sắp xếp
      </label>
      <Select id="catalog-sort" size="sm" value={query.sort} onChange={(e) => router.push(toHref({ ...query, sort: e.target.value as SortKey }), { scroll: false })} className="w-52">
        {(Object.keys(SORT_LABELS) as SortKey[]).map((k) => (
          <option key={k} value={k}>
            {SORT_LABELS[k]}
          </option>
        ))}
      </Select>
    </div>
  );
}
