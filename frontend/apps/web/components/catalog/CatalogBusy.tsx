import { Alert, ButtonLink, IconRotateCcw } from "@vitaminvui/ui/v2";

/** API đang quá tải/tạm lỗi (429, 5xx): thông báo thân thiện, giữ khung trang. Không lộ mã lỗi. */
export function CatalogBusy({ href }: { href: string }) {
  return (
    <div className="mx-auto w-full max-w-xl px-4 py-12">
      {/* Trang tạm thời: không để bot lập chỉ mục (Next không đặt được 503 từ page). React 19 đưa <meta> lên <head>. */}
      <meta name="robots" content="noindex" />
      <Alert
        tone="info"
        title="Hệ thống đang bận"
        action={
          <ButtonLink href={href} variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />}>
            Thử lại
          </ButtonLink>
        }
      >
        Có nhiều bạn đang truy cập cùng lúc. Vui lòng đợi một chút rồi thử lại.
      </Alert>
    </div>
  );
}
