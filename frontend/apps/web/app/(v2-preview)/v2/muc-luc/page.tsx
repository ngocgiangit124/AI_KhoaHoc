import Link from "next/link";
import { IconArrowRight, IconExternalLink } from "@vitaminvui/ui/v2";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const WEB: Array<{ title: string; href: string; story: string; note: string }> = [
  { title: "Trang chủ", href: routes.home, story: "US-019, US-020", note: "Hero, chọn lớp, khóa nổi bật, người sáng lập (poster, nội dung mẫu), thầy cô giảng dạy, một buổi học, phụ huynh." },
  { title: "Danh mục khóa học", href: routes.catalog, story: "US-002", note: "Lọc lớp/chuyên đề/từ khóa/sắp xếp trên URL; rỗng, lỗi, đang tải." },
  { title: "Chi tiết khóa học", href: routes.course("hinh-hoc-9-duong-tron"), story: "US-003, US-012", note: "CTA theo viewer_state + paid_checkout_enabled; học thử; outline." },
  { title: "Đăng nhập", href: routes.login, story: "US-001, US-014", note: "Sai thông tin, khoá, bị đăng xuất do thiết bị khác." },
  { title: "Quên mật khẩu", href: routes.forgot, story: "US-015", note: "Bước 1: nhập email/SĐT, thông báo không lộ tài khoản." },
  { title: "Đặt lại mật khẩu (bước 2) — mới", href: routes.resetPassword, story: "US-015", note: "Mã 6 số + mật khẩu mới; mọi lỗi mã hiện như hết hạn + “Gửi lại mã”; mật khẩu phổ biến; 429." },
  { title: "Xác thực OTP — mới", href: routes.verifyOtp, story: "US-001", note: "Mã 6 số, gửi lại có đếm ngược; OTP_INVALID, OTP_EXPIRED, sai 5 lần, 429, 503, hết lượt gửi." },
  { title: "Màn chặn: cần xác thực tài khoản — mới", href: routes.needVerify, story: "US-001 AC9", note: "403 ACCOUNT_NOT_VERIFIED: trang đầy đủ + hộp thoại ở chi tiết khóa." },
  { title: "Phiên bị thay thế (overlay) — mới", href: `${routes.lesson(101, 307)}?trang-thai=phien-thay-the`, story: "US-014", note: "401 SESSION_REPLACED / SESSION_REVOKED khi đang học: hộp thoại chặn, không đóng được." },
  { title: "Đăng ký", href: routes.register, story: "US-001, US-017", note: "Khối phụ huynh không bắt buộc (mở sẵn khi dưới 18 tuổi), 2 ô đồng ý, tóm tắt lỗi." },
  { title: "Trang học video", href: routes.lesson(101, 307), story: "US-006", note: "Khung 16:9, bài trước/tiếp, bài tập của bài, mục lục + tiến độ." },
  { title: "Làm quiz", href: routes.quiz(101, 502), story: "US-007", note: "Công thức, đồng hồ, tự lưu, bảng câu hỏi, nộp bài, hết giờ." },
  { title: "Kết quả quiz", href: routes.quizResult(101, 502), story: "US-007", note: "Điểm, lọc câu sai/bỏ trống, lời giải." },
  { title: "Khóa học của tôi", href: routes.myCourses, story: "US-008, US-012", note: "Học tiếp, đang học / chờ duyệt / không được duyệt." },
  { title: "Tiến độ một khóa", href: routes.myCourse(101), story: "US-008", note: "Bảng điểm bài kiểm tra, trạng thái từng bài." },
  { title: "Tài khoản", href: routes.account, story: "US-015, Bảo mật cụm 1", note: "Đổi email/SĐT bắt buộc mật khẩu hiện tại; sai mật khẩu, 429, email trùng, vừa đổi email (chưa xác thực)." },
  { title: "Giỏ hàng (giữ chỗ V2)", href: routes.cart, story: "V2", note: "Thanh toán tạm khoá: trang giải thích thay cho 404." },
  { title: "Điều khoản / Chính sách dữ liệu (giữ chỗ)", href: routes.terms, story: "V2 pháp chế", note: "Đích của ô đồng ý ở form đăng ký." },
  { title: "Thành phần giao diện", href: routes.gallery, story: "design system", note: "Màu, chữ, công thức, nút, ô nhập, nhãn, hộp thoại, toast." },
];

const ADMIN_ORIGIN = "http://admin-api.localhost:3001";
const ADMIN: Array<{ title: string; path: string; note: string }> = [
  { title: "Mục lục quản trị (đầy đủ)", path: "/v2", note: "Danh sách mọi màn quản trị xem trước." },
  { title: "Danh sách / tạo / sửa khóa học", path: "/v2/quan-tri/khoa-hoc", note: "US-009 — menu theo vai trò, cây chương bài, trạng thái video." },
  { title: "Chuyên đề", path: "/v2/quan-tri/chuyen-de", note: "US-011." },
  { title: "Duyệt đăng ký khóa miễn phí", path: "/v2/quan-tri/duyet-dang-ky", note: "US-012." },
  { title: "Giáo viên trên trang chủ / Hồ sơ của tôi", path: "/v2/quan-tri/giao-vien", note: "US-020 — giới hạn 6, đồng ý công khai." },
  { title: "Mã giảm giá", path: "/v2/quan-tri/ma-giam-gia", note: "US-013." },
  { title: "Tài khoản staff", path: "/v2/quan-tri/tai-khoan", note: "US-016 — mật khẩu một lần, đổi vai trò." },
  { title: "Nhật ký thao tác", path: "/v2/quan-tri/nhat-ky", note: "US-016 — chỉ đọc." },
  { title: "Bài tập của khóa (danh sách quiz) — mới", path: "/v2/quan-tri/khoa-hoc/101/sua?tab=bai-tap", note: "FA5 — quiz theo chương/bài, số câu, thời gian." },
  { title: "Soạn quiz — mới", path: "/v2/quan-tri/khoa-hoc/101/bai-tap/502", note: "FA5 — danh sách câu, form 4 đáp án, xem trước công thức, \\lt/\\gt, lỗi/đang lưu." },
];

/** Mục lục bản xem trước v2: mọi màn hình + biến thể để PO duyệt. */
export default function PreviewIndex() {
  return (
    <>
      <PreviewBar />
      <main id="noi-dung" className="mx-auto w-full max-w-4xl flex-1 px-4 pb-16 pt-8 sm:px-6">
        <h1 className="text-title-lg font-extrabold tracking-heading text-ink">Xem trước design system v2</h1>
        <p className="mt-2 max-w-2xl text-lg text-ink-soft">
          Dữ liệu mẫu, chưa nối API. Mỗi màn có dải “Trạng thái” phía trên để xem các biến thể (đang tải, rỗng, lỗi...). Tài liệu: <code className="font-mono text-base">docs/design/design-system-v2.md</code>.
        </p>

        <h2 className="mt-10 text-heading font-extrabold tracking-heading text-ink">Web học sinh</h2>
        <ul className="mt-4 divide-y divide-line rounded-card border border-line bg-surface">
          {WEB.map((r) => (
            <li key={r.href}>
              <Link href={r.href} className="focus-ring flex min-h-16 items-center gap-4 rounded-card px-4 py-3 hover:bg-primary-soft">
                <span className="flex-1">
                  <span className="block text-base font-semibold text-ink">
                    {r.title} <span className="text-sm font-medium text-ink-soft">· {r.story}</span>
                  </span>
                  <span className="block text-sm text-ink-soft">{r.note}</span>
                </span>
                <IconArrowRight className="text-ink-soft" />
              </Link>
            </li>
          ))}
        </ul>

        <h2 className="mt-10 text-heading font-extrabold tracking-heading text-ink">Quản trị (app admin, origin riêng)</h2>
        <ul className="mt-4 divide-y divide-line rounded-card border border-line bg-surface">
          {ADMIN.map((r) => (
            <li key={r.path}>
              <a href={`${ADMIN_ORIGIN}${r.path}`} className="focus-ring flex min-h-16 items-center gap-4 rounded-card px-4 py-3 hover:bg-primary-soft">
                <span className="flex-1">
                  <span className="block text-base font-semibold text-ink">{r.title}</span>
                  <span className="block text-sm text-ink-soft">{r.note}</span>
                </span>
                <IconExternalLink className="text-ink-soft" title="Mở app quản trị" />
              </a>
            </li>
          ))}
        </ul>
      </main>
    </>
  );
}
