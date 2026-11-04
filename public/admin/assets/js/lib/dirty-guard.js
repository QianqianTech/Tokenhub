// Unsaved-changes guard for forms that live on a page (settings, SMTP,
// certificates, retention policy …). Dialog forms have their own guard in
// components/modal.js.
//
//   guardForm(form)   – opt a page form in; returns the form
//   markClean(form)   – call after a successful save
//   hasUnsavedChanges() – checked by the shell before a route change and on
//                         beforeunload
//
// The baseline is taken when the user first touches the form (focus or
// pointer), so values filled in asynchronously after render never count as
// edits, and changing a value back to what it was is not "dirty".

const tracked = new Set();

function snapshot(form) {
  const parts = [];
  for (const control of form.querySelectorAll("input, select, textarea")) {
    if (!control.name && !control.id) continue;
    if (control.type === "button" || control.type === "submit" || control.type === "file") continue;
    const key = control.name || control.id;
    if (control.type === "checkbox" || control.type === "radio") {
      parts.push(`${key}=${control.value}:${control.checked ? 1 : 0}`);
    } else if (control.multiple && control.selectedOptions) {
      parts.push(`${key}=${[...control.selectedOptions].map((option) => option.value).join(",")}`);
    } else {
      parts.push(`${key}=${control.value}`);
    }
  }
  return parts.join("\u0001");
}

export function guardForm(form) {
  if (!form || tracked.has(form)) {
    return form;
  }
  tracked.add(form);
  form.dataset.dirtyGuard = "true";
  const capture = () => {
    if (form.__dirtyBaseline === undefined) {
      form.__dirtyBaseline = snapshot(form);
    }
  };
  form.addEventListener("focusin", capture, true);
  form.addEventListener("pointerdown", capture, true);
  return form;
}

export function markClean(form) {
  if (form && tracked.has(form)) {
    form.__dirtyBaseline = snapshot(form);
  }
}

export function isFormDirty(form) {
  return Boolean(form?.isConnected) && form.__dirtyBaseline !== undefined && snapshot(form) !== form.__dirtyBaseline;
}

export function hasUnsavedChanges() {
  for (const form of [...tracked]) {
    if (!form.isConnected) {
      tracked.delete(form);
    }
  }
  return [...tracked].some(isFormDirty);
}

// After the user chose to leave: forget every page form.
export function discardUnsavedChanges() {
  tracked.clear();
}
