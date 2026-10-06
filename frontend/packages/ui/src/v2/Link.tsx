"use client";

import { createContext, useContext, type AnchorHTMLAttributes, type ComponentType, type ReactNode, type Ref } from "react";

export interface UiLinkProps extends Omit<AnchorHTMLAttributes<HTMLAnchorElement>, "href"> {
  href: string;
  children?: ReactNode;
  ref?: Ref<HTMLAnchorElement>;
}

const LinkContext = createContext<ComponentType<UiLinkProps> | null>(null);

/**
 * Cho component trong packages/ui dùng `next/link` mà package không phụ thuộc `next`.
 * App bọc 1 lần: `<UiLinkProvider component={Link}>` trong một Client Component của app.
 */
export function UiLinkProvider({ component, children }: { component: ComponentType<UiLinkProps>; children: ReactNode }) {
  return <LinkContext.Provider value={component}>{children}</LinkContext.Provider>;
}

/** Liên kết dùng trong packages/ui. Không có provider thì là thẻ `<a>` thường. */
export function UiLink(props: UiLinkProps) {
  const Component = useContext(LinkContext);
  if (Component) return <Component {...props} />;
  const { href, children, ...rest } = props;
  return (
    <a href={href} {...rest}>
      {children}
    </a>
  );
}
