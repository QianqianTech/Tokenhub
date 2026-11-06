import { getLocale, localize, translate } from "./i18n.js";
let csrfToken = "";
let authRequiredHandler = () => {};
let errorHandler = () => {};
let routeSignal = null;
let lastRequestId = "";

// Error messages live in the i18n dictionary (i18n/zh-CN.js: errors).
function errorText(code, fallback = undefined) {
  return code ? translate(`error.${code}`, fallback) : fallback;
}

export function adminErrorMessage(error, fallback = localize("请求失败，请稍后重试")) {
  const code = error?.code || "";
  const message = errorText(code) || fallback;
  const requestId = error?.requestId;
  return requestId ? localize`${message}（请求编号：${requestId}）` : message;
}

export function isAbortError(error) {
  return error?.name === "AbortError";
}

export function configureApi({ onAuthRequired, onError } = {}) {
  authRequiredHandler = typeof onAuthRequired === "function" ? onAuthRequired : authRequiredHandler;
  errorHandler = typeof onError === "function" ? onError : errorHandler;
}

export function setCsrf(token) {
  csrfToken = token || "";
}

// TokenHub APP: requests authenticate with a bearer access token instead of the
// console cookie. The provider supplies the header, renews an expired token
// (one retry per request) and handles step-up verification for sensitive
// operations. The console never sets a provider.
let authProvider = null;

export function setAuthProvider(provider) {
  authProvider = provider || null;
}

// Read requests made while a page is shown are tied to that page: when the
// user navigates away the router aborts the signal and the stale page code
// simply stops (its pending promise never settles), so it cannot write into
// the new page or report a spurious error.
export function setRouteSignal(signal) {
  routeSignal = signal || null;
}


function rememberRequestId(requestId) {
  if (!requestId) {
    return;
  }
  lastRequestId = requestId;
  const node = typeof document !== "undefined" ? document.getElementById("request-id") : null;
  if (node) {
    node.textContent = requestId;
  }
}

export function queryString(params = {}) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== "") {
      search.set(key, Array.isArray(value) ? value.join(",") : value);
    }
  }
  const text = search.toString();
  return text ? `?${text}` : "";
}

function requestHeaders(fetchOptions, accept) {
  return {
    "Accept": accept,
    "X-TokenHub-Locale": getLocale(),
    ...(fetchOptions.body && !(fetchOptions.body instanceof FormData) ? { "Content-Type": "application/json" } : {}),
    ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {}),
    ...(authProvider?.headers?.() || {}),
    ...(fetchOptions.headers || {})
  };
}

function resolveSignal(fetchOptions) {
  if (fetchOptions.signal !== undefined) {
    return { signal: fetchOptions.signal || undefined, routeBound: false };
  }
  const method = String(fetchOptions.method || "GET").toUpperCase();
  // Writes are never aborted on navigation: the server may already have applied them.
  if (method === "GET" && routeSignal) {
    return { signal: routeSignal, routeBound: true };
  }
  return { signal: undefined, routeBound: false };
}

const never = () => new Promise(() => {});

function buildError(payload, response, requestId) {
  const error = new Error(payload?.error?.message || errorText("REQUEST_FAILED"));
  error.code = payload?.error?.code || (response?.status === 404 ? "NOT_FOUND" : "REQUEST_FAILED");
  error.status = response?.status || 0;
  error.requestId = payload?.request_id || requestId;
  error.details = payload?.error?.details;
  return error;
}

function dispatchFailure(error) {
  if (error.code === "ADMIN_UNAUTHENTICATED" || error.status === 401) {
    authRequiredHandler(error);
  } else if (error.code === "ADMIN_CSRF_INVALID" || error.status === 403 || error.status === 419) {
    errorHandler(error);
  }
}

// Lets the APP auth provider recover from an expired token (401) or a
// step-up challenge (403 ADMIN_STEP_UP_REQUIRED) and replay the request once.
async function recoverAuth(error, retried) {
  if (!authProvider || retried) {
    return false;
  }
  try {
    if (error.status === 401 && typeof authProvider.refresh === "function") {
      return Boolean(await authProvider.refresh());
    }
    if (error.code === "ADMIN_STEP_UP_REQUIRED" && typeof authProvider.stepUp === "function") {
      return Boolean(await authProvider.stepUp(error));
    }
  } catch {
    return false;
  }
  return false;
}

export async function api(path, options = {}) {
  // silent: the caller handles failures itself (no global error banner).
  const { query, silent = false, __authRetried = false, ...fetchOptions } = options;
  const { signal, routeBound } = resolveSignal(fetchOptions);
  let response;
  try {
    response = await fetch(`${path}${queryString(query)}`, {
      credentials: "same-origin",
      ...fetchOptions,
      signal,
      headers: requestHeaders(fetchOptions, "application/json")
    });
  } catch (cause) {
    if (isAbortError(cause)) {
      if (routeBound) {
        return never();
      }
      throw cause;
    }
    const error = new Error(errorText("NETWORK_ERROR"));
    error.code = "NETWORK_ERROR";
    error.status = 0;
    throw error;
  }
  const requestId = response.headers.get("X-Request-Id") || "";
  let payload;
  try {
    payload = await response.json();
  } catch (cause) {
    if (isAbortError(cause) && routeBound) {
      return never();
    }
    payload = { ok: false, error: { code: "BAD_RESPONSE", message: localize("服务返回格式异常") }, request_id: requestId };
  }
  rememberRequestId(payload?.request_id || requestId);
  if (!response.ok || payload.ok === false) {
    const error = buildError(payload, response, requestId);
    if (await recoverAuth(error, __authRetried)) {
      return api(path, { ...options, __authRetried: true });
    }
    if (!silent || error.status === 401) {
      dispatchFailure(error);
    }
    throw error;
  }
  const totalHeader = response.headers.get("X-Total-Count");
  const total = totalHeader === null || totalHeader === "" ? null : Number(totalHeader);
  return { data: payload.data, requestId: payload.request_id || requestId, total: Number.isFinite(total) ? total : null };
}

export async function streamApi(path, options = {}, onEvent = () => {}) {
  const { query, __authRetried = false, ...fetchOptions } = options;
  const response = await fetch(`${path}${queryString(query)}`, {
    credentials: "same-origin",
    ...fetchOptions,
    headers: requestHeaders(fetchOptions, "text/event-stream")
  });
  const requestId = response.headers.get("X-Request-Id") || "";
  rememberRequestId(requestId);
  if (!response.ok || !response.body) {
    const payload = await response.json().catch(() => ({
      error: { code: "BAD_RESPONSE", message: localize("服务返回格式异常") },
      request_id: requestId
    }));
    const error = buildError(payload, response, requestId);
    if (await recoverAuth(error, __authRetried)) {
      return streamApi(path, { ...options, __authRetried: true }, onEvent);
    }
    dispatchFailure(error);
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
  const controller = new AbortController();
  let timedOut = false;
  const timer = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, 10000);
  try {
    const result = await api("/admin-api/login", {
      method: "POST",
      body: JSON.stringify({ username, password }),
      signal: controller.signal
    });
    setCsrf(result.data.csrf_token);
    return result;
  } catch (error) {
    if (timedOut) {
      const timeout = new Error("Login request timed out");
      timeout.code = "ADMIN_LOGIN_TIMEOUT";
      timeout.status = 0;
      throw timeout;
    }
    if (error.code === "ADMIN_UNAUTHENTICATED" && error.status === 401) {
      error.code = "ADMIN_LOGIN_INVALID";
    }
    throw error;
  } finally {
    clearTimeout(timer);
  }
}
