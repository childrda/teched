import { describe, expect, it } from 'vitest';

import {
  clampPct,
  nudgePercent,
  pointToPercent,
  roundPct,
} from '../../resources/js/authoring/hotspot-editor.js';

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
