"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { Button, Checkbox, Dialog, IconSliders, Select, cx } from "@vitaminvui/ui/v2";
import type { Subject } from "@/lib/catalog/schemas";
import { SORTS, SORT_LABELS, toPageHref, type CatalogQuery, type CatalogSort } from "@/lib/catalog/query";

interface FilterProps {
  basePath: string;
  query: CatalogQuery;
  subjects: Subject[];
  /** `/lop-{grade}`: lớp nằm trong đường dẫn, không đưa `grade` lên query. */
  omitGrade: boolean;
}

function toggle(list: number[], id: number) {
  return list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
}

function SubjectList({ subjects, selected, onToggle, idPrefix }: { subjects: Subject[]; selected: number[]; onToggle: (id: number) => void; idPrefix: string }) {
  if (subjects.length === 0) return <p className="text-sm text-ink-soft">Chưa có chuyên đề.</p>;
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
 * Bộ lọc chuyên đề (OR — khớp ít nhất 1, T10). URL là nguồn sự thật: mọi thay đổi đẩy lên `searchParams` (về trang 1)
 * rồi Server Component render lại — F5/chia sẻ link không mất trạng thái.
 * - `sidebar` (desktop): đổi là áp dụng ngay, hiển thị lạc quan trong lúc chờ.
 * - `sheet` (mobile): nút "Bộ lọc" mở bottom-sheet, bấm "Xem kết quả" mới áp dụng. Nội dung sheet chỉ dựng khi mở
 *   để không trùng ô/nhãn với thanh bên.
 */
export function CatalogFilters({ variant, ...props }: FilterProps & { variant: "sidebar" | "sheet" }) {
  const { basePath, query, subjects, omitGrade } = props;
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<{ subjectIds: number[]; sort: CatalogSort }>({ subjectIds: query.subjectIds, sort: query.sort });
  // Lạc quan cho thanh bên: ô đổi ngay khi bấm, không đợi Server Component render lại.
  const [optimistic, setOptimistic] = useState(query.subjectIds);
  const [prevIds, setPrevIds] = useState(query.subjectIds);
  if (prevIds.join(",") !== query.subjectIds.join(",")) {
    setPrevIds(query.subjectIds);
    setOptimistic(query.subjectIds);
  }

  function go(next: Partial<CatalogQuery>) {
    startTransition(() => router.push(toPageHref(basePath, { ...query, ...next, page: 1 }, omitGrade), { scroll: false }));
  }

  if (variant === "sidebar") {
    return (
      <div className={cx("flex flex-col gap-5", pending && "opacity-70")} aria-busy={pending || undefined}>
        <SubjectList
          subjects={subjects}
          selected={optimistic}
          onToggle={(id) => {
            const next = toggle(optimistic, id);
            setOptimistic(next);
            go({ subjectIds: next });
          }}
          idPrefix="sb"
        />
      </div>
    );
  }

  const activeCount = query.subjectIds.length + (query.sort !== "newest" ? 1 : 0);
  return (
    <>
      <Button
        variant="secondary"
        leadingIcon={<IconSliders size={18} />}
        onClick={() => {
          setDraft({ subjectIds: query.subjectIds, sort: query.sort });
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
            <Button variant="ghost" onClick={() => setDraft({ subjectIds: [], sort: "newest" })}>
              Bỏ chọn tất cả
            </Button>
            <Button
              onClick={() => {
                setOpen(false);
                go({ subjectIds: draft.subjectIds, sort: draft.sort });
              }}
            >
              Xem kết quả
            </Button>
          </>
        }
      >
        {open ? (
          <div className="flex flex-col gap-6">
            <SubjectList
              subjects={subjects}
              selected={draft.subjectIds}
              onToggle={(id) => setDraft((d) => ({ ...d, subjectIds: toggle(d.subjectIds, id) }))}
              idPrefix="sh"
            />
            <div className="flex flex-col gap-1.5">
              <label htmlFor="sheet-sort" className="text-sm font-semibold text-ink">
                Sắp xếp
              </label>
              <Select id="sheet-sort" value={draft.sort} onChange={(e) => setDraft((d) => ({ ...d, sort: e.target.value as CatalogSort }))}>
                {SORTS.map((k) => (
                  <option key={k} value={k}>
                    {SORT_LABELS[k]}
                  </option>
                ))}
              </Select>
            </div>
          </div>
        ) : null}
      </Dialog>
    </>
  );
}

/** Ô sắp xếp (desktop): đổi là áp dụng ngay. */
export function SortSelect({ basePath, query, omitGrade }: Omit<FilterProps, "subjects">) {
  const router = useRouter();
  const [sort, setSort] = useState(query.sort);
  const [prevSort, setPrevSort] = useState(query.sort);
  if (prevSort !== query.sort) {
    setPrevSort(query.sort);
    setSort(query.sort);
  }
  return (
    <div className="flex items-center gap-2">
      <label htmlFor="catalog-sort" className="whitespace-nowrap text-sm font-medium text-ink-soft">
        Sắp xếp
      </label>
      <Select
        id="catalog-sort"
        size="sm"
        value={sort}
        onChange={(e) => {
          const next = e.target.value as CatalogSort;
          setSort(next);
          router.push(toPageHref(basePath, { ...query, sort: next, page: 1 }, omitGrade), { scroll: false });
        }}
        className="w-52"
      >
        {SORTS.map((k) => (
          <option key={k} value={k}>
            {SORT_LABELS[k]}
          </option>
        ))}
      </Select>
    </div>
  );
}
