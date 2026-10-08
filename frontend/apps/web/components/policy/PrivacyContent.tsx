import Link from "next/link";
import { routes } from "@/lib/routes";
import { PolicySection } from "./PolicyPage";

export function PrivacyContent() {
  return (
    <>
      <PolicySection title="1. Chúng tôi thu thập gì">
        <ul className="list-disc pl-5">
          <li>Thông tin tài khoản: họ tên, ngày sinh, lớp, email, số điện thoại, mật khẩu (lưu dạng mã hoá một chiều).</li>
          <li>Liên hệ phụ huynh (không bắt buộc): email và/hoặc số điện thoại phụ huynh.</li>
          <li>Hoạt động học: khóa đã đăng ký, tiến độ xem bài, kết quả quiz, đơn hàng và giỏ hàng.</li>
          <li>Bằng chứng đồng ý: loại, phiên bản văn bản, thời điểm, và địa chỉ IP/thiết bị lúc bạn đồng ý (chỉ dùng để chứng minh đồng ý, không hiển thị lại cho bạn).</li>
        </ul>
      </PolicySection>
      <PolicySection title="2. Mục đích sử dụng">
        <p>Cung cấp khóa học, xác thực tài khoản, xử lý thanh toán, hỗ trợ học sinh, bảo mật hệ thống và thông báo cho phụ huynh như mô tả dưới đây.</p>
      </PolicySection>
      <PolicySection title="3. Thông báo cho phụ huynh">
        <ul className="list-disc pl-5">
          <li>Nếu bạn khai email phụ huynh, VitaminVui gửi thư thông báo: khi tài khoản được xác thực lần đầu, khi bạn thêm hoặc đổi email phụ huynh, và khi bạn thanh toán khóa học có phí.</li>
          <li>Thư không chứa email, số điện thoại hay ngày sinh của bạn. Phụ huynh không cần làm gì thêm.</li>
          <li>Mỗi thư có liên kết để phụ huynh huỷ nhận bất cứ lúc nào. Bạn cũng có thể sửa hoặc xoá thông tin phụ huynh trong mục Quyền dữ liệu cá nhân.</li>
        </ul>
      </PolicySection>
      <PolicySection title="4. Quyền của bạn">
        <ul className="list-disc pl-5">
          <li>Xem các đồng ý đã cho, và đồng ý lại khi văn bản được cập nhật.</li>
          <li>Tải toàn bộ dữ liệu cá nhân của bạn (tệp JSON, tối đa 2 lần mỗi ngày, cần mật khẩu).</li>
          <li>Xoá tài khoản: thông tin cá nhân được ẩn danh; khóa học và đơn hàng được giữ lại nhưng không còn gắn với bạn, và bạn không đăng nhập lại được.</li>
        </ul>
        <p>
          Thực hiện tại{" "}
          <Link href={routes.privacyData} className="focus-ring rounded font-semibold text-primary underline">Quyền dữ liệu cá nhân</Link>.
        </p>
      </PolicySection>
      <PolicySection title="5. Thời gian lưu giữ">
        <p>Nhật ký hoạt động hệ thống (gồm địa chỉ IP và thiết bị) được giữ 24 tháng rồi tự động xoá, kể cả với tài khoản đã xoá.</p>
      </PolicySection>
      <PolicySection title="6. Liên hệ">
        <p>Mọi thắc mắc về dữ liệu cá nhân, vui lòng liên hệ bộ phận hỗ trợ VitaminVui.</p>
      </PolicySection>
    </>
  );
}
