let csrfToken = "";
let authRequiredHandler = () => {};
let errorHandler = () => {};

const adminErrorMessages = {
  ADMIN_UNAUTHENTICATED: "登录状态已失效，请重新登录",
  ADMIN_CSRF_INVALID: "页面安全校验失效，请刷新后重试",
  ADMIN_PERMISSION_DENIED: "当前账号没有权限执行此操作",
  ADMIN_INVALID_PAYLOAD: "提交内容不完整或格式不正确",
  ADMIN_INVALID_DEPARTMENT_MOVE: "不能把部门移动到自身下级",
  ADMIN_INVALID_SETTING_SECTION: "未知的设置分区",
  ADMIN_NOT_FOUND: "记录不存在或已被删除",
  CONTENT_FORBIDDEN: "无权访问该内容或内容已失效",
  DESENSITIZATION_BLOCKED: "内容命中了阻断型脱敏策略",
  ADMIN_CHAT_INVALID_PAYLOAD: "会话内容不完整",
  ADMIN_CHAT_MODEL_NOT_FOUND: "当前模型不可用或不支持会话测试",
  ADMIN_CHAT_KEY_NOT_FOUND: "所选 API 密钥不存在或不在管理范围内",
  ADMIN_WRITE_CONFLICT: "记录已被他人修改，请关闭后刷新列表再修改",
  ADMIN_VALIDATION_FAILED: "配置校验未通过，请检查输入内容",
  QUOTA_ADJUSTMENT_INVALID: "额度调整无效：需选择指标、填写非零调整量和原因",
  QUOTA_POLICY_NOT_FOUND: "该额度策略未绑定到所选对象",
  API_KEY_INVALID: "API 密钥无效、已停用，或其员工/部门已停用",
  API_KEY_EXPIRED: "API 密钥已过期",
  MODEL_FORBIDDEN: "该 API 密钥未被授权使用此模型",
  MODEL_NOT_FOUND: "模型不存在",
  MODEL_UNAVAILABLE: "模型当前没有可用通道",
  MODEL_CAPABILITY_UNSUPPORTED: "没有支持该请求功能的可用通道",
  QUOTA_EXCEEDED: "额度已用尽",
  MODEL_QUOTA_EXCEEDED: "该密钥的模型限额已用尽",
  CONCURRENCY_EXCEEDED: "并发请求过多，请稍后重试",
  PROVIDER_RATE_LIMITED: "上游模型限流，请稍后重试",
  PROVIDER_REJECTED: "上游模型拒绝了请求，请检查模型参数或厂商限制",
  PROVIDER_ERROR: "上游模型服务异常",
  PROVIDER_TIMEOUT: "上游模型请求超时",
  PROVIDER_INVALID_RESPONSE: "上游模型返回格式异常",
  BAD_RESPONSE: "服务返回格式异常",
  BAD_INPUT: "输入内容格式不正确",
  REQUEST_FAILED: "请求失败，请稍后重试",
  NOT_FOUND: "请求的资源不存在",
  INTERNAL_ERROR: "服务内部异常，请稍后重试"
};

export function adminErrorMessage(error, fallback = "请求失败，请稍后重试") {
  const code = error?.code || "";
  const message = adminErrorMessages[code] || fallback;
  const requestId = error?.requestId;
  return requestId ? `${message}（请求编号：${requestId}）` : message;
}

export function configureApi({ onAuthRequired, onError } = {}) {
  authRequiredHandler = typeof onAuthRequired === "function" ? onAuthRequired : authRequiredHandler;
  errorHandler = typeof onError === "function" ? onError : errorHandler;
}

export function setCsrf(token) {
  csrfToken = token || "";
}

export function queryString(params = {}) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== "") {
      search.set(key, value);
    }
  }
  const text = search.toString();
  return text ? `?${text}` : "";
}

export async function api(path, options = {}) {
  const { query, ...fetchOptions } = options;
  const headers = {
    "Accept": "application/json",
    ...(fetchOptions.body ? { "Content-Type": "application/json" } : {}),
    ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {}),
    ...(fetchOptions.headers || {})
  };
  const response = await fetch(`${path}${queryString(query)}`, {
    credentials: "same-origin",
    ...fetchOptions,
    headers
  });
  const requestId = response.headers.get("X-Request-Id") || "";
  const payload = await response.json().catch(() => ({
    ok: false,
    error: { code: "BAD_RESPONSE", message: "服务返回格式异常" },
    request_id: requestId
  }));
  if (!response.ok || payload.ok === false) {
    const error = new Error(payload.error?.message || adminErrorMessages.REQUEST_FAILED);
    error.code = payload.error?.code || "REQUEST_FAILED";
    error.status = response.status;
    error.requestId = payload.request_id || requestId;
    if (error.code === "ADMIN_UNAUTHENTICATED" || response.status === 401) {
      authRequiredHandler(error);
    } else if (error.code === "ADMIN_CSRF_INVALID" || response.status === 403 || response.status === 419) {
      errorHandler(error);
    }
    throw error;
  }
  return { data: payload.data, requestId: payload.request_id || requestId };
}

export async function streamApi(path, options = {}, onEvent = () => {}) {
  const { query, ...fetchOptions } = options;
  const headers = {
    "Accept": "text/event-stream",
    ...(fetchOptions.body ? { "Content-Type": "application/json" } : {}),
    ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {}),
    ...(fetchOptions.headers || {})
  };
  const response = await fetch(`${path}${queryString(query)}`, {
    credentials: "same-origin",
    ...fetchOptions,
    headers
  });
  const requestId = response.headers.get("X-Request-Id") || "";
  if (!response.ok || !response.body) {
    const payload = await response.json().catch(() => ({
      error: { code: "BAD_RESPONSE", message: "服务返回格式异常" },
      request_id: requestId
    }));
    const error = new Error(payload.error?.message || adminErrorMessages.REQUEST_FAILED);
    error.code = payload.error?.code || "REQUEST_FAILED";
    error.status = response.status;
    error.requestId = payload.request_id || requestId;
    throw error;
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  while (true) {
    const { done, value } = await reader.read();
    if (done) {
      break;
    }
    buffer += decoder.decode(value, { stream: true });
    const parts = buffer.split(/\r?\n\r?\n/);
    buffer = parts.pop() || "";
    for (const part of parts) {
      const data = part
        .split(/\r?\n/)
        .filter((line) => line.startsWith("data:"))
        .map((line) => line.slice(5).trim())
        .join("\n");
      if (data) {
        try {
          onEvent(data);
        } catch (error) {
          error.requestId ||= requestId;
          throw error;
        }
      }
    }
  }
  return { requestId };
}

export async function login(username, password) {
  const result = await api("/admin-api/login", {
    method: "POST",
    body: JSON.stringify({ username, password })
  });
  setCsrf(result.data.csrf_token);
  return result;
}
