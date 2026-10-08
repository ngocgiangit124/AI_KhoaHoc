"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Alert, Badge, Button, ButtonLink, IconBookOpen, IconFileText, Sheet, useToast } from "@vitaminvui/ui/v2";
import { LogoutButton } from "@/components/auth/LogoutButton";
import { useAuth } from "@/lib/auth/AuthProvider";
import { routes } from "@/lib/routes";
import { ChangeContactForm } from "./ChangeContactForm";
import { ChangePasswordForm } from "./ChangePasswordForm";

const H2 = "text-heading font-extrabold tracking-heading text-ink";

/**
 * `/tai-khoan` (design `v2/tai-khoan`): hồ sơ · đổi email/SĐT (cần mật khẩu hiện tại) · đổi mật khẩu. Mục chưa có API
 * (dữ liệu cá nhân — FW7, khóa học của tôi đã có ở FW6) ghi "Sắp có". Khách -> đăng nhập rồi quay lại; `/auth/me` lỗi -> Thử lại.
 */
export function AccountView() {
  const router = useRouter();
  const toast = useToast();
  const { state, refresh } = useAuth();
  const [changedEmail, setChangedEmail] = useState(false);

  useEffect(() => {
    if (state.status === "guest") router.replace(`${routes.login}?next=${routes.account}`);
  }, [state, router]);

  const ready = state.status === "user";
  useEffect(() => {
    // Nội dung chỉ có sau khi `/auth/me` xong nên trình duyệt chưa cuộn được tới `#doi-lien-he`: cuộn tại đây.
    if (ready && window.location.hash) document.getElementById(window.location.hash.slice(1))?.scrollIntoView();
  }, [ready]);

  if (state.status === "error") {
    return (
      <Sheet>
        <Alert tone="danger" title="Không tải được thông tin tài khoản" action={<Button variant="secondary" onClick={() => void refresh()}>Thử lại</Button>}>
          Vui lòng kiểm tra kết nối và thử lại.
        </Alert>
      </Sheet>
    );
  }
  if (state.status !== "user") return <p className="text-base text-ink-soft">Đang tải…</p>;

  const { user } = state;
  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-title font-extrabold tracking-heading text-ink">Tài khoản</h1>

      <div>
        <ButtonLink href={routes.myCourses} variant="secondary" leadingIcon={<IconBookOpen size={18} />}>
          Khóa học của tôi
        </ButtonLink>
      </div>

      {changedEmail || !user.is_verified ? (
        <Alert
          tone="info"
          title={changedEmail ? "Đã đổi email" : "Cần xác thực tài khoản"}
          action={
            <ButtonLink href={routes.verifyOtp} size="md">
              Xác thực ngay
            </ButtonLink>
          }
        >
          {changedEmail
            ? "Mã xác thực đã gửi tới email mới. Các thiết bị khác đã được đăng xuất. Bạn cần xác thực lại trước khi đăng ký khóa học."
            : "Xác thực email để đăng ký khóa học."}
        </Alert>
      ) : null}

      <Sheet as="section" aria-labelledby="ho-so">
        <h2 id="ho-so" className={H2}>
          {user.name}
        </h2>
        <dl className="mt-4 grid gap-3 text-base sm:grid-cols-[160px_1fr]">
          {user.grade_level ? (
            <>
              <dt className="text-ink-soft">Lớp</dt>
              <dd className="text-ink">Lớp {user.grade_level}</dd>
            </>
          ) : null}
          <dt className="text-ink-soft">Email</dt>
          <dd className="flex flex-wrap items-center gap-2 break-all text-ink">
            {user.email ?? "—"}
            {user.email ? (
              <Badge tone={user.is_verified ? "success" : "warning"} size="sm">
                {user.is_verified ? "Đã xác thực" : "Chưa xác thực"}
              </Badge>
            ) : null}
          </dd>
          <dt className="text-ink-soft">Số điện thoại</dt>
          <dd className="text-ink">{user.phone ?? "—"}</dd>
        </dl>
      </Sheet>

      <Sheet as="section" aria-labelledby="doi-lien-he-tieu-de" id="doi-lien-he" className="scroll-mt-24">
        <h2 id="doi-lien-he-tieu-de" className={H2}>
          Đổi email hoặc số điện thoại
        </h2>
        <div className="mt-4">
          <ChangeContactForm
            // Đổi liên hệ xong thì dựng lại form với giá trị mới (mật khẩu đã xoá).
            key={`${user.email ?? ""}|${user.phone ?? ""}`}
            email={user.email ?? ""}
            phone={user.phone ?? ""}
            onDone={async ({ resendAvailableAt, emailChanged }) => {
              await refresh(); // cookie phiên được xoay khi đổi email; `/auth/me` cho user mới
              if (emailChanged && resendAvailableAt !== null) {
                setChangedEmail(true);
              } else {
                toast.show({ tone: "success", title: "Đã lưu thay đổi", description: "Đã cập nhật thông tin liên hệ." });
              }
            }}
          />
        </div>
      </Sheet>

      <Sheet as="section" aria-labelledby="doi-mat-khau-tieu-de" id="doi-mat-khau" className="scroll-mt-24">
        <h2 id="doi-mat-khau-tieu-de" className={H2}>
          Đổi mật khẩu
        </h2>
        <div className="mt-4">
          <ChangePasswordForm />
        </div>
      </Sheet>

      <Sheet as="section" aria-labelledby="du-lieu">
        <h2 id="du-lieu" className={H2}>
          Dữ liệu cá nhân
        </h2>
        <ul className="mt-3 flex flex-col">
          <li className="flex min-h-12 items-center gap-3 rounded-control px-2 text-ink-soft">
            <IconFileText />
            <span className="flex-1 text-base">Tải dữ liệu, xoá tài khoản, trạng thái đồng ý</span>
            <Badge size="sm">Sắp có</Badge>
          </li>
        </ul>
      </Sheet>

      <div>
        <LogoutButton />
      </div>
    </div>
  );
}
