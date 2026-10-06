"use client";

import { useState } from "react";
import { Button, ConfirmDialog, Countdown, Dialog, Field, Tabs, TextInput, useToast } from "@vitaminvui/ui/v2";

/** Phần tương tác của trang thư viện thành phần: hộp thoại, xác nhận, toast, tab, đồng hồ. */
export function GalleryDemos() {
  const toast = useToast();
  const [dialog, setDialog] = useState(false);
  const [sheet, setSheet] = useState(false);
  const [confirm, setConfirm] = useState(false);
  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-wrap gap-3">
        <Button onClick={() => setDialog(true)}>Mở hộp thoại</Button>
        <Button variant="secondary" onClick={() => setSheet(true)}>
          Mở bottom-sheet (mobile)
        </Button>
        <Button variant="danger" onClick={() => setConfirm(true)}>
          Xác nhận xoá
        </Button>
      </div>
      <div className="flex flex-wrap gap-3">
        <Button variant="soft" onClick={() => toast.show({ tone: "success", title: "Đã lưu thay đổi" })}>
          Toast thành công
        </Button>
        <Button variant="soft" onClick={() => toast.show({ tone: "info", title: "Đã gửi lại mã", description: "Kiểm tra hộp thư của bạn." })}>
          Toast thông tin
        </Button>
        <Button variant="soft" onClick={() => toast.show({ tone: "warning", title: "Sắp hết giờ làm bài", description: "Còn 1 phút." })}>
          Toast cảnh báo
        </Button>
        <Button variant="soft" onClick={() => toast.show({ tone: "danger", title: "Không lưu được đáp án", description: "Đang thử lại…" })}>
          Toast lỗi
        </Button>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Countdown remainingSeconds={900} />
        <Countdown remainingSeconds={240} />
        <Countdown remainingSeconds={45} />
      </div>
      <Tabs
        label="Ví dụ tab"
        items={[
          { id: "a", label: "Nội dung khóa học", count: 16, content: <p className="text-base text-ink">Tab dùng mũi tên trái/phải để chuyển, Home/End để về đầu/cuối.</p> },
          { id: "b", label: "Bài tập", count: 3, content: <p className="text-base text-ink">Chỉ tab đang chọn nằm trong thứ tự Tab của bàn phím.</p> },
          { id: "c", label: "Ghi chú", content: <p className="text-base text-ink">Tab theo URL dùng LinkTabs (xem trang kết quả quiz).</p> },
        ]}
      />

      <Dialog
        open={dialog}
        onClose={() => setDialog(false)}
        title="Đổi tên chương"
        description="Tên chương hiển thị cho học sinh trong mục lục."
        footer={
          <>
            <Button variant="secondary" onClick={() => setDialog(false)}>
              Huỷ
            </Button>
            <Button onClick={() => setDialog(false)}>Lưu</Button>
          </>
        }
      >
        <Field label="Tên chương" required>
          <TextInput defaultValue="Chương 2. Góc với đường tròn" />
        </Field>
      </Dialog>
      <Dialog open={sheet} onClose={() => setSheet(false)} title="Bộ lọc" sheetOnMobile>
        <p className="text-base text-ink">Trên mobile, hộp thoại này trượt lên từ đáy (bottom-sheet). Trên desktop hiện ở giữa.</p>
      </Dialog>
      <ConfirmDialog
        open={confirm}
        onClose={() => setConfirm(false)}
        onConfirm={() => {
          setConfirm(false);
          toast.show({ tone: "success", title: "Đã xoá khóa học" });
        }}
        tone="danger"
        title="Xoá khóa học “Đại số lớp 7”?"
        description="Hành động này không thể hoàn tác."
        confirmLabel="Xoá khóa học"
      />
    </div>
  );
}
