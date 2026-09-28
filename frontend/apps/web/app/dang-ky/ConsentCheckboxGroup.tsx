"use client";

/**
 * `<ConsentCheckboxGroup>` (design-system.md §5.2, US-017 BR1/AC1): 2 checkbox đồng ý tách
 * riêng, KHÔNG tick sẵn (S7). Props được điều khiển (controlled) để khớp với
 * `react-hook-form` (`watch`/`setValue`) thay vì tự quản lý state nội bộ.
 */
export interface ConsentCheckboxGroupProps {
  termsChecked: boolean;
  privacyChecked: boolean;
  onTermsChange: (checked: boolean) => void;
  onPrivacyChange: (checked: boolean) => void;
  /** Lỗi gộp — chỉ hiển thị 1 dòng khi thiếu 1 trong 2 (US-017 AC1), không lặp lại 2 lần. */
  error?: string;
  /** `config/public.policy_version` — hiển thị để khớp bản ghi `consents` server sẽ tạo. */
  policyVersion: string;
}

export function ConsentCheckboxGroup({
  termsChecked,
  privacyChecked,
  onTermsChange,
  onPrivacyChange,
  error,
  policyVersion,
}: ConsentCheckboxGroupProps) {
  return (
    <div className="space-y-2 border-t border-gray-100 pt-2">
      <label className="flex items-start gap-2 text-sm">
        <input
          type="checkbox"
          checked={termsChecked}
          onChange={(e) => onTermsChange(e.target.checked)}
          className="mt-0.5 h-4 w-4 rounded text-indigo-600 focus:ring-indigo-600"
        />
        <span>
          Tôi đã đọc và đồng ý với{" "}
          {/* TODO(FW-sau): trỏ tới trang /dieu-khoan-su-dung thật khi có (ngoài phạm vi FW1 phần 1). */}
          <a href="#" target="_blank" rel="noopener noreferrer" className="text-indigo-600 underline">
            Điều khoản sử dụng
          </a>{" "}
          <span className="text-rose-600">*</span>
        </span>
      </label>
      <label className="flex items-start gap-2 text-sm">
        <input
          type="checkbox"
          checked={privacyChecked}
          onChange={(e) => onPrivacyChange(e.target.checked)}
          className="mt-0.5 h-4 w-4 rounded text-indigo-600 focus:ring-indigo-600"
        />
        <span>
          Tôi đã đọc và đồng ý với{" "}
          <a href="#" target="_blank" rel="noopener noreferrer" className="text-indigo-600 underline">
            Chính sách xử lý dữ liệu cá nhân
          </a>{" "}
          <span className="text-rose-600">*</span>
        </span>
      </label>
      {error ? (
        <p role="alert" className="flex items-center gap-1 text-sm text-rose-600">
          <span aria-hidden="true">⚠</span> {error}
        </p>
      ) : null}
      <p className="text-[11px] text-gray-400">Phiên bản chính sách: {policyVersion}</p>
    </div>
  );
}
