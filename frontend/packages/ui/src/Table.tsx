import type { ReactNode } from "react";
import { Skeleton } from "./Skeleton";

export interface TableColumn<Row> {
  key: string;
  header: string;
  render?: (row: Row) => ReactNode;
  className?: string;
}

export interface TableProps<Row> {
  columns: TableColumn<Row>[];
  rows: Row[];
  rowKey: (row: Row) => string | number;
  isLoading?: boolean;
  /** Số dòng skeleton khi `isLoading` (mặc định 5). */
  loadingRowCount?: number;
  emptyMessage?: string;
  className?: string;
}

/**
 * Bảng dữ liệu tối thiểu (tasks.md FE0 — `packages/ui`: "Table"). Đây là bản nền cho
 * `<DataTable>` đầy đủ hơn của design-system.md §5.1 (slot filter, header sticky) — sẽ mở
 * rộng dần ở các task FA dùng bảng quản trị (đơn hàng, mã giảm giá...).
 */
export function Table<Row>({
  columns,
  rows,
  rowKey,
  isLoading = false,
  loadingRowCount = 5,
  emptyMessage = "Chưa có dữ liệu",
  className = "",
}: TableProps<Row>) {
  return (
    <div className={`overflow-x-auto rounded-lg border border-gray-200 ${className}`}>
      <table className="min-w-full divide-y divide-gray-200 text-sm">
        <thead className="bg-gray-50">
          <tr>
            {columns.map((column) => (
              <th
                key={column.key}
                scope="col"
                className={`px-4 py-3 text-left font-semibold text-gray-700 ${column.className ?? ""}`}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100 bg-white">
          {isLoading ? (
            Array.from({ length: loadingRowCount }).map((_, i) => (
              <tr key={`skeleton-${i}`}>
                <td colSpan={columns.length} className="px-4 py-3">
                  <Skeleton variant="table-row" />
                </td>
              </tr>
            ))
          ) : rows.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className="px-4 py-8 text-center text-gray-500">
                {emptyMessage}
              </td>
            </tr>
          ) : (
            rows.map((row) => (
              <tr key={rowKey(row)}>
                {columns.map((column) => (
                  <td key={column.key} className={`px-4 py-3 text-gray-700 ${column.className ?? ""}`}>
                    {column.render ? column.render(row) : String((row as Record<string, unknown>)[column.key] ?? "")}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}
