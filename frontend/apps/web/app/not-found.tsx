import { NotFoundView } from "@/components/shell/NotFoundView";
import { SiteShell } from "@/components/shell/SiteShell";

/** 404 cho URL không thuộc nhóm route nào: tự dựng khung trang (vì nằm ngoài layout của `(site)`). */
export default function NotFound() {
  return (
    <SiteShell>
      <NotFoundView />
    </SiteShell>
  );
}
