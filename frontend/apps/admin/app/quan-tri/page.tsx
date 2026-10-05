import type { Metadata } from "next";
import { Dashboard } from "@/components/shell/Dashboard";

export const metadata: Metadata = { title: "Tổng quan — VitaminVui Quản trị" };

export default function AdminHomePage() {
  return <Dashboard />;
}
