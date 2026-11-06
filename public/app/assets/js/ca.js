import { localize } from "/admin/assets/js/i18n.js";
// 证书安装指引: for https://IP deployments the phone must trust TokenHub's
// internal root certificate before the APP can be installed or receive push.
import { el } from "/admin/assets/js/dom.js";
import { qrSvgElement } from "/admin/assets/js/lib/qrcode.js";

export async function renderCaGuide(content) {
  let available = false;
  try {
    const response = await fetch("/admin-api/app-auth/ca.crt", { method: "GET", credentials: "omit", cache: "no-store" });
    available = response.ok;
  } catch {
    available = false;
  }
  const appUrl = `${window.location.origin}/app/`;
  const qr = el("div", { className: "tha-ca-qr" });
  try {
    qr.append(qrSvgElement(`${appUrl}#/ca`, { level: "M", label: localize("在手机上打开本页") }));
  } catch {
    // no QR, the URL is shown below
  }
  const steps = (title, items) => el("section", { className: "tha-card" }, [
    el("header", { className: "tha-card-header" }, [el("h2", { text: title })]),
    el("ol", { className: "tha-steps" }, items.map((text) => el("li", { text })))
  ]);
  content.replaceChildren(el("div", { className: "tha-ca" }, [
    el("section", { className: "tha-card" }, [
      el("header", { className: "tha-card-header" }, [el("h2", { text: localize("证书安装指引") })]),
      el("p", { text: available
        ? localize("这台 TokenHub 使用内部证书（https://IP）。手机和电脑需要先信任它的根证书，才能把 APP 安装到主屏幕并接收推送。根证书只包含公开信息，不含任何密钥。")
        : localize("这台 TokenHub 没有使用内部证书：使用域名证书时不需要安装任何证书；使用纯 HTTP 时 APP 以网页方式使用，无法安装或接收推送。") }),
      available ? el("div", { className: "tha-ca-downloads" }, [
        el("a", { className: "button-link", href: "/admin-api/app-auth/ca.mobileconfig", text: localize("下载 iPhone / iPad / Mac 描述文件") }),
        el("a", { className: "button-link secondary", href: "/admin-api/app-auth/ca.crt", text: localize("下载根证书 .crt（Android / Windows）") })
      ]) : null,
      el("div", { className: "tha-ca-open" }, [qr, el("p", { className: "tha-muted", text: localize`在手机上扫码打开本页：${appUrl}#/ca` })])
    ]),
    available ? steps("iPhone / iPad", [
      localize("用 Safari 打开本页，点「下载 iPhone / iPad / Mac 描述文件」，允许下载。"),
      localize("打开「设置」→「已下载描述文件」→「安装」。"),
      localize("打开「设置」→「通用」→「关于本机」→「证书信任设置」，打开 TokenHub 根证书的开关。"),
      localize("回到 Safari 打开 TokenHub，点「分享」→「添加到主屏幕」。")
    ]) : null,
    available ? steps("Android", [
      localize("点「下载根证书 .crt」。"),
      localize("打开「设置」→「安全」→「加密与凭据」→「安装证书」→「CA 证书」，选择刚下载的文件（不同品牌菜单名称略有不同）。"),
      localize("用 Chrome 打开 TokenHub，菜单中选择「安装应用」或「添加到主屏幕」。")
    ]) : null,
    available ? steps(localize("Mac / Windows 电脑"), [
      localize("Mac：下载描述文件或 .crt，双击后在「钥匙串访问」中把它设为「始终信任」。"),
      localize("Windows：双击 .crt →「安装证书」→ 存储位置选「受信任的根证书颁发机构」。"),
      localize("重新打开浏览器，在地址栏右侧点「安装 TokenHub」。")
    ]) : null
  ]));
}
