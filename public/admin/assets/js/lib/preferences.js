// Small per-browser UI preferences (sidebar state, hidden table columns).
// Stored in cookies scoped to /admin: the admin UI deliberately keeps no
// data in the browser's web storage APIs.
const PREFIX = "tokenhub_admin_";
const MAX_AGE = 31536000;

export function readPreference(name) {
  const key = `${PREFIX}${name}=`;
  const item = document.cookie.split(";").map((part) => part.trim()).find((part) => part.startsWith(key));
  if (!item) {
    return null;
  }
  try {
    return decodeURIComponent(item.slice(key.length));
  } catch {
    return null;
  }
}

export function writePreference(name, value) {
  try {
    const encoded = encodeURIComponent(String(value ?? ""));
    if (encoded === "") {
      document.cookie = `${PREFIX}${name}=; Max-Age=0; Path=/admin; SameSite=Lax`;
      return true;
    }
    document.cookie = `${PREFIX}${name}=${encoded}; Max-Age=${MAX_AGE}; Path=/admin; SameSite=Lax`;
    return true;
  } catch {
    return false;
  }
}
