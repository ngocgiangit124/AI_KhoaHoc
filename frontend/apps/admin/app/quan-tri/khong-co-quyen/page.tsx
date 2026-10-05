import type { Metadata } from "next";
import { ForbiddenView } from "@/components/shell/ForbiddenView";

export const metadata: Metadata = { title: "Không có quyền — VitaminVui Quản trị" };

export default function ForbiddenPage() {
  return <ForbiddenView />;
}
