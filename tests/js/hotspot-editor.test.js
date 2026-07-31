import { describe, expect, it, vi } from 'vitest';

import {
  clampPct,
  hotspotEditor,
  nudgePercent,
  pointToPercent,
  roundPct,
} from '../../resources/js/authoring/hotspot-editor.js';

function makeEditor(hotspots = [], bank = [{ id: 'bank-1', label: 'Label' }]) {
  const editor = hotspotEditor({
    imageUrl: 'https://example.com/diagram.png',
    hotspots,
    bank,
    newId: () => `id-${Math.random().toString(16).slice(2, 10)}`,
  });

  editor.$dispatch = vi.fn();
  editor.$nextTick = (fn) => fn();
  editor.$refs = {};
  editor.$watch = vi.fn();
  editor.init();

  return editor;
}

describe('hotspot coordinate math', () => {
  it('clamps and rounds percentages', () => {
    expect(clampPct(-5)).toBe(0);
    expect(clampPct(105)).toBe(100);
    expect(roundPct(12.345)).toBe(12.35);
    expect(roundPct(12.344)).toBe(12.34);
  });

  it('maps clicks against the image content box at two widths', () => {
    const wide = { left: 100, top: 50, width: 800, height: 400 };
    const narrow = { left: 20, top: 10, width: 400, height: 200 };

    expect(pointToPercent({ clientX: 500, clientY: 250 }, wide)).toEqual({
      x_pct: 50,
      y_pct: 50,
    });

    expect(pointToPercent({ clientX: 220, clientY: 110 }, narrow)).toEqual({
      x_pct: 50,
      y_pct: 50,
    });

    // Different rendered width, same relative point → same percentages.
    const atQuarterWide = pointToPercent({ clientX: 300, clientY: 150 }, wide);
    const atQuarterNarrow = pointToPercent({ clientX: 120, clientY: 60 }, narrow);
    expect(atQuarterWide).toEqual({ x_pct: 25, y_pct: 25 });
    expect(atQuarterNarrow).toEqual({ x_pct: 25, y_pct: 25 });
  });

  it('rejects clicks outside the image rect (letterbox)', () => {
    const rect = { left: 100, top: 50, width: 200, height: 100 };

    expect(pointToPercent({ clientX: 50, clientY: 80 }, rect)).toBeNull();
    expect(pointToPercent({ clientX: 150, clientY: 20 }, rect)).toBeNull();
    expect(pointToPercent({ clientX: 350, clientY: 80 }, rect)).toBeNull();
  });

  it('nudges by 0.5 and 2 with clamping', () => {
    expect(nudgePercent(10, 0.5)).toBe(10.5);
    expect(nudgePercent(10, 2)).toBe(12);
    expect(nudgePercent(0, -0.5)).toBe(0);
    expect(nudgePercent(99, 2)).toBe(100);
  });
});

describe('hotspot selection and placement', () => {
  it('selects a newly added hotspot', () => {
    const editor = makeEditor([
      { id: 'h1', number: 1, x_pct: 10, y_pct: 10, answer_id: 'bank-1' },
    ]);

    expect(editor.selectedIndex).toBe(0);
    editor.addHotspot();
    expect(editor.selectedIndex).toBe(1);
    expect(editor.hotspots).toHaveLength(2);
  });

  it('moves only the selected hotspot when the image is clicked', () => {
    const editor = makeEditor([
      { id: 'h1', number: 1, x_pct: 10, y_pct: 10, answer_id: 'bank-1' },
      { id: 'h2', number: 2, x_pct: 20, y_pct: 20, answer_id: 'bank-1' },
    ]);
    editor.selectedIndex = 1;
    editor.$refs.image = {
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 100, height: 100 }),
    };

    editor.onImageClick({ clientX: 75, clientY: 25 });

    expect(editor.hotspots[0].x_pct).toBe(10);
    expect(editor.hotspots[0].y_pct).toBe(10);
    expect(editor.hotspots[1].x_pct).toBe(75);
    expect(editor.hotspots[1].y_pct).toBe(25);
  });

  it('gives the next click to a second added hotspot rather than the first', () => {
    const editor = makeEditor([]);
    editor.$refs.image = {
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 100, height: 100 }),
    };

    editor.addHotspot();
    editor.onImageClick({ clientX: 30, clientY: 40 });
    expect(editor.hotspots[0].x_pct).toBe(30);
    expect(editor.hotspots[0].y_pct).toBe(40);

    editor.addHotspot();
    expect(editor.selectedIndex).toBe(1);
    editor.onImageClick({ clientX: 80, clientY: 90 });

    expect(editor.hotspots[0].x_pct).toBe(30);
    expect(editor.hotspots[0].y_pct).toBe(40);
    expect(editor.hotspots[1].x_pct).toBe(80);
    expect(editor.hotspots[1].y_pct).toBe(90);
  });

  it('clamps selectedIndex when the selected hotspot is removed', () => {
    const editor = makeEditor([
      { id: 'h1', number: 1, x_pct: 10, y_pct: 10, answer_id: 'bank-1' },
      { id: 'h2', number: 2, x_pct: 20, y_pct: 20, answer_id: 'bank-1' },
      { id: 'h3', number: 3, x_pct: 30, y_pct: 30, answer_id: 'bank-1' },
    ]);
    editor.selectedIndex = 2;
    editor.removeSelectedHotspot();

    expect(editor.hotspots).toHaveLength(2);
    expect(editor.selectedIndex).toBe(1);

    editor.removeSelectedHotspot();
    editor.removeSelectedHotspot();
    expect(editor.hotspots).toHaveLength(0);
    expect(editor.selectedIndex).toBe(-1);
  });

  it('clamps selectedIndex when the array shrinks underneath it', () => {
    const editor = makeEditor([
      { id: 'h1', number: 1, x_pct: 10, y_pct: 10, answer_id: 'bank-1' },
      { id: 'h2', number: 2, x_pct: 20, y_pct: 20, answer_id: 'bank-1' },
    ]);
    editor.selectedIndex = 1;
    editor.hotspots.pop();
    editor.clampSelectedIndex();

    expect(editor.selectedIndex).toBe(0);
  });

  it('top and bottom add controls share addHotspot and select the new marker', () => {
    const editor = makeEditor([]);
    // Both Blade buttons ultimately invoke this one function (top: direct call,
    // bottom: window teched-add-hotspot → same addHotspot).
    const sharedAdd = editor.addHotspot.bind(editor);

    sharedAdd();
    expect(editor.selectedIndex).toBe(0);
    expect(editor.hotspots).toHaveLength(1);

    sharedAdd();
    expect(editor.selectedIndex).toBe(1);
    expect(editor.hotspots).toHaveLength(2);
    expect(editor.hotspots[0].x_pct).toBe(50);
    expect(editor.hotspots[1].x_pct).toBe(50);
  });

  it('keeps coordinates as percentages including image edges 0 and 100', () => {
    expect(clampPct(0)).toBe(0);
    expect(clampPct(100)).toBe(100);

    const editor = makeEditor([
      { id: 'h1', number: 1, x_pct: 50, y_pct: 50, answer_id: 'bank-1' },
    ]);
    editor.$refs.image = {
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 200, height: 100 }),
    };

    editor.onImageClick({ clientX: 0, clientY: 0 });
    expect(editor.hotspots[0].x_pct).toBe(0);
    expect(editor.hotspots[0].y_pct).toBe(0);

    editor.onImageClick({ clientX: 200, clientY: 100 });
    expect(editor.hotspots[0].x_pct).toBe(100);
    expect(editor.hotspots[0].y_pct).toBe(100);

    // Never stored as CSS pixels.
    expect(editor.hotspots[0].x_pct).toBeLessThanOrEqual(100);
    expect(String(editor.hotspots[0].x_pct)).not.toMatch(/px/);
  });
});
