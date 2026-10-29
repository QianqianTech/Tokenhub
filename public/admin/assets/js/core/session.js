// Current administrator, shared by modules that need to know what the user may
// do (e.g. whether a lookup is allowed) without importing the app shell.
let currentUser = null;

export function setCurrentUser(user) {
  currentUser = user || null;
}

export function hasCapability(capability) {
  return Boolean(capability) && Array.isArray(currentUser?.permissions) && currentUser.permissions.includes(capability);
}

export function getCurrentUser() {
  return currentUser;
}
