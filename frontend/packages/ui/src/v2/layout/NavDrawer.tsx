"use client";

import { useState, type ReactNode } from "react";
import { IconButton } from "../Button";
import { Dialog } from "../Dialog";
import { IconMenu } from "../icons";

/** Nút mở menu (mobile) + ngăn kéo dạng hộp thoại. Nội dung menu do server render và truyền vào. */
export function NavDrawer({ title, children, label = "Mở menu" }: { title: string; children: ReactNode; label?: string }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <IconButton label={label} icon={<IconMenu />} onClick={() => setOpen(true)} aria-expanded={open} aria-haspopup="dialog" />
      <Dialog open={open} onClose={() => setOpen(false)} title={title} sheetOnMobile>
        {/* Bấm liên kết bất kỳ thì đóng menu. */}
        <div
          onClick={(e) => {
            if ((e.target as HTMLElement).closest("a")) setOpen(false);
          }}
        >
          {children}
        </div>
      </Dialog>
    </>
  );
}
