"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import { ToastProvider, UiLinkProvider, type UiLinkProps } from "@vitaminvui/ui/v2";

function NextUiLink({ href, ...rest }: UiLinkProps) {
  return <Link href={href} {...rest} />;
}

/** Provider dùng chung cho mọi trang: liên kết của packages/ui dùng `next/link`; toast v2. */
export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <UiLinkProvider component={NextUiLink}>
      <ToastProvider bottomOffsetClass="bottom-24 lg:bottom-6">{children}</ToastProvider>
    </UiLinkProvider>
  );
}
