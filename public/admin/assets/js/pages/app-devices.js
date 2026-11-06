import { localize } from "../i18n.js";
// "我的 APP 设备": generate a one-time TokenHub APP sign-in link (with a QR
// code drawn locally) and manage the devices linked to the current account.
import { adminErrorMessage, api } from "../api.js";
import { copyButton, emptyState, errorState, loadingState, toast } from "../components/feedback.js";
import { confirmDialog, openModal } from "../components/modal.js";
import { button, clear, el } from "../dom.js";
import { formatDateTime } from "../lib/format.js";
import { qrSvgElement } from "../lib/qrcode.js";

const PLATFORM_LABELS = { ios: "iPhone / iPad", android: "Android", macos: "Mac", windows: "Windows", linux: "Linux", web: localize("浏览器") };

// adminUser: open the dialog for another administrator (super admins, APP-008).
export function openAppDevicesDialog({ adminUser = null } = {}) {
  const base = adminUser ? `/admin-api/admin-users/${encodeURIComponent(adminUser.id)}/app-devices` : "/admin-api/my/app-devices";
  const who = adminUser ? String(adminUser.display_name || adminUser.username || adminUser.id) : "";
  const state = { countdown: 0, watch: 0 };
  const root = el("div", { className: "app-devices" });
  const linkHost = el("section", { className: "app-devices-link", "aria-live": "polite" });
  const listHost = el("section", { className: "app-devices-list" });
  root.append(
    el("p", { className: "app-devices-intro", text: adminUser
      ? localize`为管理员「${who}」生成认证链接。请把链接或二维码当面交给本人，打开后会以 ${who} 的身份登录 TokenHub APP。此操作会写入审计日志。`
      : localize("在手机或电脑上打开认证链接（或扫描二维码）即可登录 TokenHub APP，不需要输入密码。APP 闪退或被系统关闭后再次打开，会自动保持登录。") }),
    linkHost,
    listHost
  );
  const modal = openModal({
    title: adminUser ? localize`APP 设备 · ${who}` : localize("APP 设备"),
    body: root,
    size: "md",
    guardDirty: false,
    onClose: () => {
      window.clearInterval(state.countdown);
      window.clearInterval(state.watch);
    }
  });

  const renderLinkIdle = (info) => {
    window.clearInterval(state.countdown);
    clear(linkHost);
    const note = el("input", { type: "text", maxLength: 100, placeholder: localize("设备备注（可选），例如：我的 iPhone"), "aria-label": localize("设备备注"), className: "app-devices-note" });
    const full = info && info.devices.length >= info.max_devices;
    linkHost.append(
      el("div", { className: "app-devices-link-form" }, [
        note,
        button(localize("生成认证链接"), async () => {
          try {
            const result = await api(`${base}/links`, { method: "POST", body: JSON.stringify({ note: note.value }), silent: true });
            renderLink(result.data);
          } catch (error) {
            toast(adminErrorMessage(error, localize("生成认证链接失败")), { tone: "error" });
          }
        }, "", { disabled: full })
      ]),
      full ? el("p", { className: "app-devices-hint warning", text: localize`已绑定 ${info.max_devices} 台设备，达到上限。请先吊销不再使用的设备。` }) : null
    );
  };

  const renderLink = (link) => {
    clear(linkHost);
    const url = `${window.location.origin}${link.path}`;
    const remaining = el("span", { className: "app-devices-countdown" });
    const deadline = Date.now() + Number(link.ttl_seconds || 600) * 1000;
    const tick = () => {
      const left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
      remaining.textContent = left > 0 ? localize`${Math.floor(left / 60)} 分 ${String(left % 60).padStart(2, "0")} 秒后失效` : localize("链接已失效，请重新生成");
      if (left <= 0) {
        window.clearInterval(state.countdown);
        qr.classList.add("is-expired");
      }
    };
    const qr = el("div", { className: "app-devices-qr" });
    try {
      qr.append(qrSvgElement(url, { level: "M", label: localize("认证链接二维码") }));
    } catch {
      qr.append(el("span", { className: "app-devices-hint", text: localize("无法生成二维码，请复制链接") }));
    }
    linkHost.append(
      el("div", { className: "app-devices-link-card" }, [
        qr,
        el("div", { className: "app-devices-link-body" }, [
          el("strong", { text: localize("用手机扫码，或在 APP 中粘贴下面的链接") }),
          el("code", { className: "app-devices-url", text: url }),
          el("div", { className: "app-devices-link-actions" }, [
            copyButton(url, localize("复制链接")),
            button(localize("重新生成"), () => renderLinkIdle(null), "secondary")
          ]),
          remaining,
          el("p", { className: "app-devices-hint", text: localize("链接只能使用一次。请勿转发给他人：任何打开链接的人都会以你的身份登录。") }),
          el("p", { className: "app-devices-hint" }, [
            localize("在电脑上使用：打开 "),
            el("a", { href: "/app/", target: "_blank", rel: "noopener", text: `${window.location.origin}/app/` }),
            localize(" 后粘贴链接。"),
            /^\d+\.\d+\.\d+\.\d+$/.test(window.location.hostname) && window.location.protocol === "https:"
              ? el("span", {}, [localize(" 手机提示证书不受信任时，先查看"), el("a", { href: "/app/#/ca", target: "_blank", rel: "noopener", text: localize("证书安装指引") }), "。"])
              : null
          ])
        ])
      ])
    );
    tick();
    window.clearInterval(state.countdown);
    state.countdown = window.setInterval(tick, 1000);
    void load({ quiet: true });
  };

  const renderDevices = (info) => {
    clear(listHost);
    const header = el("div", { className: "app-devices-list-header" }, [
      el("h3", { text: localize`已登录的设备（${info.devices.length} / ${info.max_devices}）` }),
      info.devices.length > 0
        ? button(localize("全部吊销"), async () => {
          if (!await confirmDialog({ title: localize("吊销全部 APP 设备"), message: localize("所有已登录的 APP 设备都会立即退出，需要重新打开认证链接才能登录。"), confirmLabel: localize("全部吊销") })) {
            return;
          }
          await api(`${base}/revoke-all`, { method: "POST", body: "{}" });
          toast(localize("已吊销全部 APP 设备"), { tone: "success" });
          await load();
        }, "secondary danger")
        : null
    ]);
    listHost.append(header);
    if (info.approval_required) {
      listHost.append(el("p", { className: "app-devices-hint", text: localize("已开启新设备确认：用认证链接登录的新设备会出现在下面，核对设备上显示的匹配码后点「确认」，10 分钟内未确认自动作废。") }));
    }
    if (!info.secure_transport) {
      listHost.append(el("p", { className: "app-devices-hint warning", text: localize("当前通过 HTTP 访问：通过 HTTP 绑定的设备 7 天未使用会自动退出，且无法安装 APP 或接收推送。建议管理员改用 HTTPS。") }));
    }
    if (info.devices.length === 0) {
      listHost.append(emptyState({ title: localize("还没有登录的 APP 设备"), description: localize("生成认证链接后，在手机或电脑上打开即可登录。") }));
      return;
    }
    listHost.append(el("ul", { className: "app-devices-items" }, info.devices.map((device) => device.pending ? pendingItem(device) : el("li", { className: "app-devices-item" }, [
      el("div", { className: "app-devices-item-main" }, [
        el("strong", { text: device.name }),
        el("span", { className: "app-devices-item-meta", text: [
          PLATFORM_LABELS[device.platform] || device.platform,
          device.client_type === "native" ? localize("安装包") : "PWA",
          device.bound_over_http ? localize("HTTP 绑定") : null,
          device.current ? localize("当前设备") : null
        ].filter(Boolean).join(" · ") }),
        el("span", { className: "app-devices-item-meta", text: localize`最近使用 ${formatDateTime(device.last_seen_at) || "-"}${device.last_ip ? ` · ${device.last_ip}` : ""} · 绑定于 ${formatDateTime(device.created_at) || "-"}` }),
        (device.risk || []).length
          ? el("span", { className: "app-devices-item-meta app-devices-risk", text: localize`注意：${(device.risk || []).map((signal) => ({ network_changed: localize("使用的网络与绑定时不同"), client_changed: localize("浏览器或系统与绑定时不同") })[signal] || signal).join("；")}` })
          : null
      ]),
      button(localize("吊销"), async () => {
        if (!await confirmDialog({ title: localize("吊销 APP 设备"), message: localize("该设备会立即退出，需要重新打开认证链接才能登录。"), details: [[localize("设备"), device.name]], confirmLabel: localize("吊销") })) {
          return;
        }
        await api(`${base}/${encodeURIComponent(device.id)}`, { method: "DELETE" });
        toast(localize("设备已吊销"), { tone: "success" });
        await load();
      }, "secondary danger")
    ]))));
  };

  // New-device approval: compare the match code shown on the device, then confirm or reject.
  const pendingItem = (device) => el("li", { className: "app-devices-item is-pending" }, [
    el("div", { className: "app-devices-item-main" }, [
      el("strong", { text: localize`${device.name} · 等待确认` }),
      el("span", { className: "app-devices-item-meta", text: [
        PLATFORM_LABELS[device.platform] || device.platform,
        device.client_type === "native" ? localize("安装包") : "PWA",
        device.last_ip || null,
        localize`请求于 ${formatDateTime(device.created_at) || "-"}`
      ].filter(Boolean).join(" · ") }),
      el("span", { className: "app-devices-item-meta" }, [localize("匹配码 "), el("strong", { className: "app-devices-match-code", text: device.approval_code || "----" }), localize("（与设备屏幕上显示的一致才确认）")])
    ]),
    el("div", { className: "app-devices-item-actions" }, [
      button(localize("确认"), async () => {
        if (!await confirmDialog({ title: localize("确认新设备"), message: localize("确认后这台设备会以该账号登录 TokenHub APP。请确认匹配码与设备上显示的一致。"), details: [[localize("设备"), device.name], [localize("匹配码"), device.approval_code || "-"]], confirmLabel: localize("确认登录"), tone: "primary" })) {
          return;
        }
        try {
          await api(`${base}/${encodeURIComponent(device.id)}/approve`, { method: "POST", body: "{}", silent: true });
          toast(localize("设备已确认"), { tone: "success" });
        } catch (error) {
          toast(adminErrorMessage(error, localize("确认失败")), { tone: "error" });
        }
        await load();
      }),
      button(localize("拒绝"), async () => {
        try {
          await api(`${base}/${encodeURIComponent(device.id)}/reject`, { method: "POST", body: "{}", silent: true });
          toast(localize("已拒绝该设备"), { tone: "success" });
        } catch (error) {
          toast(adminErrorMessage(error, localize("操作失败")), { tone: "error" });
        }
        await load();
      }, "secondary danger")
    ])
  ]);

  // While approval is on and a link is out (or a device waits), refresh the
  // list quietly so the new device shows up without reopening the dialog.
  const watch = (info) => {
    const needed = Boolean(info?.approval_required) && (Boolean(linkHost.querySelector(".app-devices-link-card")) || (info.devices || []).some((device) => device.pending));
    if (needed && !state.watch) {
      state.watch = window.setInterval(() => void load({ quiet: true }), 4000);
    } else if (!needed && state.watch) {
      window.clearInterval(state.watch);
      state.watch = 0;
    }
  };

  const load = async ({ quiet = false } = {}) => {
    if (!quiet) {
      clear(listHost).append(loadingState(localize("正在加载设备…"), { rows: 2 }));
    }
    try {
      const result = await api(base, { silent: true });
      renderDevices(result.data);
      watch(result.data);
      if (!linkHost.querySelector(".app-devices-link-card")) {
        renderLinkIdle(result.data);
      }
    } catch (error) {
      if (!quiet) {
        clear(listHost).append(errorState(error, { onRetry: () => load() }));
      }
    }
  };

  renderLinkIdle(null);
  void load();
  return modal;
}
