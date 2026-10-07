"use client";

import { CatalogError } from "@/components/catalog/CatalogError";

export default function Error(props: { error: Error & { digest?: string }; reset: () => void }) {
  return <CatalogError {...props} />;
}
