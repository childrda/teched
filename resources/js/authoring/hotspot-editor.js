/**
 * Coordinate math for the image-labeling hotspot authoring editor.
 * Pure functions — unit-tested against supplied rects (no layout/jsdom).
 */

export function clampPct(value) {
  const n = Number(value);
  if (! Number.isFinite(n)) {
    return 0;
  }

  return Math.min(100, Math.max(0, n));
}

export function roundPct(value) {
  return Math.round(clampPct(value) * 100) / 100;
}

/**
 * Map a pointer event on an <img> to 0–100 percentages of its content box.
 * Returns null when the click falls outside the image rect (letterbox reject).
 *
 * @param {{ clientX: number, clientY: number }} point
 * @param {DOMRect} rect from img.getBoundingClientRect()
 * @returns {{ x_pct: number, y_pct: number } | null}
 */
export function pointToPercent(point, rect) {
  if (! rect || rect.width <= 0 || rect.height <= 0) {
    return null;
  }

  const x = point.clientX - rect.left;
  const y = point.clientY - rect.top;

  if (x < 0 || y < 0 || x > rect.width || y > rect.height) {
    return null;
  }

  return {
    x_pct: roundPct((x / rect.width) * 100),
    y_pct: roundPct((y / rect.height) * 100),
  };
}

export function nudgePercent(value, delta) {
  return roundPct(clampPct(Number(value) + delta));
}

/**
 * Alpine component factory for the Filament hotspot map field.
 */
export function hotspotEditor(config = {}) {
  const step = 0.5;
  const shiftStep = 2;

  return {
    imageUrl: config.imageUrl ?? '',
    imageFailed: false,
    hotspots: Array.isArray(config.hotspots) ? structuredClone(config.hotspots) : [],
    bank: Array.isArray(config.bank) ? config.bank : [],
    selectedIndex: config.hotspots?.length ? 0 : -1,
    announcement: '',
    _nudgeTimer: null,
    _dragging: false,

    init() {
      this.$watch('hotspots', () => this.emit(), { deep: true });
    },

    emit() {
      this.$dispatch('hotspots-changed', { hotspots: this.hotspots });
    },

    select(index) {
      this.selectedIndex = index;
      this.announceSelected();
    },

    addHotspot() {
      const nextNumber = this.hotspots.reduce(
        (max, h) => Math.max(max, Number(h.number) || 0),
        0,
      ) + 1;

      this.hotspots.push({
        id: config.newId?.() ?? crypto.randomUUID().replace(/-/g, '').slice(0, 26),
        number: nextNumber,
        x_pct: 50,
        y_pct: 50,
        answer_id: this.bank[0]?.id ?? null,
        description: null,
      });
      this.selectedIndex = this.hotspots.length - 1;
      this.$nextTick(() => {
        this.$refs[`marker-${this.selectedIndex}`]?.focus?.();
        this.announceSelected();
      });
    },

    onImageError() {
      this.imageFailed = true;
    },

    onImageClick(event) {
      const img = this.$refs.image;
      if (! img || this.imageFailed) {
        return;
      }

      const mapped = pointToPercent(
        { clientX: event.clientX, clientY: event.clientY },
        img.getBoundingClientRect(),
      );

      if (mapped === null) {
        return;
      }

      if (this.selectedIndex < 0) {
        this.addHotspot();
      }

      const hotspot = this.hotspots[this.selectedIndex];
      if (! hotspot) {
        return;
      }

      hotspot.x_pct = mapped.x_pct;
      hotspot.y_pct = mapped.y_pct;
      this.announceSelected();
    },

    startDrag(index, event) {
      event.preventDefault();
      this.selectedIndex = index;
      this._dragging = true;

      const move = (ev) => {
        if (! this._dragging) {
          return;
        }
        const img = this.$refs.image;
        if (! img) {
          return;
        }
        const mapped = pointToPercent(
          { clientX: ev.clientX, clientY: ev.clientY },
          img.getBoundingClientRect(),
        );
        if (mapped === null) {
          return;
        }
        const hotspot = this.hotspots[this.selectedIndex];
        if (! hotspot) {
          return;
        }
        hotspot.x_pct = mapped.x_pct;
        hotspot.y_pct = mapped.y_pct;
      };

      const up = () => {
        this._dragging = false;
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
        this.announceSelected();
      };

      window.addEventListener('pointermove', move);
      window.addEventListener('pointerup', up);
    },

    onMarkerKeydown(index, event) {
      this.selectedIndex = index;
      const hotspot = this.hotspots[index];
      if (! hotspot) {
        return;
      }

      const delta = event.shiftKey ? shiftStep : step;
      let handled = true;

      switch (event.key) {
        case 'ArrowLeft':
          hotspot.x_pct = nudgePercent(hotspot.x_pct, -delta);
          break;
        case 'ArrowRight':
          hotspot.x_pct = nudgePercent(hotspot.x_pct, delta);
          break;
        case 'ArrowUp':
          hotspot.y_pct = nudgePercent(hotspot.y_pct, -delta);
          break;
        case 'ArrowDown':
          hotspot.y_pct = nudgePercent(hotspot.y_pct, delta);
          break;
        default:
          handled = false;
      }

      if (! handled) {
        return;
      }

      event.preventDefault();
      clearTimeout(this._nudgeTimer);
      this._nudgeTimer = setTimeout(() => this.announceSelected(), 300);
    },

    announceSelected() {
      const hotspot = this.hotspots[this.selectedIndex];
      if (! hotspot) {
        this.announcement = '';

        return;
      }

      this.announcement = `Hotspot ${hotspot.number} at ${Number(hotspot.x_pct).toFixed(2)} percent X, ${Number(hotspot.y_pct).toFixed(2)} percent Y`;
    },

    bankLabel(id) {
      return this.bank.find((item) => item.id === id)?.label ?? id ?? '—';
    },
  };
}
