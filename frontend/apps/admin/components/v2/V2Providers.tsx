"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import { ToastProvider, UiLinkProvider, type UiLinkProps } from "@vitaminvui/ui/v2";

function NextUiLink({ href, ...rest }: UiLinkProps) {
  return <Link href={href} {...rest} />;
}

export function V2Providers({ children }: { children: ReactNode }) {
  return (
    <UiLinkProvider component={NextUiLink}>
      <ToastProvider>{children}</ToastProvider>
    </UiLinkProvider>
  );
}
