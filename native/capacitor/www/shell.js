// Start screen of the TokenHub mobile shell: remembers the TokenHub servers the
// user signed in to and opens their /app/ page. A sign-in link
// (https://<server>/app/link#c=thl_…) both names the server and signs in; the
// page then stores the device credential in the Keychain/Keystore through the
// TokenHubSecureStore plugin (see public/app/assets/js/native-bridge.js).
(function () {
  "use strict";
  var KEY = "tokenhub.servers";
  var $ = function (id) { return document.getElementById(id); };

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
      if (url.protocol !== "https:") { return { error: "安装包只连接 HTTPS 服务器。纯 HTTP 部署请在浏览器中使用。" }; }
      if (!/^thl_[0-9a-f]{64}$/.test(code)) { return null; }
      return { origin: url.origin, code: code };
    } catch (e) { return null; }
  }
  function open(origin, code) {
    remember(origin);
    window.location.href = code ? origin + "/app/link#c=" + code : origin + "/app/";
  }
  function go(text) {
    var parsed = parse(text);
    $("error").textContent = "";
    if (!parsed) { $("error").textContent = "这不是有效的认证链接，请在后台重新生成。"; return; }
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
