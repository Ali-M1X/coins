/**
 * TheHybit — Minimal DOM helpers.
 *
 * Intentionally tiny. The page is server-rendered HTML; JavaScript only draws
 * charts, computes the analytics score and handles interaction. There is no
 * templating layer here and there should not be one — anything that renders
 * structure in JS would have to be rewritten as PHP at the WordPress port.
 */

/** querySelector, scoped. */
export const qs = (sel, root = document) => root.querySelector(sel);

/** querySelectorAll as a real array. */
export const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/** addEventListener with a disposer. */
export function on(target, type, handler, opts) {
  target.addEventListener(type, handler, opts);
  return () => target.removeEventListener(type, handler, opts);
}

/**
 * Create an element.
 * @param {string} tag
 * @param {object} [attrs]  attributes; `class`, `text` and `html` are special
 * @param {Array<Node|string>} [children]
 */
export function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  applyAttrs(node, attrs);
  appendAll(node, children);
  return node;
}

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Create an SVG element. Charts are built entirely from these.
 * @param {string} tag
 * @param {object} [attrs]
 * @param {Array<Node|string>} [children]
 */
export function svg(tag, attrs = {}, children = []) {
  const node = document.createElementNS(SVG_NS, tag);
  applyAttrs(node, attrs);
  appendAll(node, children);
  return node;
}

function applyAttrs(node, attrs) {
  for (const [k, v] of Object.entries(attrs)) {
    if (v == null || v === false) continue;
    if (k === 'class') node.setAttribute('class', v);
    else if (k === 'text') node.textContent = v;
    else if (k === 'html') node.innerHTML = v;
    else if (k === 'dataset') Object.assign(node.dataset, v);
    else node.setAttribute(k, v);
  }
}

function appendAll(node, children) {
  for (const c of [].concat(children)) {
    if (c == null) continue;
    node.append(typeof c === 'string' ? document.createTextNode(c) : c);
  }
}

/** Replace all children of a node. */
export function clear(node) {
  while (node.firstChild) node.removeChild(node.firstChild);
  return node;
}

/**
 * Read a JSON data island.
 *
 * This is the seam to the data layer. In the prototype the island is written
 * into the HTML by hand from data/*.json; in WordPress the exact same shape
 * arrives via wp_add_inline_script / wp_localize_script. Nothing downstream
 * of this function knows the difference.
 *
 * @param {string} id  element id of a <script type="application/json">
 * @returns {any|null}
 */
export function readDataIsland(id) {
  const node = document.getElementById(id);
  if (!node) return null;

  // An empty island is a normal state, not a failure: the prototype ships the
  // element unpopulated and falls back to fetching the fixture. Only malformed
  // content is worth warning about.
  const raw = node.textContent.trim();
  if (!raw) return null;

  try {
    return JSON.parse(raw);
  } catch (err) {
    console.error(`[thb] data island #${id} is not valid JSON`, err);
    return null;
  }
}
