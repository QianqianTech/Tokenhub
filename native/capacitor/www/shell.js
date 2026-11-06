// Start screen of the TokenHub mobile shell: remembers the TokenHub servers the
// user signed in to and opens their /app/ page. A sign-in link
// (https://<server>/app/link#c=thl_…) both names the server and signs in; the
// page then stores the device credential in the Keychain/Keystore through the
// TokenHubSecureStore plugin (see public/app/assets/js/native-bridge.js).
(function () {
  "use strict";
  var KEY = "tokenhub.servers";
  var $ = function (id) { return document.getElementById(id); };

  var languageKey = "tokenhub.locale";
  var language = "zh-CN";
  try { if (localStorage.getItem(languageKey) === "en-US") language = "en-US"; } catch (e) { /* optional preference */ }
  var labels = Array.from(document.querySelectorAll("[data-en]"), function (node) {
    return { node: node, original: node.textContent };
  });
  function copy(chinese, english) {
    return language === "en-US" ? english : chinese;
  }
  function renderLanguage() {
    document.documentElement.lang = language;
    $("locale").value = language;
    $("locale").setAttribute("aria-label", copy("界面语言", "Interface language"));
    labels.forEach(function (item) {
      item.node.textContent = copy(item.original, item.node.dataset.en);
    });
  }
  renderLanguage();
  $("locale").addEventListener("change", function () {
    language = $("locale").value === "en-US" ? "en-US" : "zh-CN";
    try { localStorage.setItem(languageKey, language); } catch (e) { /* optional preference */ }
    $("error").textContent = "";
    renderLanguage();
  });

  function servers() {
    try { return JSON.parse(localStorage.getItem(KEY) || "[]").filter(function (s) { return /^https:\/\//.test(s); }); } catch (e) { return []; }
  }
  function remember(origin) {
    var list = servers().filter(function (s) { return s !== origin; });
    list.unshift(origin);
    localStorage.setItem(KEY, JSON.stringify(list.slice(0, 10)));
  }
  function parse(text) {
    text = String(text || "").trim();
    var deep = text.match(/^tokenhub:\/\/link\?u=(.+)$/);
    if (deep) { try { text = decodeURIComponent(deep[1]); } catch (e) { return null; } }
    try {
      var url = new URL(text);
      var code = new URLSearchParams(url.hash.replace(/^#/, "")).get("c") || "";
      if (url.protocol !== "https:") { return { error: copy("安装包只连接 HTTPS 服务器。纯 HTTP 部署请在浏览器中使用。", "The installed app connects to HTTPS servers only. Use a browser for an HTTP deployment.") }; }
      if (!/^thl_[0-9a-f]{64}$/.test(code)) { return null; }
      return { origin: url.origin, code: code };
    } catch (e) { return null; }
  }
  function open(origin, code) {
    remember(origin);
    var localeQuery = language === "en-US" ? "?lang=en-US" : "";
    window.location.href = origin + "/app/" + (code ? "link" : "") + localeQuery + (code ? "#c=" + code : "");
  }
  function go(text) {
    var parsed = parse(text);
    $("error").textContent = "";
    if (!parsed) { $("error").textContent = copy("这不是有效的认证链接，请在后台重新生成。", "Invalid sign-in link. Generate a new one in the admin console."); return; }
    if (parsed.error) { $("error").textContent = parsed.error; return; }
    open(parsed.origin, parsed.code);
  }
  function renderServers() {
    var list = servers();
    $("servers").hidden = list.length === 0;
    $("server-list").replaceChildren.apply($("server-list"), list.map(function (origin) {
      var li = document.createElement("li");
      var button = document.createElement("button");
      button.type = "button";
      button.textContent = origin.replace(/^https:\/\//, "");
      button.addEventListener("click", function () { open(origin, ""); });
      li.appendChild(button);
      return li;
    }));
  }

  $("go").addEventListener("click", function () { go($("link").value); });

  // Deep links: tokenhub://link?u=<encoded sign-in link> and https App Links.
  var App = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App;
  if (App) {
    App.addListener("appUrlOpen", function (event) { go(event.url); });
    App.getLaunchUrl().then(function (launch) { if (launch && launch.url) { go(launch.url); } }).catch(function () {});
  }

  renderServers();
  // Sent back here by a server page for a link of another server.
  var handoff = (window.location.hash.match(/^#link=(.+)$/) || [])[1];
  if (handoff) {
    history.replaceState(null, "", window.location.pathname);
    try { go(decodeURIComponent(handoff)); return; } catch (e) { /* ignore */ }
  }
  // Reopen the last server directly: the device stays signed in there.
  var last = servers()[0];
  if (last && !sessionStorage.getItem("tokenhub.picker")) {
    sessionStorage.setItem("tokenhub.picker", "1");
    open(last, "");
  }
})();
