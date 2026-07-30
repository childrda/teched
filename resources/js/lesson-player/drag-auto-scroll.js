/**
 * Viewport edge auto-scroll while a native HTML5 drag is in progress.
 *
 * dragover carries pointer coordinates during a drag; mousemove does not.
 * Scrolling is driven by requestAnimationFrame so rate stays steady no matter
 * how chatty the browser is with dragover events.
 *
 * Generic on purpose: no knowledge of placement state. Touch never fires these
 * events — tap-to-place remains the touch path.
 */

const EDGE_ZONE_PX = 90;
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
 *   scrollBy?: (x: number, y: number) => void,
 *   matchMedia?: (query: string) => { matches: boolean },
 *   document?: Document,
 *   window?: Window & typeof globalThis,
 * }} [options]
 * @returns {{ attach: () => () => void, isScrolling: () => boolean, velocity: () => number }}
 */
export function createDragAutoScroll(options = {}) {
  const edgeZone = options.edgeZone ?? EDGE_ZONE_PX;
  const minVelocity = options.minVelocity ?? MIN_VELOCITY_PX;
  const maxVelocity = options.maxVelocity ?? MAX_VELOCITY_PX;
  const reducedVelocity = options.reducedVelocity ?? REDUCED_VELOCITY_PX;
  const doc = options.document ?? globalThis.document;
  const win = options.window ?? globalThis;
  const scrollBy =
    options.scrollBy ??
    ((x, y) => {
      win.scrollBy?.(x, y);
    });
  const matchMedia =
    options.matchMedia ?? ((query) => win.matchMedia?.(query) ?? { matches: false });

  let dragging = false;
  let velocityY = 0;
  let rafId = null;

  function prefersReducedMotion() {
    return matchMedia('(prefers-reduced-motion: reduce)').matches === true;
  }

  function velocityForClientY(clientY) {
    const vh = win.innerHeight || 0;

    if (vh <= 0) {
      return 0;
    }

    if (clientY < edgeZone) {
      if (prefersReducedMotion()) {
        return -reducedVelocity;
      }

      const t = 1 - Math.max(0, clientY) / edgeZone;

      return -(minVelocity + t * (maxVelocity - minVelocity));
    }

    if (clientY > vh - edgeZone) {
      if (prefersReducedMotion()) {
        return reducedVelocity;
      }

      const t = 1 - Math.max(0, vh - clientY) / edgeZone;

      return minVelocity + t * (maxVelocity - minVelocity);
    }

    return 0;
  }

  function stopLoop() {
    if (rafId !== null) {
      win.cancelAnimationFrame?.(rafId);
      rafId = null;
    }
  }

  function tick() {
    rafId = null;

    if (!dragging || velocityY === 0) {
      return;
    }

    scrollBy(0, velocityY);
    rafId = win.requestAnimationFrame?.(tick) ?? null;
  }

  function ensureLoop() {
    if (!dragging || velocityY === 0 || rafId !== null) {
      return;
    }

    rafId = win.requestAnimationFrame?.(tick) ?? null;
  }

  function updateFromPointer(clientY) {
    if (!dragging) {
      return;
    }

    const next = velocityForClientY(clientY);
    velocityY = next;

    if (next === 0) {
      stopLoop();

      return;
    }

    ensureLoop();
  }

  function stop() {
    dragging = false;
    velocityY = 0;
    stopLoop();
  }

  function onDragStart() {
    dragging = true;
    velocityY = 0;
  }

  function onDragOver(event) {
    if (!dragging) {
      return;
    }

    updateFromPointer(event.clientY);
  }

  function onDragEnter(event) {
    if (!dragging) {
      return;
    }

    updateFromPointer(event.clientY);
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
    /** @internal test helper */
    _updateFromPointer: updateFromPointer,
    _onDragStart: onDragStart,
  };
}

/** Install once for the lesson player page. */
export function installDragAutoScroll() {
  return createDragAutoScroll().attach();
}
