import { localize } from "/admin/assets/js/i18n.js";
// 我的: account, this device, PIN and APP lock, notifications, other devices,
// theme, installation and sign-out.
import { api, adminErrorMessage } from "/admin/assets/js/api.js";
import { confirmDialog } from "/admin/assets/js/components/modal.js";
import { toast } from "/admin/assets/js/components/feedback.js";
import { button, el } from "/admin/assets/js/dom.js";
import { formatDateTime } from "/admin/assets/js/lib/format.js";
import { valueLabel } from "/admin/assets/js/resources.js";
import { currentDevice, currentUser, insecureTransport, logout, platform } from "./auth.js";
import { lockSettings, saveLockSettings } from "./lock.js";
import { changePin, requestPin } from "./stepup.js";
import { passkeySupported, registerPasskey, removePasskeys } from "./passkey.js";
import { currentSubscription, disablePush, enablePush, pushSupport, standalone } from "./push.js";
import { applyTheme, themePreference } from "./theme.js";
import { realtimeConnected } from "./realtime.js";

const RISK_LABELS = { network_changed: localize("网络与绑定时不同"), client_changed: localize("浏览器或系统与绑定时不同") };
const PLATFORM_LABELS = { ios: "iPhone / iPad", android: "Android", macos: "Mac", windows: "Windows", linux: "Linux", web: localize("浏览器") };

function section(title, children) {
  return el("section", { className: "tha-card" }, [el("header", { className: "tha-card-header" }, [el("h2", { text: title })]), ...[].concat(children).filter(Boolean)]);
}

function row(label, value, action = null) {
  return el("div", { className: "tha-row" }, [
    el("div", { className: "tha-row-text" }, [el("span", { className: "tha-row-label", text: label }), value ? el("span", { className: "tha-row-value", text: value }) : null]),
    action
  ]);
}

export async function renderMe(content, { installPrompt, onSignedOut }) {
  const user = currentUser() || {};
  const device = currentDevice() || {};
  let status = {};
  try {
    status = (await api("/admin-api/app-auth/status", { silent: true })).data || {};
  } catch {
    status = {};
  }

  const account = section(localize("账号"), [
    row(String(user.display_name || user.username || ""), valueLabel("role", user.role) || ""),
    row(localize("本设备"), [device.name, PLATFORM_LABELS[device.platform] || device.platform].filter(Boolean).join(" · ")),
    insecureTransport() ? el("p", { className: "tha-warning", text: localize("当前通过 HTTP 访问：本设备 7 天不使用会自动退出，且无法安装 APP 或接收推送。建议管理员改用 HTTPS。") }) : null
  ]);

  // PIN and APP lock
  const pinButton = button(status.pin_set ? localize("修改 PIN") : localize("设置 PIN"), async () => {
    const ok = status.pin_set ? await changePin() : await requestPin({ pinSet: false, title: localize("设置 PIN") });
    if (ok) {
      toast(status.pin_set ? localize("PIN 已修改") : localize("PIN 已设置"), { tone: "success" });
      status.pin_set = true;
      pinButton.textContent = localize("修改 PIN");
      lockToggle.disabled = false;
    }
  }, "secondary");
  const settings = lockSettings();
  const lockToggle = el("input", { type: "checkbox", checked: settings.enabled, disabled: !status.pin_set, "aria-label": localize("打开 APP 时验证 PIN") });
  const lockMinutes = el("select", { "aria-label": localize("离开多久后需要验证") }, [1, 5, 15, 60].map((value) => el("option", { value: String(value), text: localize`${value} 分钟`, selected: settings.minutes === value })));
  const saveLock = () => saveLockSettings({ enabled: lockToggle.checked, minutes: Number(lockMinutes.value) });
  lockToggle.addEventListener("change", saveLock);
  lockMinutes.addEventListener("change", saveLock);
  // Passkeys (Face ID / fingerprint / Windows Hello) as an alternative to the PIN.
  const passkeyState = el("span", { className: "tha-row-value" });
  const passkeyActions = el("div", { className: "tha-row-actions" });
  let passkeyCount = Number(status.passkeys?.count || 0);
  const renderPasskeys = () => {
    const available = Boolean(status.passkeys?.available) && passkeySupported();
    passkeyState.textContent = !available
      ? localize("需要通过 HTTPS 和域名访问才能使用 Passkey；当前只能使用 PIN。")
      : passkeyCount > 0 ? localize`已添加 ${passkeyCount} 个。敏感操作时可以用面容、指纹或系统密码代替 PIN。` : localize("未添加。添加后，敏感操作时可以用面容、指纹或系统密码代替 PIN。");
    passkeyActions.replaceChildren(...[
      available ? button(localize("添加 Passkey"), async () => {
        try {
          const result = await registerPasskey();
          passkeyCount = Number(result?.passkeys || passkeyCount + 1);
          toast(localize("Passkey 已添加"), { tone: "success" });
          renderPasskeys();
        } catch (error) {
          toast(error?.name === "NotAllowedError" ? localize("已取消") : adminErrorMessage(error, error?.message || localize("添加 Passkey 失败")), { tone: "error" });
        }
      }, "secondary") : null,
      available && passkeyCount > 0 ? button(localize("移除"), async () => {
        if (!await confirmDialog({ title: localize("移除 Passkey"), message: localize("移除后，敏感操作只能用 PIN 验证。"), confirmLabel: localize("移除") })) {
          return;
        }
        await removePasskeys();
        passkeyCount = 0;
        renderPasskeys();
      }, "secondary danger") : null
    ].filter(Boolean));
  };
  renderPasskeys();

  const security = section(localize("安全"), [
    row("PIN", status.pin_set ? localize("已设置。删除或轮换密钥、调整额度、管理员管理、系统设置等敏感操作前需要验证。") : localize("未设置。第一次执行敏感操作时会提示设置。"), pinButton),
    el("div", { className: "tha-row" }, [el("div", { className: "tha-row-text" }, [el("span", { className: "tha-row-label", text: "Passkey" }), passkeyState]), passkeyActions]),
    row(localize("打开 APP 时验证 PIN"), localize("离开 APP 超过设定时间后再打开，需要输入 PIN。"), el("label", { className: "tha-switch" }, [lockToggle, el("span", { "aria-hidden": "true" })])),
    row(localize("离开多久后需要验证"), "", lockMinutes)
  ]);

  // Notifications
  const support = pushSupport();
  const pushState = el("span", { className: "tha-row-value" });
  const pushToggle = el("input", { type: "checkbox", disabled: !support.ok || !status.push?.available, "aria-label": localize("告警推送") });
  const subscription = support.ok ? await currentSubscription().catch(() => null) : null;
  pushToggle.checked = Boolean(subscription) && Boolean(status.push?.subscribed);
  // Native push: the server knows whether this device has a token.
  if (support.native) {
    pushToggle.checked = Boolean(status.push?.subscribed);
  }
  const nativeKind = support.native ? platform() : "";
  const nativeReady = !support.native || Boolean(status.push?.native?.[nativeKind]);
  if (support.native && !nativeReady) {
    pushToggle.disabled = true;
  }
  pushState.textContent = !status.push?.available ? localize("服务器尚未启用推送。")
    : !nativeReady ? localize`服务器尚未配置${nativeKind === "ios" ? " APNs" : " FCM"} 推送凭证，请联系管理员（见 docs/app-admin.md）。APP 打开时仍会实时收到告警。`
      : support.ok ? (pushToggle.checked ? localize("已开启：有新告警时通知这台设备。") : localize("未开启。")) : support.reason;
  pushToggle.addEventListener("change", async () => {
    pushToggle.disabled = true;
    try {
      if (pushToggle.checked) {
        await enablePush();
        pushState.textContent = localize("已开启：有新告警时通知这台设备。");
      } else {
        await disablePush();
        pushState.textContent = localize("未开启。");
      }
    } catch (error) {
      pushToggle.checked = !pushToggle.checked;
      toast(error?.message || localize("推送设置失败"), { tone: "error" });
    } finally {
      pushToggle.disabled = false;
    }
  });
  // Push rules (APP-037): lowest severity and quiet hours (critical alerts always come through).
  const prefs = status.push?.preferences || { min_severity: "warning", quiet_start: null, quiet_end: null };
  const minSeverity = el("select", { "aria-label": localize("推送哪些告警") }, [["info", localize("全部告警")], ["warning", localize("警告及严重")], ["critical", localize("仅严重")]].map(([value, text]) => el("option", { value, text, selected: prefs.min_severity === value })));
  const quietStart = el("input", { type: "time", value: prefs.quiet_start || "", "aria-label": localize("免打扰开始") });
  const quietEnd = el("input", { type: "time", value: prefs.quiet_end || "", "aria-label": localize("免打扰结束") });
  const saveRules = async () => {
    try {
      await api("/admin-api/app-auth/push/preferences", {
        method: "PUT",
        silent: true,
        body: JSON.stringify({
          min_severity: minSeverity.value,
          quiet_start: quietStart.value && quietEnd.value ? quietStart.value : "",
          quiet_end: quietStart.value && quietEnd.value ? quietEnd.value : "",
          time_zone: Intl.DateTimeFormat().resolvedOptions().timeZone || ""
        })
      });
      toast(localize("推送规则已保存"), { tone: "success", timeout: 2000 });
    } catch (error) {
      toast(adminErrorMessage(error, error?.message || localize("保存失败")), { tone: "error" });
    }
  };
  [minSeverity, quietStart, quietEnd].forEach((control) => control.addEventListener("change", () => void saveRules()));
  const rules = el("div", { className: "tha-push-rules", hidden: !pushToggle.checked }, [
    row(localize("推送哪些告警"), "", minSeverity),
    row(localize("免打扰时段"), localize("期间只推送严重告警。"), el("div", { className: "tha-time-range" }, [quietStart, el("span", { text: localize("至") }), quietEnd]))
  ]);
  pushToggle.addEventListener("change", () => {
    window.setTimeout(() => { rules.hidden = !pushToggle.checked; }, 0);
  });
  const liveRow = row(localize("APP 打开时的实时更新"), realtimeConnected() ? localize("已连接：新告警约 2 秒内出现，用量每 10 秒、服务健康每 15 秒刷新。") : localize("未连接：正在使用定时刷新（服务器繁忙或网络中断时会自动切换）。"));
  const notifications = section(localize("通知"), [liveRow, row(localize("告警推送"), "", el("label", { className: "tha-switch" }, [pushToggle, el("span", { "aria-hidden": "true" })])), pushState, rules]);

  // Devices
  const devicesHost = el("div", { className: "tha-devices" });
  const loadDevices = async () => {
    try {
      const { data } = await api("/admin-api/my/app-devices", { silent: true });
      devicesHost.replaceChildren(...(data.devices || []).map((item) => el("div", { className: "tha-row" }, [
        el("div", { className: "tha-row-text" }, [
          el("span", { className: "tha-row-label", text: `${item.name}${item.current ? localize("（本设备）") : ""}` }),
          el("span", { className: "tha-row-value", text: item.pending ? localize`${PLATFORM_LABELS[item.platform] || item.platform} · 等待在电脑后台确认` : localize`${PLATFORM_LABELS[item.platform] || item.platform} · 最近使用 ${formatDateTime(item.last_seen_at) || "-"}${item.bound_over_http ? localize(" · HTTP 绑定") : ""}` }),
          (item.risk || []).length ? el("span", { className: "tha-risk", text: (item.risk || []).map((signal) => RISK_LABELS[signal] || signal).join(" · ") }) : null
        ]),
        item.current ? null : button(localize("吊销"), async () => {
          if (!await confirmDialog({ title: localize("吊销设备"), message: localize("该设备会立即退出登录。"), details: [[localize("设备"), item.name]], confirmLabel: localize("吊销") })) {
            return;
          }
          try {
            await api(`/admin-api/my/app-devices/${encodeURIComponent(item.id)}`, { method: "DELETE", silent: true });
            toast(localize("设备已吊销"), { tone: "success" });
            await loadDevices();
          } catch (error) {
            toast(adminErrorMessage(error, localize("吊销失败")), { tone: "error" });
          }
        }, "secondary danger")
      ])));
    } catch (error) {
      devicesHost.replaceChildren(el("p", { className: "tha-muted", text: adminErrorMessage(error, localize("设备列表加载失败")) }));
    }
  };
  const devices = section(localize("已登录的设备"), [devicesHost, el("p", { className: "tha-muted", text: localize("新增设备：在电脑上打开 TokenHub 后台，点左下角「APP 设备」生成认证链接。") })]);

  // Appearance and installation
  const theme = el("select", { "aria-label": localize("主题") }, [["auto", localize("跟随系统")], ["light", localize("浅色")], ["dark", localize("深色")]].map(([value, text]) => el("option", { value, text, selected: themePreference() === value })));
  theme.addEventListener("change", () => applyTheme(theme.value, true));
  let installRow = null;
  if (!standalone()) {
    if (installPrompt()) {
      installRow = row(localize("安装到主屏幕 / 桌面"), localize("像普通 APP 一样从图标打开。"), button(localize("安装"), async () => {
        const prompt = installPrompt();
        if (prompt) {
          prompt.prompt();
          await prompt.userChoice.catch(() => null);
        }
      }, "secondary"));
    } else if (platform() === "ios") {
      installRow = row(localize("安装到主屏幕"), localize("在 Safari 中点「分享」→「添加到主屏幕」。"));
    } else if (!window.isSecureContext) {
      installRow = row(localize("安装到主屏幕 / 桌面"), localize("需要 HTTPS 连接才能安装。"));
    }
  }
  const appearance = section(localize("外观与安装"), [row(localize("主题"), "", theme), installRow, row(localize("证书安装指引"), localize("使用 https://IP 访问时，先在手机上信任服务器证书。"), el("a", { className: "tha-link", href: "#/ca", text: localize("查看") }))]);

  const signOut = section(localize("退出"), [
    el("p", { className: "tha-muted", text: localize("退出后这台设备需要重新打开认证链接才能登录。只是关闭或切换 APP 不需要退出。") }),
    button(localize("退出登录"), async () => {
      if (!await confirmDialog({ title: localize("退出登录"), message: localize("这台设备会退出登录，需要重新打开认证链接才能再次登录。"), confirmLabel: localize("退出登录") })) {
        return;
      }
      await logout().catch(() => undefined);
      onSignedOut();
    }, "danger")
  ]);

  content.replaceChildren(el("div", { className: "tha-me" }, [account, security, notifications, devices, appearance, signOut, el("p", { className: "tha-version", text: localize`TokenHub APP · 会话有效至 ${formatDateTime(status.session_expires_at) || "-"}` })]));
  void loadDevices();
}
