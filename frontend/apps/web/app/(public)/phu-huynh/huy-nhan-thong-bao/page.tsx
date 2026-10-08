import type { Metadata } from "next";
import { UnsubscribeView } from "@/components/privacy/UnsubscribeView";

/** Token nằm trong URL: không gửi Referer đi nơi khác (cũng đặt header ở proxy.ts), không index. */
export const metadata: Metadata = {
  title: "Huỷ nhận thông báo — VitaminVui",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export const dynamic = "force-dynamic";

export default function ParentUnsubscribePage() {
  return (
    <div className="mx-auto w-full max-w-lg px-4 py-10 sm:px-6">
      <UnsubscribeView />
    </div>
  );
}
