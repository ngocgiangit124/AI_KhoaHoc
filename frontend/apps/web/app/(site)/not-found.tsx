import { NotFoundView } from "@/components/shell/NotFoundView";

/** 404 trong nhóm trang công khai (vd. `/lop-99`): khung trang đã có từ `(site)/layout.tsx`. */
export default function SiteNotFound() {
  return <NotFoundView />;
}
