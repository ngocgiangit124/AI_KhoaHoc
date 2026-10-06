/**
 * Design system v2 "Vở ô ly & mực tím" — import từ `@vitaminvui/ui/v2`.
 * Token: `packages/ui/src/v2/tokens.css` (import trong globals.css của app), chỉ có hiệu lực trong `.theme-v2`.
 * Khi PO duyệt v2: thay các component v1 cùng tên ở `src/index.ts` bằng bản ở đây (API tương thích ngược
 * ở Button/Badge/EmptyState/Skeleton/Toast), rồi xoá thư mục v1.
 */
export { cx } from "./cx";
export * from "./icons";
export { UiLink, UiLinkProvider } from "./Link";
export type { UiLinkProps } from "./Link";
export { Logo } from "./Logo";
export { Spinner } from "./Spinner";
export { Button, ButtonLink, IconButton, buttonClasses } from "./Button";
export type { ButtonProps, ButtonLinkProps, ButtonVariant, ButtonSize, IconButtonProps } from "./Button";
export { Field } from "./Field";
export type { FieldProps } from "./Field";
export { TextInput, PasswordInput, Select, Textarea, Checkbox, CONTROL_BASE } from "./Input";
export type { TextInputProps, PasswordInputProps, SelectProps, TextareaProps, CheckboxProps } from "./Input";
export { Badge } from "./Badge";
export type { BadgeProps, BadgeTone } from "./Badge";
export { Alert } from "./Alert";
export type { AlertProps, AlertTone } from "./Alert";
export { ProgressBar } from "./ProgressBar";
export type { ProgressBarProps } from "./ProgressBar";
export { Skeleton, LoadingRegion } from "./Skeleton";
export { Avatar, initials } from "./Avatar";
export { Breadcrumb } from "./Breadcrumb";
export type { BreadcrumbItem } from "./Breadcrumb";
export { Pagination } from "./Pagination";
export { EmptyState } from "./EmptyState";
export type { EmptyStateProps } from "./EmptyState";
export { Tabs, LinkTabs } from "./Tabs";
export type { TabItem, LinkTabItem } from "./Tabs";
export { Dialog, ConfirmDialog } from "./Dialog";
export type { DialogProps, ConfirmDialogProps } from "./Dialog";
export { ToastProvider, useToast } from "./Toast";
export type { ToastInput, ToastTone } from "./Toast";
export { Countdown } from "./Countdown";
export { MathText, splitMath } from "./MathText";
export { CourseCover, coverMotif } from "./CourseCover";
export { CourseCard } from "./CourseCard";
export type { CourseCardData, CourseCardProps } from "./CourseCard";
export { DataTable } from "./DataTable";
export type { Column, DataTableProps } from "./DataTable";
export { ThemeSwitch } from "./ThemeSwitch";
export type { ThemeMode } from "./ThemeSwitch";
export { formatPrice, formatCount, formatClock, formatDurationLong, formatScore, formatDate, formatDateTime } from "./format";
export { SiteHeader } from "./layout/SiteHeader";
export type { SiteHeaderProps, NavItem } from "./layout/SiteHeader";
export { SiteFooter } from "./layout/SiteFooter";
export type { FooterGroup } from "./layout/SiteFooter";
export { BottomNav } from "./layout/BottomNav";
export { AdminFrame } from "./layout/AdminFrame";
export type { AdminFrameProps, AdminNavGroup, AdminNavItem } from "./layout/AdminFrame";
export { NavDrawer } from "./layout/NavDrawer";
export { TeacherCard, formatGrades } from "./TeacherCard";
export type { HomeTeacher, TeacherCardProps } from "./TeacherCard";
export { Switch } from "./Switch";
export type { SwitchProps } from "./Switch";
