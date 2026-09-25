"use client";

import type { ReactNode } from "react";
import { Button } from "./Button";

export interface EmptyStateProps {
  icon?: ReactNode;
  title: string;
  description?: string;
  actionLabel?: string;
  onAction?: () => void;
  className?: string;
}

/** Trạng thái rỗng chuẩn (design-system.md §6). */
export function EmptyState({ icon, title, description, actionLabel, onAction, className = "" }: EmptyStateProps) {
  return (
    <div className={`flex flex-col items-center gap-2 rounded-lg border border-dashed border-gray-300 p-10 text-center ${className}`}>
      {icon ? <div className="text-gray-400">{icon}</div> : null}
      <p className="text-base font-semibold text-gray-900">{title}</p>
      {description ? <p className="max-w-sm text-sm text-gray-500">{description}</p> : null}
      {actionLabel && onAction ? (
        <Button variant="outline" size="sm" onClick={onAction} className="mt-2">
          {actionLabel}
        </Button>
      ) : null}
    </div>
  );
}
