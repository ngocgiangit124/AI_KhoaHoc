import type { ReactNode } from "react";

export interface CardProps {
  children: ReactNode;
  className?: string;
}

/** Khung nội dung bo góc, có shadow nhẹ (design-system.md §5.1). */
export function Card({ children, className = "" }: CardProps) {
  return (
    <div className={`rounded-xl border border-gray-200 bg-white p-4 shadow-sm md:p-6 ${className}`}>
      {children}
    </div>
  );
}
