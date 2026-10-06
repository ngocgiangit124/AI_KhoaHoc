import type { ReactNode } from "react";
import {
  Alert,
  Avatar,
  Badge,
  Breadcrumb,
  Button,
  ButtonLink,
  Checkbox,
  CourseCard,
  EmptyState,
  Field,
  IconArrowRight,
  IconBookOpen,
  IconButton,
  IconCheck,
  IconPencil,
  IconPlus,
  IconSearch,
  IconTrash,
  MathText,
  Pagination,
  PasswordInput,
  ProgressBar,
  Select,
  Skeleton,
  TextInput,
  Textarea,
} from "@vitaminvui/ui/v2";
import { GalleryDemos } from "@/components/v2/gallery/GalleryDemos";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { catalogCourses } from "@/lib/mock/v2/catalog";
import { routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const COLORS: Array<{ name: string; cls: string; note: string }> = [
  { name: "paper", cls: "bg-paper", note: "Nền trang" },
  { name: "surface", cls: "bg-surface", note: "Thẻ, header" },
  { name: "sunken", cls: "bg-sunken", note: "Nền phụ, skeleton" },
  { name: "ink", cls: "bg-ink", note: "Chữ chính · 16,6:1" },
  { name: "ink-soft", cls: "bg-ink-soft", note: "Chữ phụ · 6,9:1" },
  { name: "line", cls: "bg-line", note: "Viền tách lớp" },
  { name: "line-strong", cls: "bg-line-strong", note: "Viền ô nhập · 3,5:1" },
  { name: "primary", cls: "bg-primary", note: "Mực tím · chữ trắng 8,0:1" },
  { name: "primary-soft", cls: "bg-primary-soft", note: "Chọn/đang học" },
  { name: "accent", cls: "bg-accent", note: "Cam vitamin · chữ ink 7,4:1" },
  { name: "success", cls: "bg-success", note: "Hoàn thành · 5,3:1" },
  { name: "warning", cls: "bg-warning", note: "Chờ/sắp hết · 5,8:1" },
  { name: "danger", cls: "bg-danger", note: "Lỗi/xoá · 5,6:1" },
  { name: "info", cls: "bg-info", note: "Thông tin · 6,1:1" },
  { name: "player", cls: "bg-player", note: "Khung video" },
];

function Section({ id, title, children }: { id: string; title: string; children: ReactNode }) {
  return (
    <section aria-labelledby={id} className="border-t border-line py-10">
      <h2 id={id} className="text-heading font-extrabold tracking-heading text-ink">
        {title}
      </h2>
      <div className="mt-5">{children}</div>
    </section>
  );
}

/** Thư viện thành phần v2 (để PO/dev xem mọi trạng thái ở một chỗ). */
export default function GalleryPreview() {
  return (
    <>
      <PreviewBar note="Thư viện thành phần — đổi Sáng/Tối để kiểm tra dark mode" />
      <main id="noi-dung" className="mx-auto w-full max-w-6xl flex-1 px-4 pb-16 pt-8 sm:px-6">
        <h1 className="text-title-lg font-extrabold tracking-heading text-ink">Thành phần giao diện v2</h1>
        <p className="mt-2 max-w-2xl text-lg text-ink-soft">“Vở ô ly & mực tím” — token, chữ, nút, ô nhập, nhãn, thông báo và trạng thái dùng chung cho web học sinh và trang quản trị.</p>

        <Section id="mau" title="Màu">
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {COLORS.map((c) => (
              <li key={c.name} className="overflow-hidden rounded-card border border-line bg-surface">
                <div className={`h-16 ${c.cls}`} />
                <div className="p-3">
                  <p className="font-mono text-sm font-semibold text-ink">{c.name}</p>
                  <p className="text-sm text-ink-soft">{c.note}</p>
                </div>
              </li>
            ))}
          </ul>
        </Section>

        <Section id="chu" title="Chữ (Be Vietnam Pro)">
          <div className="flex flex-col gap-4">
            <p className="text-display font-extrabold tracking-heading md:text-display-lg">Ôn thi vào 10 — Ỗ Ữ Ặ</p>
            <p className="text-title-lg font-extrabold tracking-heading">Tiêu đề trang · Khóa học Toán lớp 9</p>
            <p className="text-heading-lg font-extrabold tracking-heading">Tiêu đề khu vực · Nội dung khóa học</p>
            <p className="text-lg font-semibold">Tiêu đề thẻ · Hình học 9: Đường tròn từ cơ bản đến nâng cao</p>
            <p className="max-w-prose text-base leading-relaxed">
              Nội dung 16px, giãn dòng 1,6. Người học đọc đoạn văn này trên điện thoại khi đang đi xe buýt về nhà, nên chữ phải đủ to, đủ đậm và mỗi dòng không quá 75 ký tự.
            </p>
            <p className="text-sm font-medium text-ink-soft">Chữ phụ 14px · 24 bài · 6 giờ 30 phút · 1.240 học sinh</p>
            <p className="font-hand text-xl text-primary">Chú thích viết tay (Mali) — chỉ dùng ở hero</p>
          </div>
        </Section>

        <Section id="cong-thuc" title="Công thức Toán">
          <div className="flex max-w-2xl flex-col gap-4 rounded-card border border-line bg-surface p-5">
            <MathText className="text-question" content={"Giải phương trình $x^2 - 5x + 6 = 0$, biết $\\Delta = b^2 - 4ac$ và $x_{1,2} = \\dfrac{-b \\pm \\sqrt{\\Delta}}{2a}$."} />
            <MathText className="text-question" content={"Công thức riêng dòng (cuộn ngang khi hẹp):\n$$P = \\left(\\dfrac{\\sqrt{x}}{\\sqrt{x} - 1} - \\dfrac{1}{x - \\sqrt{x}}\\right) : \\dfrac{\\sqrt{x} + 1}{x}$$"} />
            <MathText className="text-question" content={"Lượng giác: $\\sin^2 \\alpha + \\cos^2 \\alpha = 1$; tập nghiệm $x \\in \\mathbb{R}$, $x \\ne 1$."} />
          </div>
        </Section>

        <Section id="nut" title="Nút">
          <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
              <Button>Vào học</Button>
              <Button variant="secondary">Làm lại</Button>
              <Button variant="soft">Làm bài</Button>
              <Button variant="ghost">Xem tiến độ</Button>
              <Button variant="danger" leadingIcon={<IconTrash size={18} />}>
                Xoá
              </Button>
            </div>
            <div className="flex flex-wrap items-center gap-3">
              <Button size="sm" leadingIcon={<IconPlus size={16} />}>
                Thêm bài (sm)
              </Button>
              <Button size="md">Cỡ md 44px</Button>
              <Button size="lg" trailingIcon={<IconArrowRight size={18} />}>
                Cỡ lg 52px
              </Button>
              <ButtonLink href={routes.catalog} variant="secondary">
                Liên kết dạng nút
              </ButtonLink>
            </div>
            <div className="flex flex-wrap items-center gap-3">
              <Button loading loadingText="Đang nộp bài…">
                Nộp bài
              </Button>
              <Button disabled>Đã khoá</Button>
              <Button variant="secondary" disabled>
                Không khả dụng
              </Button>
              <IconButton label="Sửa" icon={<IconPencil />} />
              <IconButton label="Tìm kiếm" icon={<IconSearch />} variant="secondary" />
            </div>
          </div>
        </Section>

        <Section id="o-nhap" title="Ô nhập">
          <div className="grid max-w-3xl gap-5 md:grid-cols-2">
            <Field label="Họ và tên" required hint="Như trên giấy khai sinh.">
              <TextInput placeholder="Nguyễn Minh Anh" />
            </Field>
            <Field label="Email" required error="Email chưa đúng định dạng, ví dụ: ten@gmail.com.">
              <TextInput defaultValue="minhanh@" />
            </Field>
            <Field label="Mật khẩu" required>
              <PasswordInput defaultValue="vitamin2026" />
            </Field>
            <Field label="Lớp đang học">
              <Select defaultValue="9">
                {[6, 7, 8, 9, 10, 11, 12].map((g) => (
                  <option key={g} value={g}>
                    Lớp {g}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Ô bị khoá">
              <TextInput disabled defaultValue="Không sửa được" />
            </Field>
            <Field label="Tìm kiếm">
              <TextInput type="search" placeholder="Tìm khóa học" leadingIcon={<IconSearch size={18} />} />
            </Field>
            <Field label="Lý do từ chối" hint="Tối đa 1.000 ký tự." className="md:col-span-2">
              <Textarea placeholder="Ví dụ: Khóa dành cho học sinh lớp chọn." />
            </Field>
            <div className="md:col-span-2">
              <Checkbox id="g-cb1" label="Tôi đã đọc và đồng ý với Điều khoản sử dụng" />
              <Checkbox id="g-cb2" label="Cho xem thử (preview)" description="Học sinh chưa mua vẫn xem được bài này." defaultChecked />
            </div>
          </div>
        </Section>

        <Section id="nhan" title="Nhãn, tiến độ, ảnh đại diện">
          <div className="flex flex-col gap-5">
            <div className="flex flex-wrap gap-2">
              <Badge tone="free">Miễn phí</Badge>
              <Badge tone="primary">Lớp 9</Badge>
              <Badge>Hình học</Badge>
              <Badge tone="success" icon={<IconCheck size={14} />}>
                Đã hoàn thành
              </Badge>
              <Badge tone="info">Đang học</Badge>
              <Badge tone="warning">Đang chờ duyệt</Badge>
              <Badge tone="danger">Không được duyệt</Badge>
              <Badge tone="success" dot size="sm">
                Đã xuất bản
              </Badge>
              <Badge dot size="sm">
                Nháp
              </Badge>
            </div>
            <div className="grid max-w-xl gap-4">
              <ProgressBar value={37} label="Tiến độ của bạn" valueText="6/16 bài · 37%" />
              <ProgressBar value={100} label="Đã xong" valueText="12/12 bài · 100%" />
              <ProgressBar value={0} label="Khóa chưa có bài" hasContent={false} />
            </div>
            <div className="flex items-center gap-3">
              <Avatar name="Nguyễn Thu Hà" size="sm" />
              <Avatar name="Trần Minh Đức" />
              <Avatar name="Minh Anh" size="lg" />
            </div>
          </div>
        </Section>

        <Section id="thong-bao" title="Thông báo trong trang">
          <div className="flex max-w-3xl flex-col gap-3">
            <Alert tone="info" title="Thanh toán trực tuyến đang tạm đóng">
              Bạn vẫn xem được bài học thử và đăng ký các khóa miễn phí.
            </Alert>
            <Alert tone="success" title="Xác thực tài khoản thành công" />
            <Alert tone="warning" title="Mất kết nối mạng">
              Các câu trả lời sẽ được lưu khi có mạng trở lại.
            </Alert>
            <Alert tone="danger" title="Không tải được danh sách khóa học" action={<Button variant="secondary" size="sm">Tải lại</Button>}>
              Kiểm tra kết nối mạng rồi thử lại.
            </Alert>
          </div>
        </Section>

        <Section id="tuong-tac" title="Hộp thoại, toast, tab, đồng hồ">
          <GalleryDemos />
        </Section>

        <Section id="trang-thai" title="Rỗng và đang tải">
          <div className="grid gap-6 md:grid-cols-2">
            <EmptyState size="inline" icon={<IconBookOpen size={28} />} title="Bạn chưa có khóa học nào" description="Chọn một khóa để bắt đầu." action={<ButtonLink href={routes.catalog} size="sm">Khám phá khóa học</ButtonLink>} headingLevel="h3" />
            <div className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4" aria-hidden="true">
              <Skeleton className="aspect-video w-full" />
              <Skeleton className="h-5 w-3/4" />
              <Skeleton className="h-4 w-1/2" />
            </div>
          </div>
        </Section>

        <Section id="dieu-huong" title="Thẻ khóa học, đường dẫn, phân trang">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {catalogCourses.slice(3, 6).map((c) => (
              <CourseCard key={c.id} course={c} href={routes.course(c.slug)} />
            ))}
          </div>
          <Breadcrumb className="mt-6" items={[{ label: "Trang chủ", href: routes.home }, { label: "Lớp 9", href: routes.catalogQuery({ grade: 9 }) }, { label: "Hình học 9" }]} />
          <Pagination className="mt-6" currentPage={4} lastPage={12} hrefFor={(p) => `${routes.gallery}?page=${p}`} />
        </Section>
      </main>
    </>
  );
}
