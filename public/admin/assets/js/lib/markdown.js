// Minimal, safe Markdown for model replies. Every node is built with
// createElement/textContent: model output is never parsed as HTML, so a reply
// cannot inject markup or scripts. Supported: headings, paragraphs, fenced
// code, inline code, bold, italic, links (http/https only), ordered and
// unordered lists, block quotes and horizontal rules.

const SAFE_URL = /^(https?:\/\/|mailto:)/i;

function node(tag, className, text) {
  const element = document.createElement(tag);
  if (className) element.className = className;
  if (text !== undefined) element.textContent = text;
  return element;
}

// Inline tokens, longest/most specific first.
const INLINE = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*)|(__[^_\n]+__)|(\*[^*\s][^*\n]*\*)|(\[[^\]\n]+\]\([^)\s]+\))/;

export function renderInline(text) {
  const fragment = document.createDocumentFragment();
  let rest = String(text ?? "");
  while (rest) {
    const match = rest.match(INLINE);
    if (!match) {
      fragment.append(document.createTextNode(rest));
      break;
    }
    if (match.index > 0) {
      fragment.append(document.createTextNode(rest.slice(0, match.index)));
    }
    const token = match[0];
    if (match[1]) {
      fragment.append(node("code", "md-code-inline", token.slice(1, -1)));
    } else if (match[2] || match[3]) {
      const strong = node("strong");
      strong.append(renderInline(token.slice(2, -2)));
      fragment.append(strong);
    } else if (match[4]) {
      const em = node("em");
      em.append(renderInline(token.slice(1, -1)));
      fragment.append(em);
    } else if (match[5]) {
      const [, label, href] = token.match(/^\[([^\]]+)\]\(([^)\s]+)\)$/) || [];
      if (href && SAFE_URL.test(href)) {
        const link = node("a", "md-link", label);
        link.href = href;
        link.target = "_blank";
        link.rel = "noopener noreferrer nofollow";
        fragment.append(link);
      } else {
        fragment.append(document.createTextNode(token));
      }
    }
    rest = rest.slice(match.index + token.length);
  }
  return fragment;
}

function paragraph(lines) {
  const p = node("p");
  lines.forEach((line, index) => {
    if (index > 0) p.append(document.createElement("br"));
    p.append(renderInline(line));
  });
  return p;
}

/** Renders Markdown text into a DocumentFragment of safe DOM nodes. */
export function renderMarkdown(source) {
  const fragment = document.createDocumentFragment();
  const lines = String(source ?? "").replace(/\r\n?/g, "\n").split("\n");
  let index = 0;
  let buffer = [];
  const flush = () => {
    if (buffer.length) {
      fragment.append(paragraph(buffer));
      buffer = [];
    }
  };
  while (index < lines.length) {
    const line = lines[index];
    const fence = line.match(/^\s*(```|~~~)\s*([\w+#.-]*)\s*$/);
    if (fence) {
      flush();
      const body = [];
      index += 1;
      while (index < lines.length && !lines[index].trim().startsWith(fence[1])) {
        body.push(lines[index]);
        index += 1;
      }
      index += 1; // closing fence (or end of a still-streaming reply)
      const pre = node("pre", "md-code-block");
      const code = node("code", fence[2] ? `language-${fence[2]}` : "", body.join("\n"));
      if (fence[2]) pre.dataset.language = fence[2];
      pre.append(code);
      fragment.append(pre);
      continue;
    }
    const heading = line.match(/^(#{1,6})\s+(.*)$/);
    if (heading) {
      flush();
      const level = Math.min(6, heading[1].length + 2); // h3..h6 inside a message
      const h = node(`h${Math.min(level, 6)}`, "md-heading");
      h.append(renderInline(heading[2]));
      fragment.append(h);
      index += 1;
      continue;
    }
    if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) {
      flush();
      fragment.append(node("hr"));
      index += 1;
      continue;
    }
    const listMatch = line.match(/^\s*([-*+]|\d+[.)])\s+(.*)$/);
    if (listMatch) {
      flush();
      const ordered = /\d/.test(listMatch[1]);
      const list = node(ordered ? "ol" : "ul", "md-list");
      if (ordered) {
        const start = Number.parseInt(listMatch[1], 10);
        if (start > 1) list.start = start;
      }
      while (index < lines.length) {
        const item = lines[index].match(/^\s*([-*+]|\d+[.)])\s+(.*)$/);
        if (!item || /\d/.test(item[1]) !== ordered) break;
        const li = node("li");
        li.append(renderInline(item[2]));
        list.append(li);
        index += 1;
      }
      fragment.append(list);
      continue;
    }
    if (/^\s*>/.test(line)) {
      flush();
      const quoted = [];
      while (index < lines.length && /^\s*>/.test(lines[index])) {
        quoted.push(lines[index].replace(/^\s*>\s?/, ""));
        index += 1;
      }
      const quote = node("blockquote", "md-quote");
      quote.append(renderMarkdown(quoted.join("\n")));
      fragment.append(quote);
      continue;
    }
    if (line.trim() === "") {
      flush();
    } else {
      buffer.push(line);
    }
    index += 1;
  }
  flush();
  return fragment;
}
