// One-line descriptions shown under the page title of list pages.
// Written from the administrator's point of view: what the page is for.
export const PAGE_DESCRIPTIONS = {
  "dashboard-share-links": "生成给大屏使用的访问链接，可限定数据范围、指标和来源 IP。",
  "dashboard-share-access-logs": "大屏链接的每次访问与被拒记录，可在这里放行被拦截的 IP。",
  users: "用户归属部门后，其密钥用量计入该部门；角色决定能看到的菜单与数据范围。",
  "admin-users": "管理后台的登录账号，可按部门授权管理范围。",
  providers: "接入的模型厂商与协议地址。删除厂商会同时删除其下的模型配置。",
  models: "同一能力有多个模型时按路由优先级依次尝试，厂商异常时自动切换。",
  "model-prices": "按模型设置输入、输出与缓存 Token 单价，用于成本统计。",
  "api-keys": "为应用和员工签发访问网关的密钥，用量按密钥计量。",
  "api-key-model-quotas": "限定某个密钥对某个模型的调用额度。",
  "quota-policies": "可复用的额度规则，例如每日 Token 上限或每分钟请求数。",
  "quota-bindings": "把额度策略绑定到部门、用户或密钥上。",
  "usage-records": "每一次网关调用一条记录，可按请求编号追溯到会话存档与审计日志。",
  "multimodal-tasks": "图像、语音、视频等异步任务的执行记录。",
  "media-assets": "多模态调用产生或上传的文件，到期后自动清理。",
  "usage-contents": "按留存策略保存的调用内容摘要或原文。",
  "export-tasks": "导出任务在后台生成文件，完成后可在此下载。",
  "desensitization-policies": "识别并掩码身份证号、手机号等敏感信息的规则。",
  "desensitization-events": "命中脱敏规则的调用记录。",
  logs: "网关请求日志，包含耗时、错误码和来源 IP。",
  "audit-logs": "管理后台中的每一次变更操作。",
  "log-retention-policies": "各类日志保留多久、是否压缩与加密。",
  "log-archive-files": "超过保留期后归档的日志文件。"
};
