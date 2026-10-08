import Link from "next/link";
import { routes } from "@/lib/routes";
import { PolicySection } from "./PolicyPage";

export function TermsContent() {
  return (
    <>
      <PolicySection title="1. VitaminVui là gì">
        <p>VitaminVui là nền tảng học Toán trực tuyến cho học sinh lớp 6 đến lớp 12, gồm bài giảng video, bài tập trắc nghiệm và theo dõi tiến độ học.</p>
      </PolicySection>
      <PolicySection title="2. Tài khoản">
        <ul className="list-disc pl-5">
          <li>Bạn cung cấp thông tin đúng và giữ bí mật mật khẩu. Mỗi tài khoản đăng nhập trên một thiết bị tại một thời điểm.</li>
          <li>Thông tin liên hệ phụ huynh là không bắt buộc. Nếu bạn nhập email phụ huynh, VitaminVui sẽ gửi thư thông báo cho phụ huynh (xem Chính sách xử lý dữ liệu cá nhân).</li>
        </ul>
      </PolicySection>
      <PolicySection title="3. Sử dụng khóa học">
        <ul className="list-disc pl-5">
          <li>Khóa học và bài giảng chỉ dùng cho việc học của bạn; không sao chép, chia sẻ tài khoản hay phát tán lại nội dung.</li>
          <li>Khóa có phí chỉ mở khi thanh toán thành công. Khóa miễn phí có thể cần được duyệt.</li>
        </ul>
      </PolicySection>
      <PolicySection title="4. Quyền của bạn đối với dữ liệu">
        <p>
          Bạn có thể xem các đồng ý đã cho, tải dữ liệu của mình và xoá tài khoản tại{" "}
          <Link href={routes.privacyData} className="focus-ring rounded font-semibold text-primary underline">Quyền dữ liệu cá nhân</Link>. Chi tiết nằm trong{" "}
          <Link href={routes.privacy} className="focus-ring rounded font-semibold text-primary underline">Chính sách xử lý dữ liệu cá nhân</Link>.
        </p>
      </PolicySection>
      <PolicySection title="5. Thay đổi điều khoản">
        <p>Khi điều khoản thay đổi, chúng tôi báo trên website và đề nghị bạn xem, đồng ý lại. Việc này không làm gián đoạn việc học hay mua khóa.</p>
      </PolicySection>
    </>
  );
}
