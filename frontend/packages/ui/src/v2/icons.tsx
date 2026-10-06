import type { ReactNode, SVGProps } from "react";

/**
 * Bộ icon SVG nội bộ của design system v2 (không cần cài package).
 * Nét vẽ theo bộ Lucide (giấy phép ISC, https://lucide.dev/license): khung 24, nét 1.75, đầu tròn.
 * Nếu PO duyệt cài `lucide-react`, thay file này bằng re-export là xong (tên icon giữ nguyên).
 *
 * Mặc định `aria-hidden`. Icon mang nghĩa mà không có chữ đi kèm: truyền `title`.
 */
export interface IconProps extends Omit<SVGProps<SVGSVGElement>, "children"> {
  size?: number;
  title?: string;
}

export type IconComponent = (props: IconProps) => ReactNode;

function createIcon(name: string, body: ReactNode): IconComponent {
  function Icon({ size = 20, title, className = "", strokeWidth = 1.75, ...rest }: IconProps) {
    return (
      <svg
        xmlns="http://www.w3.org/2000/svg"
        width={size}
        height={size}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={strokeWidth}
        strokeLinecap="round"
        strokeLinejoin="round"
        className={`shrink-0 ${className}`}
        role={title ? "img" : undefined}
        aria-label={title}
        aria-hidden={title ? undefined : true}
        focusable="false"
        {...rest}
      >
        {body}
      </svg>
    );
  }
  Icon.displayName = `Icon${name}`;
  return Icon;
}

export const IconSearch = createIcon("Search", <><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></>);
export const IconMenu = createIcon("Menu", <><path d="M4 6h16" /><path d="M4 12h16" /><path d="M4 18h16" /></>);
export const IconX = createIcon("X", <><path d="M18 6 6 18" /><path d="m6 6 12 12" /></>);
export const IconChevronDown = createIcon("ChevronDown", <path d="m6 9 6 6 6-6" />);
export const IconChevronUp = createIcon("ChevronUp", <path d="m18 15-6-6-6 6" />);
export const IconChevronLeft = createIcon("ChevronLeft", <path d="m15 18-6-6 6-6" />);
export const IconChevronRight = createIcon("ChevronRight", <path d="m9 18 6-6-6-6" />);
export const IconArrowRight = createIcon("ArrowRight", <><path d="M5 12h14" /><path d="m12 5 7 7-7 7" /></>);
export const IconCheck = createIcon("Check", <path d="M20 6 9 17l-5-5" />);
export const IconCheckCircle = createIcon("CheckCircle", <><circle cx="12" cy="12" r="10" /><path d="m9 12 2 2 4-4" /></>);
export const IconCircle = createIcon("Circle", <circle cx="12" cy="12" r="10" />);
export const IconCircleHalf = createIcon("CircleHalf", <><circle cx="12" cy="12" r="10" /><path d="M12 2a10 10 0 0 1 0 20z" fill="currentColor" /></>);
export const IconPlay = createIcon("Play", <path d="M6 3l14 9-14 9V3z" />);
export const IconPlayCircle = createIcon("PlayCircle", <><circle cx="12" cy="12" r="10" /><path d="m10 8 6 4-6 4V8z" /></>);
export const IconPause = createIcon("Pause", <><rect x="6" y="4" width="4" height="16" rx="1" /><rect x="14" y="4" width="4" height="16" rx="1" /></>);
export const IconLock = createIcon("Lock", <><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></>);
export const IconClock = createIcon("Clock", <><circle cx="12" cy="12" r="10" /><path d="M12 6v6l4 2" /></>);
export const IconHourglass = createIcon(
  "Hourglass",
  <>
    <path d="M5 22h14" />
    <path d="M5 2h14" />
    <path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22" />
    <path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2" />
  </>,
);
export const IconAlertTriangle = createIcon(
  "AlertTriangle",
  <><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3" /><path d="M12 9v4" /><path d="M12 17h.01" /></>,
);
export const IconAlertCircle = createIcon("AlertCircle", <><circle cx="12" cy="12" r="10" /><path d="M12 8v4" /><path d="M12 16h.01" /></>);
export const IconInfo = createIcon("Info", <><circle cx="12" cy="12" r="10" /><path d="M12 16v-4" /><path d="M12 8h.01" /></>);
export const IconUser = createIcon("User", <><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></>);
export const IconUsers = createIcon(
  "Users",
  <><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /></>,
);
export const IconUserCheck = createIcon(
  "UserCheck",
  <><path d="m16 11 2 2 4-4" /><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /></>,
);
export const IconBookOpen = createIcon(
  "BookOpen",
  <><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></>,
);
export const IconHome = createIcon(
  "Home",
  <>
    <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
    <path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
  </>,
);
export const IconListChecks = createIcon(
  "ListChecks",
  <><path d="m3 17 2 2 4-4" /><path d="m3 7 2 2 4-4" /><path d="M13 6h8" /><path d="M13 12h8" /><path d="M13 18h8" /></>,
);
export const IconSliders = createIcon(
  "Sliders",
  <>
    <path d="M21 4h-7" /><path d="M10 4H3" /><path d="M21 12h-9" /><path d="M8 12H3" /><path d="M21 20h-5" /><path d="M12 20H3" />
    <path d="M14 2v4" /><path d="M8 10v4" /><path d="M16 18v4" />
  </>,
);
export const IconEye = createIcon(
  "Eye",
  <><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0" /><circle cx="12" cy="12" r="3" /></>,
);
export const IconEyeOff = createIcon(
  "EyeOff",
  <>
    <path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49" />
    <path d="M14.084 14.158a3 3 0 0 1-4.242-4.242" />
    <path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143" />
    <path d="m2 2 20 20" />
  </>,
);
export const IconVolume = createIcon(
  "Volume",
  <>
    <path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z" />
    <path d="M16 9a5 5 0 0 1 0 6" />
    <path d="M19.364 18.364a9 9 0 0 0 0-12.728" />
  </>,
);
export const IconCaptions = createIcon(
  "Captions",
  <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M7 15h4" /><path d="M15 15h2" /><path d="M7 11h2" /><path d="M13 11h4" /></>,
);
export const IconMaximize = createIcon(
  "Maximize",
  <><path d="M8 3H5a2 2 0 0 0-2 2v3" /><path d="M21 8V5a2 2 0 0 0-2-2h-3" /><path d="M3 16v3a2 2 0 0 0 2 2h3" /><path d="M16 21h3a2 2 0 0 0 2-2v-3" /></>,
);
export const IconGauge = createIcon("Gauge", <><path d="m12 14 4-4" /><path d="M3.34 19a10 10 0 1 1 17.32 0" /></>);
export const IconLogOut = createIcon("LogOut", <><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5" /><path d="M21 12H9" /></>);
export const IconPlus = createIcon("Plus", <><path d="M5 12h14" /><path d="M12 5v14" /></>);
export const IconPencil = createIcon(
  "Pencil",
  <>
    <path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z" />
    <path d="m15 5 4 4" />
  </>,
);
export const IconTrash = createIcon(
  "Trash",
  <><path d="M3 6h18" /><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" /><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" /></>,
);
export const IconGrip = createIcon(
  "Grip",
  <>
    <circle cx="9" cy="5" r="1" /><circle cx="9" cy="12" r="1" /><circle cx="9" cy="19" r="1" />
    <circle cx="15" cy="5" r="1" /><circle cx="15" cy="12" r="1" /><circle cx="15" cy="19" r="1" />
  </>,
);
export const IconUpload = createIcon(
  "Upload",
  <><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="m17 8-5-5-5 5" /><path d="M12 3v12" /></>,
);
export const IconVideo = createIcon(
  "Video",
  <><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5" /><rect x="2" y="6" width="14" height="12" rx="2" /></>,
);
export const IconLayers = createIcon("Layers", <><path d="m12 2 10 5-10 5L2 7z" /><path d="m2 17 10 5 10-5" /><path d="m2 12 10 5 10-5" /></>);
export const IconShapes = createIcon(
  "Shapes",
  <>
    <path d="M8.3 10a.7.7 0 0 1-.626-1.079L11.4 3a.7.7 0 0 1 1.198-.043L16.3 8.9a.7.7 0 0 1-.572 1.1Z" />
    <rect x="3" y="14" width="7" height="7" rx="1" />
    <circle cx="17.5" cy="17.5" r="3.5" />
  </>,
);
export const IconTicket = createIcon(
  "Ticket",
  <>
    <path d="M2 9a3 3 0 1 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 1 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" />
    <path d="M9 9h.01" /><path d="m15 9-6 6" /><path d="M15 15h.01" />
  </>,
);
export const IconReceipt = createIcon(
  "Receipt",
  <>
    <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z" />
    <path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8" />
    <path d="M12 17.5v-11" />
  </>,
);
export const IconShieldCheck = createIcon(
  "ShieldCheck",
  <>
    <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" />
    <path d="m9 12 2 2 4-4" />
  </>,
);
export const IconFileText = createIcon(
  "FileText",
  <>
    <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
    <path d="M14 2v4a2 2 0 0 0 2 2h4" /><path d="M10 9H8" /><path d="M16 13H8" /><path d="M16 17H8" />
  </>,
);
export const IconInbox = createIcon(
  "Inbox",
  <>
    <path d="M22 12h-6l-2 3h-4l-2-3H2" />
    <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
  </>,
);
export const IconMoon = createIcon("Moon", <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" />);
export const IconSun = createIcon(
  "Sun",
  <>
    <circle cx="12" cy="12" r="4" />
    <path d="M12 2v2" /><path d="M12 20v2" /><path d="m4.93 4.93 1.41 1.41" /><path d="m17.66 17.66 1.41 1.41" />
    <path d="M2 12h2" /><path d="M20 12h2" /><path d="m6.34 17.66-1.41 1.41" /><path d="m19.07 4.93-1.41 1.41" />
  </>,
);
export const IconRotateCcw = createIcon("RotateCcw", <><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" /><path d="M3 3v5h5" /></>);
export const IconCart = createIcon(
  "Cart",
  <>
    <circle cx="8" cy="21" r="1" /><circle cx="19" cy="21" r="1" />
    <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12" />
  </>,
);
export const IconCopy = createIcon(
  "Copy",
  <><rect x="8" y="8" width="14" height="14" rx="2" /><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" /></>,
);
export const IconImage = createIcon(
  "Image",
  <><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" /></>,
);
export const IconExternalLink = createIcon(
  "ExternalLink",
  <><path d="M15 3h6v6" /><path d="M10 14 21 3" /><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /></>,
);
export const IconMore = createIcon("More", <><circle cx="5" cy="12" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="19" cy="12" r="1" /></>);
export const IconFlag = createIcon("Flag", <><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z" /><path d="M4 22v-7" /></>);
export const IconWifiOff = createIcon(
  "WifiOff",
  <>
    <path d="M12 20h.01" /><path d="M8.5 16.429a5 5 0 0 1 7 0" /><path d="M5 12.859a10 10 0 0 1 5.17-2.69" />
    <path d="M19 12.859a10 10 0 0 0-2.007-1.523" /><path d="M2 8.82a15 15 0 0 1 4.177-2.643" />
    <path d="M22 8.82a15 15 0 0 0-11.288-3.764" /><path d="m2 2 20 20" />
  </>,
);
export const IconLayoutGrid = createIcon(
  "LayoutGrid",
  <><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /></>,
);
