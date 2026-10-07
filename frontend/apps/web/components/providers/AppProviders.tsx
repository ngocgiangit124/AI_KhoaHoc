"use client";

import type { ReactNode } from "react";
import { ToastProvider, UiLinkProvider } from "@vitaminvui/ui/v2";
import { AppLink } from "@/components/shell/AppLink";

/** Provider dùng chung cho mọi trang: liên kết của packages/ui dùng `next/link`; toast v2. */
export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <UiLinkProvider component={AppLink}>
      <ToastProvider bottomOffsetClass="bottom-24 lg:bottom-6">{children}</ToastProvider>
    </UiLinkProvider>
  );
}
