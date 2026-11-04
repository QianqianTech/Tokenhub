// One-line descriptions shown under the page title of list pages. The text
// lives in the i18n dictionary (i18n/zh-CN.js: pageDescriptions).
import { translate } from "../i18n.js";

export function pageDescription(key) {
  return translate(`page.${key}.description`, "");
}
