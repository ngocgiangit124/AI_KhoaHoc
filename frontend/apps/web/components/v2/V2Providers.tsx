"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import { ToastProvider, UiLinkProvider, type UiLinkProps } from "@vitaminvui/ui/v2";

function NextUiLink({ href, ...rest }: UiLinkProps) {
  return <Link href={href} {...rest} />;
}

/** Provider của bản xem trước v2: liên kết trong packages/ui dùng next/link; toast v2. */
export function V2Providers({ children }: { children: ReactNode }) {
  return (
    <UiLinkProvider component={NextUiLink}>
      <ToastProvider bottomOffsetClass="bottom-20 md:bottom-6">{children}</ToastProvider>
    </UiLinkProvider>
  );
}
