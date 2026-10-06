import { Badge, ButtonLink, IconFileText, IconLogOut } from "@vitaminvui/ui/v2";
import { ChangeContactForm, ChangePasswordForm } from "@/components/v2/my/AccountForms";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { one, routes, sampleStudent } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

/** Tài khoản học sinh: thông tin, đổi liên hệ (cần mật khẩu hiện tại), đổi mật khẩu, quyền dữ liệu. */
export default async function AccountPreview({ searchParams }: PageProps<"/v2/tai-khoan">) {
  const sp = await searchParams;
  const state = one(sp["trang-thai"]) as "sai-mat-khau" | "qua-nhieu" | undefined;
  const section = "rounded-sheet border border-line bg-surface p-5 sm:p-6";
  return (
    <StudentShell
      current="account"
      loggedIn
      preview={
        <PreviewBar
          variants={[
            { label: "Mặc định", href: routes.account, current: !state },
            { label: "Sai mật khẩu hiện tại", href: `${routes.account}?trang-thai=sai-mat-khau`, current: state === "sai-mat-khau" },
            { label: "Sai nhiều lần (429)", href: `${routes.account}?trang-thai=qua-nhieu`, current: state === "qua-nhieu" },
          ]}
        />
      }
    >
      <div className="mx-auto flex max-w-2xl flex-col gap-6 px-4 pb-14 pt-6 sm:px-6">
        <h1 className="text-title font-extrabold tracking-heading text-ink">Tài khoản</h1>

        <section aria-labelledby="ho-so" className={section}>
          <h2 id="ho-so" className="text-heading font-extrabold tracking-heading text-ink">
            {sampleStudent.name}
          </h2>
          <dl className="mt-4 grid gap-3 text-base sm:grid-cols-[160px_1fr]">
            <dt className="text-ink-soft">Lớp</dt>
            <dd className="text-ink">Lớp {sampleStudent.grade_level}</dd>
            <dt className="text-ink-soft">Email</dt>
            <dd className="flex flex-wrap items-center gap-2 text-ink">
              {sampleStudent.email}
              <Badge tone="success" size="sm">
                Đã xác thực
              </Badge>
            </dd>
            <dt className="text-ink-soft">Số điện thoại</dt>
            <dd className="text-ink">{sampleStudent.phone}</dd>
          </dl>
        </section>

        <section aria-labelledby="doi-lien-he" className={section}>
          <h2 id="doi-lien-he" className="text-heading font-extrabold tracking-heading text-ink">
            Đổi email hoặc số điện thoại
          </h2>
          <div className="mt-4">
            <ChangeContactForm key={state ?? "x"} email={sampleStudent.email} phone={sampleStudent.phone} demoError={state} />
          </div>
        </section>

        <section aria-labelledby="doi-mat-khau" className={section}>
          <h2 id="doi-mat-khau" className="text-heading font-extrabold tracking-heading text-ink">
            Đổi mật khẩu
          </h2>
          <div className="mt-4">
            <ChangePasswordForm />
          </div>
        </section>

        <section aria-labelledby="du-lieu" className={section}>
          <h2 id="du-lieu" className="text-heading font-extrabold tracking-heading text-ink">
            Dữ liệu cá nhân
          </h2>
          <p className="mt-1 text-sm text-ink-soft">Màn quyền dữ liệu cá nhân (US-017/018) làm ở đợt FW7; chưa có trong bản xem trước.</p>
          <ul className="mt-3 flex flex-col">
            <li className="flex min-h-12 items-center gap-3 rounded-control px-2 text-ink-soft">
              <IconFileText />
              <span className="flex-1 text-base">Tải dữ liệu, xoá tài khoản, trạng thái đồng ý</span>
              <Badge size="sm">Sắp có</Badge>
            </li>
          </ul>
        </section>

        <div>
          <ButtonLink href={routes.home} variant="secondary" leadingIcon={<IconLogOut size={18} />}>
            Đăng xuất
          </ButtonLink>
        </div>
      </div>
    </StudentShell>
  );
}
