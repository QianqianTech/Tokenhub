// Accessible dialogs built on the native <dialog> element: the rest of the
// page is inert while open, focus stays inside, Esc closes, focus returns to
// the control that opened it, and forms with unsaved edits ask before closing.
import { el } from "../dom.js";
import { t } from "../i18n.js";

let dialogSeq = 0;
let mainDialog = null;

function modalRoot() {
  return document.getElementById("modal-root") || document.body;
}

function isDirtyTracked(body) {
  return body.matches?.("form") || Boolean(body.querySelector?.("form"));
}

/**
 * Opens a dialog. Returns a handle with close() and the dialog element.
 * - guardDirty: ask before discarding edits made in the dialog's form fields.
 * - stack: open on top of the current dialog instead of replacing it.
 */
export function openModal({ title, body, size = "md", onClose = null, guardDirty = true, stack = false, className = "", dismissible = true }) {
  if (!stack && mainDialog) {
    mainDialog.close({ force: true });
  }
  const id = `modal-title-${++dialogSeq}`;
  const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  let dirty = false;
  let closed = false;

  const dialog = el("dialog", {
    className: `modal-dialog modal-dialog--${size} ${className}`.trim(),
    "aria-labelledby": id,
    "aria-modal": "true"
  });
  const closeButton = el("button", { type: "button", className: "secondary modal-close", text: t("common.close") });
  const bodyHost = el("div", { className: "modal-body" }, [body]);
  dialog.append(
    el("header", { className: "modal-header" }, [el("h2", { id, text: title }), dismissible ? closeButton : null]),
    bodyHost
  );

  const handle = {
    dialog,
    body: bodyHost,
    markClean() {
      dirty = false;
    },
    async close({ force = false } = {}) {
      if (closed) {
        return true;
      }
      if (!force && guardDirty && dirty) {
        const discard = await confirmDialog({
          title: t("modal.discardTitle"),
          message: t("modal.discardMessage"),
          confirmLabel: t("modal.discardConfirm"),
          cancelLabel: t("modal.keepEditing"),
          tone: "danger"
        });
        if (!discard) {
          return false;
        }
      }
      closed = true;
      if (dialog.open) {
        dialog.close();
      }
      dialog.remove();
      if (mainDialog === handle) {
        mainDialog = null;
      }
      if (opener?.isConnected) {
        opener.focus({ preventScroll: true });
      }
      onClose?.();
      return true;
    }
  };

  if (guardDirty && isDirtyTracked(body)) {
    const markDirty = (event) => {
      if (event.target instanceof HTMLElement && event.target.closest("form") && !event.target.matches("[readonly]")) {
        dirty = true;
      }
    };
    bodyHost.addEventListener("input", markDirty);
    bodyHost.addEventListener("change", markDirty);
  }
  closeButton.addEventListener("click", () => void handle.close());
  dialog.addEventListener("cancel", (event) => {
    event.preventDefault();
    if (dismissible) {
      void handle.close();
    }
  });
  // A click on the backdrop lands on the <dialog> element itself.
  dialog.addEventListener("mousedown", (event) => {
    if (dismissible && event.target === dialog) {
      const rect = dialog.getBoundingClientRect();
      const outside = event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom;
      if (outside) {
        void handle.close();
      }
    }
  });

  modalRoot().append(dialog);
  dialog.showModal();
  const autofocus = bodyHost.querySelector("[autofocus], input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])");
  (autofocus || (dismissible ? closeButton : dialog)).focus({ preventScroll: true });
  if (!stack) {
    mainDialog = handle;
  }
  return handle;
}



/**
 * Asks for confirmation. Resolves true/false.
 * requireText: the user must type this text (e.g. the record name) to confirm
 * irreversible operations.
 */
export function confirmDialog({ title, message = "", details = [], confirmLabel = t("common.confirm"), cancelLabel = t("common.cancel"), tone = "danger", requireText = "", body: extra = null }) {
  return new Promise((resolve) => {
    let settled = false;
    const confirmButton = el("button", { type: "button", className: tone === "danger" ? "danger" : "", text: confirmLabel });
    const cancelButton = el("button", { type: "button", className: "secondary", text: cancelLabel });
    const typed = requireText ? el("input", { type: "text", className: "confirm-input", autocomplete: "off", "aria-label": t("confirm.typeToConfirm", { text: requireText }) }) : null;
    if (typed) {
      confirmButton.disabled = true;
      typed.addEventListener("input", () => {
        confirmButton.disabled = typed.value.trim() !== requireText;
      });
    }
    const body = el("div", { className: "confirm-dialog" }, [
      message ? el("p", { className: "confirm-message", text: message }) : null,
      details.length ? el("dl", { className: "confirm-details" }, details.flatMap(([label, value]) => [el("dt", { text: label }), el("dd", { text: value })])) : null,
      typed ? el("label", { className: "field" }, [el("span", { text: t("confirm.typeToConfirm", { text: requireText }) }), typed]) : null,
      extra,
      el("div", { className: "toolbar-actions confirm-actions" }, [cancelButton, confirmButton])
    ]);
    const handle = openModal({
      title,
      body,
      size: "sm",
      guardDirty: false,
      stack: true,
      className: "modal-dialog--confirm",
      onClose: () => {
        if (!settled) {
          settled = true;
          resolve(false);
        }
      }
    });
    const finish = (value) => {
      if (settled) {
        return;
      }
      settled = true;
      resolve(value);
      void handle.close({ force: true });
    };
    confirmButton.addEventListener("click", () => {
      if (!typed || typed.value.trim() === requireText) {
        finish(true);
      }
    });
    cancelButton.addEventListener("click", () => finish(false));
    (typed || (tone === "danger" ? cancelButton : confirmButton)).focus();
  });
}

/** Asks for a short text (e.g. a disposition note). Resolves the text or null. */
export function promptDialog({ title, label, placeholder = "", required = true, multiline = true, confirmLabel = t("common.confirm"), initialValue = "" }) {
  return new Promise((resolve) => {
    let settled = false;
    const input = multiline
      ? el("textarea", { rows: 4, placeholder, value: initialValue })
      : el("input", { type: "text", placeholder, value: initialValue });
    const errorText = el("p", { className: "field-error", hidden: true, text: t("prompt.required") });
    const confirmButton = el("button", { type: "submit", text: confirmLabel });
    const form = el("form", { className: "edit-form prompt-dialog" }, [
      el("label", { className: "field" }, [el("span", { text: label }), input]),
      errorText,
      el("div", { className: "toolbar-actions" }, [
        el("button", { type: "button", className: "secondary", text: t("common.cancel"), onClick: () => finish(null) }),
        confirmButton
      ])
    ]);
    const handle = openModal({
      title,
      body: form,
      size: "sm",
      guardDirty: false,
      stack: true,
      onClose: () => {
        if (!settled) {
          settled = true;
          resolve(null);
        }
      }
    });
    function finish(value) {
      if (settled) {
        return;
      }
      settled = true;
      resolve(value);
      void handle.close({ force: true });
    }
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      const value = input.value.trim();
      if (required && !value) {
        errorText.hidden = false;
        input.setAttribute("aria-invalid", "true");
        input.focus();
        return;
      }
      finish(value);
    });
    input.focus();
  });
}
