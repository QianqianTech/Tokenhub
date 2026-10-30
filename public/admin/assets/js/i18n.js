// UI strings shared by the admin shell and components. Page-specific copy still
// lives next to the page; new shared copy should be added here so wording stays
// consistent and can be customised per deployment in one place.
const messages = {
  "zh-CN": {
    "common.close": "关闭",
    "common.cancel": "取消",
    "common.confirm": "确认",
    "common.save": "保存",
    "common.retry": "重试",
    "common.copy": "复制",
    "common.search": "搜索",
    "common.reset": "重置",
    "common.loadMore": "加载更多",
    "common.clear": "清空",
    "common.selectAll": "全选",
    "common.none": "无",
    "feedback.notifications": "通知",
    "feedback.loading": "正在加载…",
    "feedback.empty": "暂无数据",
    "feedback.loadFailed": "加载失败，请稍后重试",
    "feedback.requestFailed": "操作失败，请稍后重试",
    "feedback.requestId": "请求编号",
    "feedback.copyRequestId": "复制请求编号",
    "feedback.copied": "已复制",
    "feedback.copyFailed": "复制失败，请手动选择文本",
    "feedback.saved": "已保存",
    "modal.discardTitle": "放弃未保存的修改？",
    "modal.discardMessage": "关闭后，本次填写的内容不会保存。",
    "modal.discardConfirm": "放弃修改",
    "modal.keepEditing": "继续编辑",
    "confirm.typeToConfirm": "请输入“{text}”以确认",
    "confirm.mismatch": "输入内容不一致",
    "prompt.required": "此项为必填",
    "pager.summary": "第 {from}–{to} 条，共 {total} 条",
    "pager.summaryUnknown": "第 {page} 页，本页 {count} 条",
    "pager.previous": "上一页",
    "pager.next": "下一页",
    "pager.pageSize": "每页",
    "pager.label": "分页",
    "select.placeholder": "输入关键字搜索",
    "select.noResults": "没有匹配的结果",
    "select.searching": "正在搜索…",
    "select.remove": "移除 {label}",
    "select.typeToSearch": "输入关键字搜索，或直接从列表选择",
    "table.columns": "显示列",
    "table.sortAsc": "升序",
    "table.sortDesc": "降序",
    "table.selection": "已选择 {count} 项",
    "table.noSelection": "未选择",
    "table.selectRow": "选择第 {id} 行",
    "table.selectPage": "选择当前页",
    "form.required": "请填写{label}",
    "form.select": "请选择{label}",
    "form.number": "{label}必须是数字",
    "form.json": "{label}不是有效的 JSON：{message}",
    "form.fixErrors": "请先修正标出的字段",
    "session.expiringTitle": "登录即将过期",
    "session.expiringMessage": "当前登录将在 {minutes} 分钟内过期，是否续期？",
    "session.renew": "继续使用",
    "session.renewed": "登录已续期",
    "session.expiredTitle": "登录已过期",
    "session.expiredMessage": "请重新登录。登录后会回到当前页面，未提交的表单内容会保留。",
    "session.login": "重新登录",
    "palette.title": "快速跳转",
    "palette.placeholder": "输入页面名称，例如：API 密钥、日志",
    "palette.empty": "没有匹配的页面",
    "palette.hint": "↑↓ 选择 · Enter 打开 · Esc 关闭",
    "palette.open": "快速跳转（Ctrl+K）"
  }
};

let locale = "zh-CN";

export function setLocale(next) {
  if (messages[next]) {
    locale = next;
  }
}

export function t(key, params = {}) {
  const template = messages[locale]?.[key] ?? messages["zh-CN"][key] ?? key;
  return template.replace(/\{(\w+)\}/g, (_, name) => (params[name] === undefined ? `{${name}}` : String(params[name])));
}
