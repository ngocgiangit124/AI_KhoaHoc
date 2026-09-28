import { render, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { TurnstileWidget } from "./TurnstileWidget";

describe("TurnstileWidget", () => {
  afterEach(() => {
    delete (window as unknown as { turnstile?: unknown }).turnstile;
    vi.restoreAllMocks();
  });

  it("không render gì và không đụng window.turnstile khi siteKey rỗng (local chưa cấu hình Turnstile)", () => {
    const { container } = render(<TurnstileWidget siteKey="" onVerify={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
    expect(window.turnstile).toBeUndefined();
  });

  it("gọi turnstile.render với đúng sitekey; callback trả token qua onVerify", async () => {
    const renderMock = vi.fn((_container: HTMLElement, options: { callback?: (t: string) => void }) => {
      options.callback?.("fake-token");
      return "widget-1";
    });
    window.turnstile = { render: renderMock, remove: vi.fn(), reset: vi.fn() };

    const onVerify = vi.fn();
    render(<TurnstileWidget siteKey="test-site-key" onVerify={onVerify} />);

    await waitFor(() => expect(renderMock).toHaveBeenCalledTimes(1));
    expect(renderMock.mock.calls[0]?.[1]).toMatchObject({ sitekey: "test-site-key" });
    expect(onVerify).toHaveBeenCalledWith("fake-token");
  });

  it("gọi onExpire/onError khi turnstile báo hết hạn/lỗi", async () => {
    let capturedOptions: {
      "expired-callback"?: () => void;
      "error-callback"?: () => void;
    } = {};
    const renderMock = vi.fn((_container: HTMLElement, options: typeof capturedOptions) => {
      capturedOptions = options;
      return "widget-2";
    });
    window.turnstile = { render: renderMock, remove: vi.fn(), reset: vi.fn() };

    const onExpire = vi.fn();
    const onError = vi.fn();
    render(<TurnstileWidget siteKey="test-site-key" onVerify={vi.fn()} onExpire={onExpire} onError={onError} />);

    await waitFor(() => expect(renderMock).toHaveBeenCalledTimes(1));
    capturedOptions["expired-callback"]?.();
    capturedOptions["error-callback"]?.();

    expect(onExpire).toHaveBeenCalledTimes(1);
    expect(onError).toHaveBeenCalledTimes(1);
  });

  it("gỡ widget (turnstile.remove) khi unmount", async () => {
    const removeMock = vi.fn();
    const renderMock = vi.fn().mockReturnValue("widget-3");
    window.turnstile = { render: renderMock, remove: removeMock, reset: vi.fn() };

    const { unmount } = render(<TurnstileWidget siteKey="test-site-key" onVerify={vi.fn()} />);
    await waitFor(() => expect(renderMock).toHaveBeenCalledTimes(1));

    unmount();
    expect(removeMock).toHaveBeenCalledWith("widget-3");
  });
});
