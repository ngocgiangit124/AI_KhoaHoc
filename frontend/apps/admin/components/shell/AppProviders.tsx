"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import { ToastProvider as LegacyToastProvider } from "@vitaminvui/ui";
import { ToastProvider, UiLinkProvider, type UiLinkProps } from "@vitaminvui/ui/v2";

function NextUiLink({ href, ...rest }: UiLinkProps) {
  return <Link href={href} {...rest} />;
}

/**
 * Provider gốc của admin (design v2): `next/link` cho component trong packages/ui và toast v2.
 * `LegacyToastProvider` (v1) chỉ còn để các màn chưa làm lại theo v2 (FA3 `components/courses/*`) vẫn gọi được
 * `useToast` của `@vitaminvui/ui`; gỡ khi FA3 chuyển sang `@vitaminvui/ui/v2`.
 * TODO(FA3): gỡ `LegacyToastProvider` khi `components/courses/*` dùng toast v2.
 */
export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <UiLinkProvider component={NextUiLink}>
      <LegacyToastProvider>
        <ToastProvider>{children}</ToastProvider>
      </LegacyToastProvider>
    </UiLinkProvider>
  );
}
