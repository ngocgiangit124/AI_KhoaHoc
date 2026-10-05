import { redirect } from "next/navigation";
import { DEFAULT_LANDING } from "@/lib/nav";

export default function RootPage() {
  redirect(DEFAULT_LANDING);
}
