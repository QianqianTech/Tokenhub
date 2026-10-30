import { adminErrorMessage, api } from "../api.js";
import { button, clear, el, field, readFields, setText, statusMessage } from "../dom.js";
import { metric, operationSuccessText } from "../lib/output.js";
import { SETTINGS_SECTION_CATEGORIES, SETTINGS_SECTION_PAGES } from "./settings-config.js";
import { columnLabel, formatResourceValue } from "../resources.js";
import { pageHead } from "../lib/resource-table.js";

// What each settings page controls, shown under its title.
const SETTINGS_DESCRIPTIONS = {
  "settings-brand-identity": "产品名称、后台与大屏标题和图标；登录页、侧栏和浏览器标签会同步显示。",
  "settings-tls-certificates": "申请、验证、部署和续期 HTTPS 证书，并查看服务器上的真实证书。",
  "settings-entry-security": "限定管理后台与接口入口允许访问的网段。",
  "settings-retention-export": "调用内容的留存方式和导出文件的保留时间。",
  "settings-alert-thresholds": "厂商错误率、队列积压与证书到期等告警的触发阈值。",
  "settings-rag-upload-review": "知识库上传后是否需要人工审核以及审核规则。",
  "settings-rag-package-upload": "图片包上传的大小、数量与格式限制。",
  "settings-rag-ai-image-processing": "上传图片的自动描述、识别与处理方式。",
  "settings-rag-vectorization-monitoring": "向量化成功率的告警阈值与统计窗口。"
};

export async function renderSettingsCenterPage(content) {
  clear(content);
  const result = await api("/admin-api/system/settings-center");
  const sections = result.data?.sections || {};
  const categoryTabs = [];
  const categoryPanels = [];
  const certificateStatusPanels = [];

  SETTINGS_SECTION_CATEGORIES.forEach((category, categoryIndex) => {
    const categoryActive = categoryIndex === 0;
    const sectionTabs = [];
    const sectionPanels = [];
    const categoryPanel = el("section", {
      className: categoryActive ? "settings-category-panel active" : "settings-category-panel",
      hidden: !categoryActive,
      "data-settings-category-panel": category.key
    });
    const categoryTab = el("button", {
      type: "button",
      className: categoryActive ? "settings-category-tab active" : "settings-category-tab",
      text: category.label,
      "data-settings-category": category.key,
      onClick: () => {
        for (const tab of categoryTabs) {
          tab.classList.toggle("active", tab.dataset.settingsCategory === category.key);
        }
        for (const panel of categoryPanels) {
          const active = panel.dataset.settingsCategoryPanel === category.key;
          panel.hidden = !active;
          panel.classList.toggle("active", active);
        }
      }
    });
    categoryTabs.push(categoryTab);
    categoryPanels.push(categoryPanel);

    category.sections.forEach((resourceKey, sectionIndex) => {
      const page = SETTINGS_SECTION_PAGES[resourceKey];
      if (!page) {
        return;
      }
      const sectionActive = categoryActive && sectionIndex === 0;
      const values = settingsFormValues(sections[page.section] || {});
      const sectionTab = el("button", {
        type: "button",
        className: sectionActive ? "settings-section-tab active" : "settings-section-tab",
        text: page.title,
        "data-settings-section": resourceKey,
        onClick: () => {
          for (const tab of sectionTabs) {
            tab.classList.toggle("active", tab.dataset.settingsSection === resourceKey);
          }
          for (const panel of sectionPanels) {
            const active = panel.dataset.settingsSectionPanel === resourceKey;
            panel.hidden = !active;
            panel.classList.toggle("active", active);
          }
        }
      });
      const rendered = page.section === "tls_certificates"
        ? createCertificateSettingsPage(page, values)
        : { element: settingsFormPanel(page.title, page.section, page.fields, values), statusPanel: null };
      const sectionPanel = el("div", {
        className: sectionActive ? "settings-section-panel active" : "settings-section-panel",
        hidden: !sectionActive,
        "data-settings-section-panel": resourceKey
      }, [rendered.element]);
      if (rendered.statusPanel) {
        certificateStatusPanels.push(rendered.statusPanel);
      }
      sectionTabs.push(sectionTab);
      sectionPanels.push(sectionPanel);
    });

    categoryPanel.append(
      el("div", { className: "settings-section-tabs" }, sectionTabs),
      el("div", { className: "settings-section-panels" }, sectionPanels)
    );
  });

  content.append(el("div", { className: "settings-center-page" }, [
    el("div", { className: "settings-category-tabs" }, categoryTabs),
    el("div", { className: "settings-category-panels" }, categoryPanels)
  ]));

  for (const statusPanel of certificateStatusPanels) {
    await renderCertificateStatus(statusPanel);
  }
}

export async function renderSettingsSectionPage(content, resourceKey) {
  clear(content);
  const page = SETTINGS_SECTION_PAGES[resourceKey];
  if (!page) {
    content.append(statusMessage("设置分区不存在", "error"));
    return;
  }
  const result = await api("/admin-api/system/settings-center");
  const sections = result.data?.sections || {};
  if (page.section === "tls_certificates") {
    await renderCertificateSettingsPage(content, page, settingsFormValues(sections[page.section] || {}));
    return;
  }
  const panel = settingsFormPanel(page.title, page.section, page.fields, settingsFormValues(sections[page.section] || {}), { standalone: true });
  content.append(pageHead(page.title, SETTINGS_DESCRIPTIONS[resourceKey] || ""), panel);
}

function settingsFormValues(values) {
  const out = {};
  for (const [key, value] of Object.entries(values || {})) {
    if (Array.isArray(value)) {
      out[key] = value.every((item) => typeof item === "string") ? value.join("\n") : JSON.stringify(value, null, 2);
    } else if (typeof value === "boolean") {
      out[key] = value ? "true" : "false";
    } else {
      out[key] = value ?? "";
    }
  }
  return out;
}



async function renderCertificateSettingsPage(content, page, values) {
  const rendered = createCertificateSettingsPage(page, values);
  content.append(rendered.element);
  await renderCertificateStatus(rendered.statusPanel);
}

function createCertificateSettingsPage(page, values) {
  const statusPanel = el("section", { className: "certificate-inventory" });
  const element = el("section", { className: "panel certificate-center-page" }, [
    el("header", { className: "certificate-center-heading" }, [
      el("div", {}, [
        el("h2", { text: "证书中心" }),
        el("p", { text: "申请、验证、部署和续签必须以真实证书状态闭环。" })
      ]),
      button("检查证书告警", async () => {
        await api("/admin-api/system/settings-center/certificate-alert-check", { method: "POST" });
      }, "secondary")
    ]),
    el("div", { className: "certificate-summary-grid" }, [
      certificateSummaryCard("当前状态", "正在读取", "真实证书状态"),
      certificateSummaryCard("自动续签", values.auto_renewal === "false" ? "未启用" : "已启用", "公网 IP 每 12 小时检查"),
      certificateSummaryCard("签发类型", certificateRequestTypeLabel(values.certificate_mode), "请选择申请目标")
    ]),
    el("div", { className: "certificate-request-workspace" }, [
      renderCertificateRequestForm(values),
      renderCertificateRequestProgress(values.certificate_request || {})
    ]),
    statusPanel,
    el("section", { className: "certificate-center-alerts" }, [renderCertificateAlertCheck()])
  ]);
  return { element, statusPanel };
}

function renderCertificateRequestForm(values) {
  return renderCertificateCenterRequestForm(values);
}

function renderCertificateCenterRequestForm(values) {
  const fields = [
    { name: "certificate_request_type", label: "申请类型", type: "select", options: [["domain", "域名证书"], ["public_ip", "公网 IP 证书"], ["internal_ip", "内网 IP 证书"]] },
    { name: "request_domain_names", label: "申请域名（每行一个）", type: "textarea", optional: true, rows: 3 },
    { name: "request_ip_addresses", label: "申请 IP（每行一个）", type: "textarea", optional: true, rows: 3 }
  ];
  const initialType = ["domain", "public_ip", "internal_ip"].includes(values.certificate_mode) ? values.certificate_mode : "domain";
  const form = el("form", { className: "edit-form certificate-request-form", onSubmit: (event) => event.preventDefault() });
  const typeField = field(fields[0], initialType);
  const domainField = field(fields[1], values.domain_names || "");
  const ipField = field(fields[2], values.ip_addresses || "");
  typeField.classList.add("certificate-request-type-select");
  const typeHelp = el("p", { className: "notice certificate-type-help" });
  const internalChoices = el("div", { className: "internal-ip-choices", hidden: true });
  const output = el("p", { className: "notice", hidden: true });
  const cards = [
    certificateTypeCard("domain", "域名证书", "公网域名 · Caddy ACME 自动续签"),
    certificateTypeCard("public_ip", "公网 IP 证书", "Let’s Encrypt · 约 6 天 · 每 12 小时检查"),
    certificateTypeCard("internal_ip", "内网 IP 证书", "内部 CA / 自签名 · 信任内部根证书")
  ];
  form.append(el("div", { className: "certificate-type-grid" }, cards), typeField, typeHelp, internalChoices, domainField, ipField);
  const typeControl = form.querySelector('[name="certificate_request_type"]');
  const showOutput = (text) => {
    setText(output, text);
    output.hidden = false;
  };
  const syncType = () => {
    const type = typeControl?.value || "domain";
    domainField.hidden = type !== "domain";
    ipField.hidden = type === "domain";
    setText(typeHelp, certificateRequestTypeHelp(type));
    for (const card of cards) {
      card.classList.toggle("active", card.dataset.certificateType === type);
    }
    internalChoices.hidden = type !== "internal_ip";
    if (type === "internal_ip") {
      void renderInternalIpChoices(form, internalChoices, showOutput);
    }
  };
  for (const card of cards) {
    card.addEventListener("click", () => {
      if (typeControl) {
        typeControl.value = card.dataset.certificateType || "domain";
        syncType();
      }
    });
  }
  typeControl?.addEventListener("change", syncType);
  syncType();
  form.append(el("div", { className: "toolbar-actions" }, [
    button("提交申请并开始验证", async () => {
      try {
        const payload = readFields(form, fields);
        const result = await api("/admin-api/system/certificate-request", {
          method: "POST",
          body: JSON.stringify({
            request_type: payload.certificate_request_type,
            domain_names: payload.request_domain_names,
            ip_addresses: payload.request_ip_addresses
          })
        });
        const verified = await certificateRequestIsDeployed(payload);
        showOutput(verified ? "证书已部署，真实状态已回读。" : "申请已受理，正在验证与部署；只有真实证书回读成功后才视为完成。");
      } catch (error) {
        showOutput(adminErrorMessage(error, "证书申请失败，请检查域名或 IP 地址"));
      }
    })
  ]));
  form.append(output);
  return el("section", { className: "certificate-request" }, [el("h3", { text: "申请或更换证书" }), form]);
}

async function renderInternalIpChoices(form, container, showOutput) {
  const ipControl = form.querySelector('[name="request_ip_addresses"]');
  if (!ipControl) {
    return;
  }
  clear(container);
  container.append(el("strong", { text: "选择一个内网 IP" }), el("small", { text: "仅会为当前选中的一个 IP 申请内部 CA 证书。" }));
  try {
    const result = await api("/admin-api/system/network-interface-ips");
    const ips = Array.isArray(result.data?.ips) ? result.data.ips : [];
    if (ips.length === 0) {
      container.append(el("p", { className: "notice", text: "未发现可选内网 IP，请在下方手工输入一个 IP。" }));
      return;
    }
    const choices = el("div", { className: "internal-ip-choice-list" });
    for (const ip of ips) {
      const choice = el("button", {
        type: "button",
        className: "internal-ip-choice",
        "data-internal-ip-choice": ip,
        text: ip,
        onClick: () => {
          ipControl.value = ip;
          for (const item of choices.querySelectorAll(".internal-ip-choice")) {
            item.classList.toggle("active", item.dataset.internalIpChoice === ip);
          }
        }
      });
      choice.classList.toggle("active", ipControl.value.trim() === ip);
      choices.append(choice);
    }
    container.append(choices);
  } catch (error) {
    showOutput(adminErrorMessage(error, "获取内网 IP 候选失败，请手工输入一个 IP"));
  }
}

function certificateTypeCard(type, title, detail) {
  return el("button", {
    type: "button",
    className: "certificate-type-card",
    dataset: {
      certificateType: type,
      certificateIssuer: type === "public_ip" ? "letsencrypt_shortlived" : type === "internal_ip" ? "internal_ca" : "caddy_acme"
    }
  }, [
    el("strong", { text: title }),
    el("span", { text: detail })
  ]);
}

function certificateSummaryCard(label, value, detail) {
  return el("article", { className: "certificate-summary-card" }, [
    el("span", { text: label }), el("strong", { text: value }), el("small", { text: detail })
  ]);
}

function certificateRequestTypeLabel(type) {
  return { domain: "域名", public_ip: "公网 IP", internal_ip: "内网 IP" }[type] || "域名";
}

function certificateRequestTypeHelp(type) {
  return {
    domain: "域名证书会使用公网 ACME 验证。请确保域名解析和 80/443 端口可达。",
    public_ip: "公网 IP 证书使用 Let’s Encrypt short-lived profile；仅支持公网 IP 的 HTTP-01 或 TLS-ALPN-01 验证。",
    internal_ip: "内网 IP 只使用内部 CA，不会向公网 CA 发起申请。客户端需要信任内部根证书。"
  }[type] || "";
}

function renderCertificateRequestProgress(request) {
  const status = request.status || "requested";
  const stages = [["requested", "已受理", "已保存目标与签发策略"], ["validating", "验证中", "检查挑战条件与 CA 验证"], ["deploying", "部署中", "Caddy 正在加载证书"], ["verified", "已部署", "真实证书状态已回读"]];
  const activeIndex = Math.max(0, stages.findIndex(([key]) => key === status));
  return el("aside", { className: "certificate-request-progress" }, [
    el("h3", { text: "申请进度" }),
    ...stages.map(([key, title, detail], index) => el("div", { className: `certificate-progress-step ${index <= activeIndex ? "active" : ""}`.trim() }, [
      el("span", { text: index < activeIndex ? "✓" : String(index + 1) }),
      el("div", {}, [el("strong", { text: title }), el("small", { text: detail })])
    ]))
  ]);
}

async function certificateRequestIsDeployed(payload) {
  const result = await api("/admin-api/system/certificate-status");
  const certificates = Array.isArray(result.data?.certificates) ? result.data.certificates : [];
  const source = payload.certificate_request_type === "domain" ? payload.request_domain_names : payload.request_ip_addresses;
  const targets = String(source || "").split(/[\n,;]+/).map((value) => value.trim()).filter(Boolean);
  return targets.length > 0 && certificates.some((certificate) => {
    const names = [...(certificate.domains || []), ...(certificate.ip_addresses || [])];
    return targets.every((target) => names.includes(target));
  });
}

function renderCertificateInventory(data) {
  const certificates = Array.isArray(data?.certificates) ? data.certificates : [];
  return el("section", { className: "certificate-inventory-results" }, [
    el("header", { className: "table-toolbar" }, [el("h3", { text: "受管证书" }), el("span", { text: `共 ${certificates.length} 张` })]),
    certificates.length > 0 ? renderCertificateStatusTable(certificates) : statusMessage("暂无可读取的真实证书。申请完成后会在此处显示已部署状态。")
  ]);
}



function renderCertificateAlertCheck() {
  const output = el("p", { className: "notice", hidden: true });
  const showOutput = (text) => {
    setText(output, text);
    output.hidden = false;
  };
  return el("div", { className: "certificate-alert-check" }, [
    el("p", { className: "notice", text: "根据真实证书状态和到期阈值生成证书告警。" }),
    button("检查证书告警", async () => {
      try {
        const checked = await api("/admin-api/system/settings-center/certificate-alert-check", { method: "POST" });
        showOutput(operationSuccessText(checked.data, "证书告警检查已完成"));
      } catch (error) {
        showOutput(adminErrorMessage(error, "证书告警检查失败"));
      }
    }, "secondary"),
    output
  ]);
}

function settingsFormPanel(title, section, fields, values, { standalone = false } = {}) {
  const form = el("form", { className: "edit-form settings-form", onSubmit: (event) => event.preventDefault() });
  for (const config of fields) {
    form.append(field(config, values[config.name]));
  }
  const output = el("p", { className: "notice", hidden: true });
  const showOutput = (text) => {
    setText(output, text);
    output.hidden = false;
  };
  form.append(el("div", { className: "toolbar-actions" }, [
    button("保存", async () => {
      try {
        const payload = readFields(form, fields);
        await api(`/admin-api/system/settings-center/${section}`, {
          method: "PUT",
          body: JSON.stringify(payload)
        });
        showOutput(`${title}已保存`);
      } catch (error) {
        showOutput(adminErrorMessage(error, "保存设置失败，请检查输入内容"));
      }
    }),
    section === "tls_certificates" ? button("检查证书告警", async () => {
      try {
        const checked = await api("/admin-api/system/settings-center/certificate-alert-check", { method: "POST" });
        showOutput(operationSuccessText(checked.data, "证书告警检查已完成"));
      } catch (error) {
        showOutput(adminErrorMessage(error, "证书告警检查失败"));
      }
    }, "secondary") : null
  ].filter(Boolean)));
  form.append(output);
  if (standalone) {
    // The page title sits above the panel (page head), so the panel is just the form.
    return el("section", { className: "panel settings-form-panel" }, [form]);
  }
  return el("section", { className: "panel" }, [
    el("h2", { text: title }),
    form
  ]);
}

async function renderCertificateStatus(content) {
  const panel = el("section", { className: "panel table-panel" }, [
    el("h2", { text: "真实证书状态" }),
    statusMessage("正在读取真实证书信息")
  ]);
  content.append(panel);
  try {
    const result = await api("/admin-api/system/certificate-status");
    const data = result.data || {};
    clear(panel);
    panel.append(renderCertificateSummary(data.summary || {}));
    panel.append(renderCertificateInventory(data));
  } catch (error) {
    clear(panel);
    panel.append(el("h2", { text: "真实证书状态" }));
    panel.append(statusMessage(adminErrorMessage(error, "证书状态加载失败"), "error"));
  }
}

function renderCertificateSummary(summary) {
  return el("section", { className: "metric-grid" }, [
    metric("证书总数", summary.total || 0),
    metric("域名证书", summary.domain_certificates || 0),
    metric("IP 证书", summary.ip_certificates || 0),
    metric("即将到期", summary.expiring_soon || 0),
    metric("已过期", summary.expired || 0),
    metric("自动续期", summary.auto_renewal_managed || 0)
  ]);
}

function renderCertificateStatusTable(certificates) {
  const columns = ["name", "certificate_type", "targets", "issuer", "status", "days_remaining", "expires_at", "renewal_source", "source_type"];
  const table = el("table", { className: "resource-table resource-table--certificate-status" });
  table.append(el("thead", {}, [
    el("tr", {}, columns.map((column) => el("th", { text: certificateColumnLabel(column) })))
  ]));
  const body = el("tbody");
  for (const certificate of certificates) {
    body.append(el("tr", {}, columns.map((column) => el("td", {
      text: certificateStatusValue(certificate, column)
    }))));
  }
  table.append(body);
  return table;
}

function certificateStatusValue(certificate, column) {
  if (column === "certificate_type") {
    return certificate.certificate_type === "ip" ? "IP 证书" : "域名证书";
  }
  if (column === "targets") {
    return [...(certificate.domains || []), ...(certificate.ip_addresses || [])].join(", ");
  }
  if (column === "status") {
    return certificateStatusLabel(certificate.status);
  }
  if (column === "renewal_source") {
    return certificate.renewal_source === "caddy" ? "Caddy 自动续期" : "证书文件";
  }
  if (column === "source_type") {
    return certificate.source_type === "caddy" ? "Caddy 存储" : "文件路径";
  }
  return formatResourceValue(column, certificate[column]);
}

function certificateColumnLabel(column) {
  return {
    name: "证书文件",
    certificate_type: "类型",
    targets: "域名或 IP",
    issuer: "签发者",
    status: "状态",
    days_remaining: "剩余天数",
    expires_at: "到期时间",
    renewal_source: "续期来源",
    source_type: "来源"
  }[column] || columnLabel(column);
}

function certificateStatusLabel(status) {
  return {
    valid: "正常",
    expiring_soon: "即将到期",
    expired: "已过期"
  }[status] || status || "";
}
