/**
 * Viewport / container edge auto-scroll while a native HTML5 drag is in progress.
 *
 * dragover carries pointer coordinates during a drag; mousemove does not.
 * Scrolling is driven by requestAnimationFrame so rate stays steady no matter
 * how chatty the browser is with dragover events.
 *
 * Scrolls the nearest scrollable ancestor under the pointer (bank, page, …),
 * not only the window. Edge zones are measured against that element's client
 * rect, with sticky chrome insets applied for the document scroller.
 *
 * Generic on purpose: no knowledge of placement state. Touch never fires these
 * events — tap-to-place remains the touch path.
 */

const EDGE_ZONE_PX = 120;
const MIN_VELOCITY_PX = 4;
const MAX_VELOCITY_PX = 20;
/** Constant gentler speed when prefers-reduced-motion is set. */
const REDUCED_VELOCITY_PX = 6;

/**
 * @param {{
 *   edgeZone?: number,
 *   minVelocity?: number,
 *   maxVelocity?: number,
 *   reducedVelocity?: number,
 *   measureInsets?: () => { top: number, bottom: number },
 *   scrollElement?: (el: Element, x: number, y: number) => void,
 *   getOverflowY?: (el: Element) => string,
 *   getScrollMetrics?: (el: Element) => { scrollTop: number, clientHeight: number, scrollHeight: number },
 *   getClientRect?: (el: Element) => { top: number, bottom: number, height: number },
 *   matchMedia?: (query: string) => { matches: boolean },
 *   document?: Document,
 *   window?: Window & typeof globalThis,
 * }} [options]
 */
export function createDragAutoScroll(options = {}) {
  const edgeZone = options.edgeZone ?? EDGE_ZONE_PX;
  const minVelocity = options.minVelocity ?? MIN_VELOCITY_PX;
  const maxVelocity = options.maxVelocity ?? MAX_VELOCITY_PX;
  const reducedVelocity = options.reducedVelocity ?? REDUCED_VELOCITY_PX;
  const doc = options.document ?? globalThis.document;
  const win = options.window ?? globalThis;
  const matchMedia =
    options.matchMedia ?? ((query) => win.matchMedia?.(query) ?? { matches: false });

  const measureInsets =
    options.measureInsets ??
    (() => defaultMeasureInsets(doc));

  const getOverflowY =
    options.getOverflowY ??
    ((el) => {
      if (typeof win.getComputedStyle !== 'function') {
        return '';
      }

      return win.getComputedStyle(el).overflowY;
    });

  const getScrollMetrics =
    options.getScrollMetrics ??
    ((el) => ({
      scrollTop: el.scrollTop ?? 0,
      clientHeight: el.clientHeight ?? 0,
      scrollHeight: el.scrollHeight ?? 0,
    }));

  const getClientRect =
    options.getClientRect ??
    ((el) => {
      if (typeof el.getBoundingClientRect === 'function') {
        const rect = el.getBoundingClientRect();

        return { top: rect.top, bottom: rect.bottom, height: rect.height };
      }

      return { top: 0, bottom: win.innerHeight || 0, height: win.innerHeight || 0 };
    });

  const scrollElement =
    options.scrollElement ??
    ((el, x, y) => {
      if (isDocumentScroller(el, doc)) {
        win.scrollBy?.(x, y);

        return;
      }

      if (typeof el.scrollBy === 'function') {
        el.scrollBy(x, y);

        return;
      }

      el.scrollTop = (el.scrollTop ?? 0) + y;
    });

  let dragging = false;
  let velocityY = 0;
  let scrollTarget = null;
  let insets = { top: 0, bottom: 0 };
  let rafId = null;

  function prefersReducedMotion() {
    return matchMedia('(prefers-reduced-motion: reduce)').matches === true;
  }

  function documentScroller() {
    return doc.scrollingElement || doc.documentElement || doc.body;
  }

  function isDocumentScroller(el, documentRef = doc) {
    if (!el) {
      return false;
    }

    return (
      el === documentRef.scrollingElement ||
      el === documentRef.documentElement ||
      el === documentRef.body
    );
  }

  function usableBounds(el) {
    if (isDocumentScroller(el)) {
      const vh = win.innerHeight || 0;

      return {
        top: insets.top,
        bottom: vh - insets.bottom,
      };
    }

    const rect = getClientRect(el);

    return { top: rect.top, bottom: rect.bottom };
  }

  /**
   * @returns {-1 | 0 | 1} up, none, or down
   */
  function edgeIntent(el, clientY) {
    const { top, bottom } = usableBounds(el);

    if (bottom <= top) {
      return 0;
    }

    if (clientY >= top && clientY < top + edgeZone) {
      return -1;
    }

    if (clientY <= bottom && clientY > bottom - edgeZone) {
      return 1;
    }

    return 0;
  }

  function velocityMagnitude(el, clientY, intent) {
    const { top, bottom } = usableBounds(el);

    if (prefersReducedMotion()) {
      return reducedVelocity;
    }

    let t = 0;

    if (intent < 0) {
      t = 1 - Math.max(0, clientY - top) / edgeZone;
    } else {
      t = 1 - Math.max(0, bottom - clientY) / edgeZone;
    }

    t = Math.min(1, Math.max(0, t));

    return minVelocity + t * (maxVelocity - minVelocity);
  }

  function canOverflowScroll(el) {
    if (!el || isDocumentScroller(el)) {
      return true;
    }

    const overflowY = getOverflowY(el);

    if (overflowY !== 'auto' && overflowY !== 'scroll') {
      return false;
    }

    const metrics = getScrollMetrics(el);

    return metrics.scrollHeight > metrics.clientHeight + 1;
  }

  function hasRoom(el, intent) {
    const metrics = getScrollMetrics(el);

    if (intent < 0) {
      return metrics.scrollTop > 0;
    }

    return metrics.scrollTop + metrics.clientHeight < metrics.scrollHeight - 1;
  }

  /**
   * Walk from the element under the pointer. Prefer a nested scroller that is
   * both in an edge zone and has room; skip ones at their limit so the parent
   * (often the document) can take over.
   *
   * @returns {{ el: Element, velocity: number } | null}
   */
  function resolveScrollTarget(startEl, clientY) {
    let el = startEl;

    while (el && el !== doc) {
      if (el.nodeType === 1 && canOverflowScroll(el) && !isDocumentScroller(el)) {
        const intent = edgeIntent(el, clientY);

        if (intent !== 0 && hasRoom(el, intent)) {
          const mag = velocityMagnitude(el, clientY, intent);

          return { el, velocity: intent * mag };
        }
      }

      el = el.parentElement;
    }

    const root = documentScroller();

    if (!root) {
      return null;
    }

    const intent = edgeIntent(root, clientY);

    if (intent === 0 || !hasRoom(root, intent)) {
      return null;
    }

    const mag = velocityMagnitude(root, clientY, intent);

    return { el: root, velocity: intent * mag };
  }

  function stopLoop() {
    if (rafId !== null) {
      win.cancelAnimationFrame?.(rafId);
      rafId = null;
    }
  }

  function tick() {
    rafId = null;

    if (!dragging || velocityY === 0 || !scrollTarget) {
      return;
    }

    scrollElement(scrollTarget, 0, velocityY);
    rafId = win.requestAnimationFrame?.(tick) ?? null;
  }

  function ensureLoop() {
    if (!dragging || velocityY === 0 || rafId !== null) {
      return;
    }

    rafId = win.requestAnimationFrame?.(tick) ?? null;
  }

  function updateFromPointer(clientY, eventTarget) {
    if (!dragging) {
      return;
    }

    const resolved = resolveScrollTarget(eventTarget ?? documentScroller(), clientY);

    if (resolved === null) {
      velocityY = 0;
      scrollTarget = null;
      stopLoop();

      return;
    }

    scrollTarget = resolved.el;
    velocityY = resolved.velocity;
    ensureLoop();
  }

  function stop() {
    dragging = false;
    velocityY = 0;
    scrollTarget = null;
    stopLoop();
  }

  function onDragStart() {
    dragging = true;
    velocityY = 0;
    scrollTarget = null;
    insets = measureInsets() ?? { top: 0, bottom: 0 };
    insets = {
      top: Math.max(0, Number(insets.top) || 0),
      bottom: Math.max(0, Number(insets.bottom) || 0),
    };
  }

  function onDragOver(event) {
    if (!dragging) {
      return;
    }

    updateFromPointer(event.clientY, event.target);
  }

  function onDragEnter(event) {
    if (!dragging) {
      return;
    }

    updateFromPointer(event.clientY, event.target);
  }

  /**
   * Pointer left the window during a drag. dragleave fires often when crossing
   * element boundaries; only stop when coordinates are outside the viewport.
   */
  function onDragLeave(event) {
    if (!dragging) {
      return;
    }

    const vh = win.innerHeight || 0;
    const vw = win.innerWidth || 0;
    const { clientX, clientY } = event;

    if (clientY <= 0 || clientX <= 0 || clientY >= vh || clientX >= vw) {
      stop();
    }
  }

  function onKeyDown(event) {
    if (event.key === 'Escape') {
      stop();
    }
  }

  function attach() {
    if (!doc?.addEventListener) {
      return () => {};
    }

    doc.addEventListener('dragstart', onDragStart, true);
    doc.addEventListener('dragover', onDragOver, true);
    doc.addEventListener('dragenter', onDragEnter, true);
    doc.addEventListener('drop', stop, true);
    doc.addEventListener('dragend', stop, true);
    doc.addEventListener('dragleave', onDragLeave, true);
    doc.addEventListener('keydown', onKeyDown, true);

    return function detach() {
      doc.removeEventListener('dragstart', onDragStart, true);
      doc.removeEventListener('dragover', onDragOver, true);
      doc.removeEventListener('dragenter', onDragEnter, true);
      doc.removeEventListener('drop', stop, true);
      doc.removeEventListener('dragend', stop, true);
      doc.removeEventListener('dragleave', onDragLeave, true);
      doc.removeEventListener('keydown', onKeyDown, true);
      stop();
    };
  }

  return {
    attach,
    stop,
    isScrolling: () => rafId !== null,
    velocity: () => velocityY,
    scrollTarget: () => scrollTarget,
    /** @internal test helpers */
    _updateFromPointer: updateFromPointer,
    _onDragStart: onDragStart,
    _insets: () => insets,
  };
}

function defaultMeasureInsets(doc) {
  if (!doc?.querySelectorAll) {
    return { top: 0, bottom: 0 };
  }

  return {
    top: sumInsetHeights(doc.querySelectorAll('[data-drag-scroll-inset-top]')),
    bottom: sumInsetHeights(doc.querySelectorAll('[data-drag-scroll-inset-bottom]')),
  };
}

function sumInsetHeights(nodeList) {
  let total = 0;

  for (const el of nodeList) {
    if (typeof el.getBoundingClientRect === 'function') {
      total += el.getBoundingClientRect().height;
    } else if (typeof el.offsetHeight === 'number') {
      total += el.offsetHeight;
    }
  }

  return total;
}

/** Install once for the lesson player page. */
export function installDragAutoScroll() {
  return createDragAutoScroll().attach();
}
