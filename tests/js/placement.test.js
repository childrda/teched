import { describe, expect, it } from 'vitest';

import { createPlacement } from '../../resources/js/lesson-player/placement.js';

const ITEMS = ['item-a', 'item-b', 'item-c'];
const SLOTS = ['slot-1', 'slot-2', 'slot-3'];

function setup(itemIds = ITEMS, slotIds = SLOTS) {
    const state = { bank: [], placements: {}, selectedItemId: null };

    return { state, placement: createPlacement(state, { itemIds, slotIds }) };
}

/** How many places an item occupies; anything but 0 or 1 is a bug. */
function occurrences(state, itemId) {
    return (
        state.bank.filter((id) => id === itemId).length +
        Object.values(state.placements).filter((id) => id === itemId).length
    );
}

function fillEverySlot(state, placement) {
    SLOTS.forEach((slotId, index) => {
        placement.select(ITEMS[index]);
        placement.place(slotId);
    });
}

describe('starting state', () => {
    it('begins with every item in the bank, in the order given', () => {
        const { state } = setup();

        expect(state.bank).toEqual(ITEMS);
    });

    it('begins with every slot empty and nothing held', () => {
        const { state } = setup();

        expect(state.placements).toEqual({ 'slot-1': null, 'slot-2': null, 'slot-3': null });
        expect(state.selectedItemId).toBeNull();
    });

    it('does not begin complete', () => {
        const { placement } = setup();

        expect(placement.isComplete()).toBe(false);
    });
});

describe('picking an item up', () => {
    it('holds the item that was selected', () => {
        const { state, placement } = setup();

        expect(placement.select('item-b')).toBe('item-b');
        expect(state.selectedItemId).toBe('item-b');
    });

    it('puts the item down when it is selected a second time', () => {
        const { state, placement } = setup();

        placement.select('item-b');

        expect(placement.select('item-b')).toBeNull();
        expect(state.selectedItemId).toBeNull();
    });

    it('swaps the held item when another is selected', () => {
        const { state, placement } = setup();

        placement.select('item-b');
        placement.select('item-c');

        expect(state.selectedItemId).toBe('item-c');
    });

    it('leaves the selection alone when the item is not part of this activity', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.select('item-from-another-block');

        expect(state.selectedItemId).toBe('item-a');
    });

    it('does not copy a placed item back into the bank when it is selected', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.place('slot-1');
        placement.select('item-a');

        expect(state.bank).not.toContain('item-a');
        expect(occurrences(state, 'item-a')).toBe(1);
    });
});

describe('placing an item', () => {
    it('puts the held item into the requested slot', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.place('slot-2');

        expect(state.placements['slot-2']).toBe('item-a');
    });

    it('takes the item out of the bank', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.place('slot-2');

        expect(state.bank).toEqual(['item-b', 'item-c']);
    });

    it('leaves the student holding nothing', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.place('slot-2');

        expect(state.selectedItemId).toBeNull();
    });

    it('does nothing when no item is held', () => {
        const { state, placement } = setup();

        expect(placement.place('slot-1')).toBeNull();
        expect(state.placements['slot-1']).toBeNull();
        expect(state.bank).toEqual(ITEMS);
    });

    it('does nothing when the slot belongs to another activity', () => {
        const { state, placement } = setup();

        placement.select('item-a');

        expect(placement.place('slot-from-another-block')).toBeNull();
        expect(state.selectedItemId).toBe('item-a');
        expect(state.bank).toEqual(ITEMS);
    });
});

describe('moving an item that is already placed', () => {
    it('moves it out of the slot it was in', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.place('slot-1');
        placement.select('item-a');
        const result = placement.place('slot-3');

        expect(state.placements['slot-1']).toBeNull();
        expect(state.placements['slot-3']).toBe('item-a');
        expect(result.movedFromSlotId).toBe('slot-1');
    });

    it('leaves the item in exactly one place', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.place('slot-1');
        placement.select('item-a');
        placement.place('slot-3');

        expect(occurrences(state, 'item-a')).toBe(1);
    });

    it('treats dropping an item back onto its own slot as no displacement', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.place('slot-1');
        placement.select('item-a');
        const result = placement.place('slot-1');

        expect(result).toMatchObject({ displacedItemId: null, movedFromSlotId: null });
        expect(state.placements['slot-1']).toBe('item-a');
        expect(occurrences(state, 'item-a')).toBe(1);
    });
});

describe('displacing an item', () => {
    it('sends the item that was there back to the bank, once', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.place('slot-1');
        placement.select('item-b');
        const result = placement.place('slot-1');

        expect(result.displacedItemId).toBe('item-a');
        expect(state.bank.filter((id) => id === 'item-a')).toHaveLength(1);
        expect(occurrences(state, 'item-a')).toBe(1);
    });

    it('gives the slot to the newly placed item', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.place('slot-1');
        placement.select('item-b');
        placement.place('slot-1');

        expect(state.placements['slot-1']).toBe('item-b');
    });

    it('returns the displaced item to its old position in the bank', () => {
        const { state, placement } = setup();

        placement.select('item-b');
        placement.place('slot-1');
        placement.select('item-c');
        placement.place('slot-1');

        expect(state.bank).toEqual(['item-a', 'item-b']);
    });
});

describe('returning an item to the bank', () => {
    it('empties the slot and hands the item back once', () => {
        const { state, placement } = setup();

        placement.select('item-a');
        placement.place('slot-1');

        expect(placement.returnToBank('slot-1')).toBe('item-a');
        expect(state.placements['slot-1']).toBeNull();
        expect(occurrences(state, 'item-a')).toBe(1);
    });

    it('does nothing for an empty slot and does not throw', () => {
        const { state, placement } = setup();

        expect(() => placement.returnToBank('slot-1')).not.toThrow();
        expect(placement.returnToBank('slot-1')).toBeNull();
        expect(state.bank).toEqual(ITEMS);
    });

    it('does nothing for a slot that belongs to another activity', () => {
        const { placement } = setup();

        expect(placement.returnToBank('slot-from-another-block')).toBeNull();
    });
});

describe('resetting', () => {
    it('puts every item back, empties every slot, and drops the selection', () => {
        const { state, placement } = setup();

        fillEverySlot(state, placement);
        placement.select('item-b');
        placement.reset();

        expect(state.bank).toEqual(ITEMS);
        expect(state.placements).toEqual({ 'slot-1': null, 'slot-2': null, 'slot-3': null });
        expect(state.selectedItemId).toBeNull();
    });

    it('leaves no item in two places', () => {
        const { state, placement } = setup();

        fillEverySlot(state, placement);
        placement.reset();

        ITEMS.forEach((itemId) => expect(occurrences(state, itemId)).toBe(1));
    });
});

describe('knowing when the activity is finished', () => {
    it('is unfinished while any slot is empty', () => {
        const { placement } = setup();

        placement.select('item-a');
        placement.place('slot-1');
        placement.select('item-b');
        placement.place('slot-2');

        expect(placement.isComplete()).toBe(false);
    });

    it('is finished once every slot holds something', () => {
        const { state, placement } = setup();

        fillEverySlot(state, placement);

        expect(placement.isComplete()).toBe(true);
    });

    it('is unfinished again as soon as an item comes back', () => {
        const { state, placement } = setup();

        fillEverySlot(state, placement);
        placement.returnToBank('slot-2');

        expect(placement.isComplete()).toBe(false);
    });

    it('counts slots rather than items, so spare items in the bank do not block it', () => {
        const state = { bank: [], placements: {}, selectedItemId: null };
        const placement = createPlacement(state, {
            itemIds: ['a', 'b', 'c', 'd'],
            slotIds: ['only-slot'],
        });

        placement.select('c');
        placement.place('only-slot');

        expect(placement.isComplete()).toBe(true);
        expect(state.bank).toEqual(['a', 'b', 'd']);
    });
});

describe('where to send a student next', () => {
    it('offers the next empty slot after the one just filled', () => {
        const { placement } = setup();

        placement.select('item-a');
        placement.place('slot-1');

        expect(placement.nextEmptySlot('slot-1')).toBe('slot-2');
    });

    it('wraps past the end, because students do not fill slots in order', () => {
        const { placement } = setup();

        placement.select('item-a');
        placement.place('slot-3');

        expect(placement.nextEmptySlot('slot-3')).toBe('slot-1');
    });

    it('skips slots that are already filled', () => {
        const { placement } = setup();

        placement.select('item-a');
        placement.place('slot-2');
        placement.select('item-b');
        placement.place('slot-1');

        expect(placement.nextEmptySlot('slot-1')).toBe('slot-3');
    });

    it('offers nothing once every slot is filled', () => {
        const { state, placement } = setup();

        fillEverySlot(state, placement);

        expect(placement.nextEmptySlot('slot-1')).toBeNull();
    });
});
