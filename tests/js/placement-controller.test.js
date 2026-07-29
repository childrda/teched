import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { placementActivity } from '../../resources/js/lesson-player/placement-controller.js';

/**
 * Stand-ins for lang/en/placement.php. Blade passes the real translations in;
 * tests/Feature/PlacementLocalizationTest.php is what proves every key the
 * controller reads exists there.
 */
const STRINGS = {
    picked_up: ':label picked up',
    placed_at: ':label placed at :slot',
    moved_to: ':label moved to :slot',
    placed_over: ':label placed at :slot, :displaced returned to the bank',
    returned: ':label returned to the bank',
    cancelled: 'Selection cancelled',
    select_first: 'Select an item before choosing a slot',
    complete: 'All :count items placed',
    reset: 'Activity reset',
    gate: 'Place every item to continue.',
    slot_empty: ':slot: :description Empty.',
    slot_filled: ':slot: :description :label placed.',
};

const ITEMS = [
    { id: 'i1', label: 'Arc', name: 'Arc', speechId: 'i1:label' },
    { id: 'i2', label: 'Filler', name: 'Filler', speechId: 'i2:label' },
    { id: 'i3', label: 'Electrode', name: 'Electrode', speechId: 'i3:label' },
];

const SLOTS = [
    { id: 's1', name: 'Row 1', description: 'Makes the heat.' },
    { id: 's2', name: 'Row 2', description: 'Adds metal.' },
];

let focused;

/**
 * The controller without Alpine: the two affordances it borrows from Alpine
 * are stubbed, and nothing else about it needs a framework or a browser.
 */
function activity(overrides = {}) {
    focused = [];

    const component = placementActivity({
        blockId: 'BLOCK-1',
        pageId: 'PAGE-1',
        completionType: 'complete_activity',
        shuffle: false,
        items: ITEMS,
        slots: SLOTS,
        strings: STRINGS,
        ...overrides,
    });

    component.$nextTick = (callback) => callback();
    component.$root = {
        querySelector: (selector) => ({
            focus: () => focused.push(selector),
        }),
    };

    component.init();

    return component;
}

function place(component, itemId, slotId, layer = 'rows') {
    component.toggleItem(itemId);
    component.activateSlot(slotId, layer);
}

beforeEach(() => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('what the page gate is told', () => {
    it('describes itself as one gradable contributor for the whole activity', () => {
        const contributor = activity().contributor();

        expect(contributor.id).toBe('BLOCK-1:placement');
        expect(contributor.category).toBe('gradable');
        expect(contributor.message).toBe(STRINGS.gate);
    });

    it('is unsatisfied while any slot is empty', () => {
        const component = activity();
        const contributor = component.contributor();

        expect(contributor.isSatisfied()).toBe(false);

        place(component, 'i1', 's1');

        expect(contributor.isSatisfied()).toBe(false);
    });

    it('is satisfied once every slot is filled', () => {
        const component = activity();
        const contributor = component.contributor();

        place(component, 'i1', 's1');
        place(component, 'i2', 's2');

        expect(contributor.isSatisfied()).toBe(true);
    });

    it('is unsatisfied again when an item comes back', () => {
        const component = activity();
        const contributor = component.contributor();

        place(component, 'i1', 's1');
        place(component, 'i2', 's2');
        component.activateSlot('s2', 'rows');

        expect(contributor.isSatisfied()).toBe(false);
    });

    it('never claims to be passed: nothing here can grade', () => {
        const component = activity();
        const contributor = component.contributor();

        expect(contributor.isPassed()).toBe(false);

        place(component, 'i1', 's1');
        place(component, 'i2', 's2');

        expect(contributor.isSatisfied()).toBe(true);
        expect(contributor.isPassed()).toBe(false);
    });

    it('warns when the page asks for a pass it can never give', () => {
        activity({ completionType: 'pass_activity' });

        expect(console.warn).toHaveBeenCalledOnce();
        expect(console.warn.mock.calls[0][0]).toContain('BLOCK-1');
        expect(console.warn.mock.calls[0][0]).toContain('PAGE-1');
    });

    it('says nothing on a page whose rule it can satisfy', () => {
        activity({ completionType: 'complete_activity' });

        expect(console.warn).not.toHaveBeenCalled();
    });

    it('drops its contributor when the component goes away', () => {
        const component = activity();
        const dispose = vi.fn();

        component.captureDisposer(dispose);
        component.destroy();

        expect(dispose).toHaveBeenCalledOnce();
    });
});

describe('what a student is told', () => {
    it('reports a pick-up by name', () => {
        const component = activity();

        component.toggleItem('i1');

        expect(component.announcement).toBe('Arc picked up');
    });

    it('reports a placement with the slot it went to', () => {
        const component = activity();

        place(component, 'i1', 's1');

        expect(component.announcement).toBe('Arc placed at Row 1');
    });

    it('reports a move as a move', () => {
        const component = activity();

        place(component, 'i1', 's1');
        place(component, 'i1', 's2');

        expect(component.announcement).toBe('Arc moved to Row 2');
    });

    it('reports which item was displaced and where it went', () => {
        const component = activity();

        place(component, 'i1', 's1');
        place(component, 'i2', 's1');

        expect(component.announcement).toBe(
            'Filler placed at Row 1, Arc returned to the bank',
        );
    });

    it('reports an item sent back to the bank', () => {
        const component = activity();

        place(component, 'i1', 's1');
        component.activateSlot('s1', 'rows');

        expect(component.announcement).toBe('Arc returned to the bank');
    });

    it('asks for an item when a slot is chosen with nothing held', () => {
        const component = activity();

        component.activateSlot('s1', 'rows');

        expect(component.announcement).toBe(STRINGS.select_first);
    });

    it('reports a cancelled selection', () => {
        const component = activity();

        component.toggleItem('i1');
        component.cancel();

        expect(component.announcement).toBe(STRINGS.cancelled);
    });

    it('reports a second tap on a held item as a cancellation', () => {
        const component = activity();

        component.toggleItem('i1');
        component.toggleItem('i1');

        expect(component.announcement).toBe(STRINGS.cancelled);
    });

    it('reports a reset', () => {
        const component = activity();

        place(component, 'i1', 's1');
        component.resetActivity();

        expect(component.announcement).toBe(STRINGS.reset);
        expect(component.state.bank).toEqual(['i1', 'i2', 'i3']);
    });

    it('announces completion with the move that finishes the activity', () => {
        const component = activity();

        place(component, 'i1', 's1');
        place(component, 'i2', 's2');

        expect(component.announcement).toBe('Filler placed at Row 2 All 2 items placed');
    });

    it('does not announce completion again on later changes', () => {
        const component = activity();

        place(component, 'i1', 's1');
        place(component, 'i2', 's2');

        // Still complete afterwards: the spare item displaces one that was
        // already placed, so both slots stay filled.
        place(component, 'i3', 's1');

        expect(component.announcement).toBe(
            'Electrode placed at Row 1, Arc returned to the bank',
        );
    });

    it('announces completion again after the activity has been broken and remade', () => {
        const component = activity();

        place(component, 'i1', 's1');
        place(component, 'i2', 's2');
        component.activateSlot('s2', 'rows');
        place(component, 'i2', 's2');

        expect(component.announcement).toContain('All 2 items placed');
    });
});

describe('where focus goes', () => {
    it('moves to the next empty slot in the layer being used', () => {
        const component = activity();

        place(component, 'i1', 's1', 'rows');

        expect(focused).toEqual(['[data-placement-layer="rows"] [data-slot-id="s2"]']);
    });

    it('stays in the layer the student is working in', () => {
        const component = activity();

        place(component, 'i1', 's1', 'list');

        expect(focused).toEqual(['[data-placement-layer="list"] [data-slot-id="s2"]']);
    });

    it('stays on the slot just filled when nothing is left to fill', () => {
        const component = activity();

        place(component, 'i1', 's1');
        focused = [];
        place(component, 'i2', 's2');

        expect(focused).toEqual(['[data-placement-layer="rows"] [data-slot-id="s2"]']);
    });

    it('returns to the item that was picked up when a selection is cancelled', () => {
        const component = activity();

        component.toggleItem('i2');
        component.cancel();

        expect(focused).toEqual(['[data-item-id="i2"]']);
    });

    it('is left alone when Escape arrives with nothing held', () => {
        const component = activity();

        component.cancel();

        expect(focused).toEqual([]);
        expect(component.announcement).toBe('');
    });
});

describe('what each layer renders', () => {
    it('names a slot by its number, its description, and what is in it', () => {
        const component = activity();

        expect(component.slotName('s1')).toBe('Row 1: Makes the heat. Empty.');

        place(component, 'i1', 's1');

        expect(component.slotName('s1')).toBe('Row 1: Makes the heat. Arc placed.');
    });

    it('shows the placed label in the slot and drops it from the bank', () => {
        const component = activity();

        place(component, 'i1', 's1');

        expect(component.filledLabel('s1')).toBe('Arc');
        expect(component.isFilled('s1')).toBe(true);
        expect(component.bankItems.map((item) => item.id)).toEqual(['i2', 'i3']);
    });

    it('keeps the bank in manifest order when the block does not shuffle', () => {
        expect(activity().bankItems.map((item) => item.id)).toEqual(['i1', 'i2', 'i3']);
    });

    it('shuffles without inventing or losing an item', () => {
        const ids = activity({ shuffle: true }).bankItems.map((item) => item.id);

        expect([...ids].sort()).toEqual(['i1', 'i2', 'i3']);
    });
});

describe('dragging with a mouse', () => {
    function transfer() {
        const data = {};

        return {
            setData: (type, value) => {
                data[type] = value;
            },
            getData: (type) => data[type] ?? '',
        };
    }

    it('places through the same operations a click uses', () => {
        const component = activity();
        const dataTransfer = transfer();

        component.dragItem('i1', { dataTransfer });
        component.dropOnSlot('s1', 'rows', { dataTransfer });

        expect(component.state.placements.s1).toBe('i1');
        expect(component.announcement).toBe('Arc placed at Row 1');
    });

    it('holds the dragged item rather than toggling whatever was held', () => {
        const component = activity();
        const dataTransfer = transfer();

        component.toggleItem('i1');
        component.dragItem('i1', { dataTransfer });

        expect(component.state.selectedItemId).toBe('i1');
    });

    it('ignores a drag that began on an empty slot', () => {
        const component = activity();
        const dataTransfer = transfer();

        component.dragItem(null, { dataTransfer });

        expect(dataTransfer.getData('text/plain')).toBe('');
        expect(component.state.selectedItemId).toBeNull();
    });

    it('cancels an aborted drag instead of leaving the item held', () => {
        const component = activity();

        component.dragItem('i1', { dataTransfer: transfer() });
        component.dragEnded();

        expect(component.state.selectedItemId).toBeNull();
        expect(component.announcement).toBe(STRINGS.cancelled);

        // A drag was never a tap, so there is no item to hand focus back to.
        expect(focused).toEqual([]);
    });

    it('does nothing on the dragend that follows a successful drop', () => {
        const component = activity();
        const dataTransfer = transfer();

        component.dragItem('i1', { dataTransfer });
        component.dropOnSlot('s1', 'rows', { dataTransfer });
        component.dragEnded();

        expect(component.state.placements.s1).toBe('i1');
        expect(component.announcement).toBe('Arc placed at Row 1');
    });
});

describe('when the diagram does not load', () => {
    it('hides the marker layer and leaves a note for staff', () => {
        const component = activity();

        component.imageFailed('/lessons/missing.png');

        expect(component.imageAvailable).toBe(false);
        expect(console.warn).toHaveBeenCalledOnce();
    });
});
