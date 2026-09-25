import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Table, type TableColumn } from "./Table";

interface Row {
  id: number;
  name: string;
  status: string;
}

const columns: TableColumn<Row>[] = [
  { key: "name", header: "Tên" },
  { key: "status", header: "Trạng thái", render: (row) => row.status.toUpperCase() },
];

describe("Table", () => {
  it("hiển thị đúng dữ liệu theo columns/rows", () => {
    render(
      <Table<Row>
        columns={columns}
        rows={[{ id: 1, name: "Toán 6", status: "active" }]}
        rowKey={(row) => row.id}
      />,
    );

    expect(screen.getByText("Toán 6")).toBeInTheDocument();
    expect(screen.getByText("ACTIVE")).toBeInTheDocument();
  });

  it("hiện skeleton khi isLoading, không hiện rows/empty message", () => {
    render(<Table<Row> columns={columns} rows={[]} rowKey={(row) => row.id} isLoading />);
    expect(screen.queryByText("Chưa có dữ liệu")).not.toBeInTheDocument();
  });

  it("hiện emptyMessage khi rows rỗng và không loading", () => {
    render(<Table<Row> columns={columns} rows={[]} rowKey={(row) => row.id} />);
    expect(screen.getByText("Chưa có dữ liệu")).toBeInTheDocument();
  });
});
