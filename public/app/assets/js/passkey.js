// Passkeys (WebAuthn) as a step-up method next to the PIN. They need HTTPS and
// a domain name (WebAuthn does not work on IP addresses).
import { api } from "/admin/assets/js/api.js";

const toBytes = (value) => Uint8Array.from(atob(String(value).replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (String(value).length % 4)) % 4)), (char) => char.charCodeAt(0));
const toB64 = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");

export function passkeySupported() {
  return window.isSecureContext && typeof window.PublicKeyCredential === "function" && !/^\d+\.\d+\.\d+\.\d+$|^\[/.test(window.location.hostname);
}

export async function registerPasskey(name = "") {
  const { data } = await api("/admin-api/app-auth/passkeys/options", { method: "POST", body: JSON.stringify({ purpose: "register" }), silent: true });
  const credential = await navigator.credentials.create({
    publicKey: {
      ...data,
      challenge: toBytes(data.challenge),
      user: { ...data.user, id: toBytes(data.user.id) },
      excludeCredentials: (data.excludeCredentials || []).map((item) => ({ ...item, id: toBytes(item.id) }))
    }
  });
  if (!credential) {
    throw new Error("没有创建 Passkey。");
  }
  const payload = {
    id: credential.id,
    rawId: toB64(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: toB64(credential.response.clientDataJSON),
      attestationObject: toB64(credential.response.attestationObject)
    }
  };
  return (await api("/admin-api/app-auth/passkeys", { method: "POST", body: JSON.stringify({ credential: payload, name }), silent: true })).data;
}

export async function verifyWithPasskey() {
  const { data } = await api("/admin-api/app-auth/passkeys/options", { method: "POST", body: JSON.stringify({ purpose: "verify" }), silent: true });
  const assertion = await navigator.credentials.get({
    publicKey: {
      ...data,
      challenge: toBytes(data.challenge),
      allowCredentials: (data.allowCredentials || []).map((item) => ({ ...item, id: toBytes(item.id) }))
    }
  });
  if (!assertion) {
    throw new Error("没有完成 Passkey 验证。");
  }
  await api("/admin-api/app-auth/step-up/passkey", {
    method: "POST",
    silent: true,
    body: JSON.stringify({
      credential: {
        id: assertion.id,
        rawId: toB64(assertion.rawId),
        type: assertion.type,
        response: {
          clientDataJSON: toB64(assertion.response.clientDataJSON),
          authenticatorData: toB64(assertion.response.authenticatorData),
          signature: toB64(assertion.response.signature)
        }
      }
    })
  });
  return true;
}

export async function removePasskeys() {
  return (await api("/admin-api/app-auth/passkeys", { method: "DELETE", silent: true })).data;
}
