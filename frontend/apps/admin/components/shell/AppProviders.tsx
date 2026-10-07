"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import { ToastProvider, UiLinkProvider, type UiLinkProps } from "@vitaminvui/ui/v2";

function NextUiLink({ href, ...rest }: UiLinkProps) {
  return <Link href={href} {...rest} />;
}

/** Provider gốc của admin (design v2): `next/link` cho component trong packages/ui và toast v2. */
export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <UiLinkProvider component={NextUiLink}>
      <ToastProvider>{children}</ToastProvider>
    </UiLinkProvider>
  );
}
