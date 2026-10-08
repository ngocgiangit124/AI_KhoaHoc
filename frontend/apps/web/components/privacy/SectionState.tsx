import type { ReactNode } from "react";
import { Alert, Button, Skeleton } from "@vitaminvui/ui/v2";
import type { Loaded } from "./useLoad";

/** Khung chung cho khối đang tải/lỗi: Skeleton, hoặc Alert + "Thử lại". Khi `ok` thì gọi `children(data)`. */
export function SectionState<T>({ state, reload, children }: { state: Loaded<T>; reload: () => void; children: (data: T) => ReactNode }) {
  if (state.status === "loading") {
    return (
      <div className="flex flex-col gap-3" aria-busy="true">
        <span className="sr-only" role="status">
          Đang tải…
        </span>
        <Skeleton className="h-5 w-2/3" />
        <Skeleton className="h-5 w-1/2" />
      </div>
    );
  }
  if (state.status === "failed") {
    return (
      <Alert tone="danger" action={<Button variant="secondary" onClick={reload}>Thử lại</Button>}>
        {state.message}
      </Alert>
    );
  }
  return <>{children(state.data)}</>;
}
