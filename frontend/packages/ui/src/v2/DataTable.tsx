import type { ReactNode } from "react";
import { cx } from "./cx";
import { Skeleton } from "./Skeleton";

export interface Column<T> {
  key: string;
  header: ReactNode;
  cell: (row: T) => ReactNode;
  /** Ẩn cột ít quan trọng trên màn hẹp. */
  hideBelow?: "md" | "lg" | "xl" | "2xl";
  align?: "left" | "right";
  className?: string;
}

export interface DataTableProps<T> {
  /** Mô tả bảng cho trình đọc màn hình. */
  caption: string;
  columns: Array<Column<T>>;
  rows: T[];
  rowKey: (row: T) => string | number;
  /** Đang tải: hiện N dòng giữ chỗ cùng chiều cao dòng thật. */
  loadingRows?: number;
  /** Nội dung khi rỗng (EmptyState size="inline"). */
  empty?: ReactNode;
  density?: "compact" | "comfortable";
}

const HIDE = { md: "hidden md:table-cell", lg: "hidden lg:table-cell", xl: "hidden xl:table-cell", "2xl": "hidden 2xl:table-cell" } as const;

/**
 * Bảng quản trị: tiêu đề dính khi cuộn, chữ 14px, dòng 52px (comfortable) hoặc 44px (compact),
 * số căn phải + tabular-nums. Bảng rộng cuộn ngang trong khung (không làm vỡ trang).
 */
export function DataTable<T>({ caption, columns, rows, rowKey, loadingRows, empty, density = "comfortable" }: DataTableProps<T>) {
  const cellPad = density === "compact" ? "px-3 py-2" : "px-4 py-3";
  const loading = loadingRows !== undefined;
  return (
    <div className="overflow-x-auto rounded-card border border-line bg-surface">
      <table className="w-full border-collapse text-left text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead className="sticky top-0 z-10 bg-sunken">
          <tr>
            {columns.map((c) => (
              <th
                key={c.key}
                scope="col"
                className={cx(cellPad, "whitespace-nowrap font-semibold text-ink-soft", c.align === "right" && "text-right", c.hideBelow && HIDE[c.hideBelow], c.className)}
              >
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody aria-busy={loading || undefined}>
          {loading
            ? Array.from({ length: loadingRows }).map((_, i) => (
                <tr key={`sk-${i}`} className="border-t border-line">
                  {columns.map((c) => (
                    <td key={c.key} className={cx(cellPad, c.hideBelow && HIDE[c.hideBelow])}>
                      <Skeleton className="h-5 w-full max-w-40" />
                    </td>
                  ))}
                </tr>
              ))
            : rows.map((row) => (
                <tr key={rowKey(row)} className="border-t border-line align-middle hover:bg-paper">
                  {columns.map((c) => (
                    <td
                      key={c.key}
                      className={cx(cellPad, "text-ink", c.align === "right" && "num text-right", c.hideBelow && HIDE[c.hideBelow], c.className)}
                    >
                      {c.cell(row)}
                    </td>
                  ))}
                </tr>
              ))}
          {!loading && rows.length === 0 && empty ? (
            <tr>
              <td colSpan={columns.length} className="p-4">
                {empty}
              </td>
            </tr>
          ) : null}
        </tbody>
      </table>
    </div>
  );
}
