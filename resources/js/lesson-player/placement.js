/**
 * The placement model behind every activity where a student moves items into
 * slots: matching rows, image hotspots, and the numbered list that mirrors
 * those hotspots.
 *
 * It knows nothing about the DOM, about Alpine, or about correct answers.
 * Grading happens on the server in Phase 2C; here "complete" means only that
 * every slot holds something.
 *
 * State is handed in rather than owned, the same arrangement speech.js uses:
 * a reactive framework passes its own object, re-renders from the mutations,
 * and this module never learns that reactivity exists.
 *
 * @param {{ bank: string[], placements: Record<string, string|null>, selectedItemId: string|null }} state
 * @param {{ itemIds?: string[], slotIds?: string[] }} definition
 */
export function createPlacement(state, { itemIds = [], slotIds = [] } = {}) {
  // The order items are given in is the order the bank shows them in, and an
  // item returned from a slot drops back into its old position rather than
  // onto the end. A student's mental map of the bank survives their mistakes.
  const items = [...itemIds];
  const slots = [...slotIds];

  /**
   * The bank is derived from the slots on every change rather than pushed and
   * spliced. Nothing can appear twice, nothing can be lost, and no sequence
   * of moves can leave an item in a slot and in the bank at once.
   */
  function syncBank() {
    const placed = new Set(
      slots.map((slotId) => state.placements[slotId]).filter((itemId) => itemId != null),
    );

    state.bank = items.filter((itemId) => !placed.has(itemId));
  }

  function itemIn(slotId) {
    return state.placements[slotId] ?? null;
  }

  function slotOf(itemId) {
    return slots.find((slotId) => state.placements[slotId] === itemId) ?? null;
  }

  function reset() {
    state.placements = Object.fromEntries(slots.map((slotId) => [slotId, null]));
    state.selectedItemId = null;

    syncBank();
  }

  /**
   * Picks an item up, or puts it down when it was already held. Returns the
   * selection after the call, so a caller can tell a pick-up from a cancel
   * without reading state.
   *
   * An item sitting in a slot may be selected: that is how a student moves a
   * placement. Selecting never touches the bank.
   */
  function select(itemId) {
    if (!items.includes(itemId)) {
      return state.selectedItemId;
    }

    state.selectedItemId = state.selectedItemId === itemId ? null : itemId;

    return state.selectedItemId;
  }

  function clearSelection() {
    state.selectedItemId = null;
  }

  /**
   * Puts the held item into a slot, and returns what happened so the caller
   * can announce it: the item placed, the slot it came from when this was a
   * move, and the item it evicted, which is back in the bank.
   *
   * Returns null when nothing was held or the slot is not one of ours, which
   * is how a caller knows to tell the student to pick something up first.
   */
  function place(slotId) {
    const itemId = state.selectedItemId;

    if (itemId === null || !slots.includes(slotId)) {
      return null;
    }

    const displacedItemId = itemIn(slotId);
    const movedFromSlotId = slotOf(itemId);

    if (movedFromSlotId !== null) {
      state.placements[movedFromSlotId] = null;
    }

    state.placements[slotId] = itemId;
    state.selectedItemId = null;

    syncBank();

    return {
      itemId,
      slotId,
      // Both are null when the move changed nothing: dropping an item back
      // onto the slot it already occupied is not a displacement.
      displacedItemId: displacedItemId === itemId ? null : displacedItemId,
      movedFromSlotId: movedFromSlotId === slotId ? null : movedFromSlotId,
    };
  }

  /** Returns the item that went back to the bank, or null for an empty slot. */
  function returnToBank(slotId) {
    const itemId = itemIn(slotId);

    if (itemId === null) {
      return null;
    }

    state.placements[slotId] = null;

    syncBank();

    return itemId;
  }

  /**
   * True once every slot holds something. An activity with no slots counts as
   * complete: a student should never be trapped behind an empty activity.
   */
  function isComplete() {
    return slots.every((slotId) => itemIn(slotId) !== null);
  }

  /**
   * The slot to send a student to after a placement: the next empty one,
   * wrapping past the end, or null when the activity is finished. Wrapping
   * matters because students do not work top to bottom.
   */
  function nextEmptySlot(fromSlotId) {
    if (slots.length === 0) {
      return null;
    }

    const from = slots.indexOf(fromSlotId);

    for (let step = 1; step <= slots.length; step += 1) {
      const slotId = slots[(from + step) % slots.length];

      if (itemIn(slotId) === null) {
        return slotId;
      }
    }

    return null;
  }

  reset();

  return {
    select,
    clearSelection,
    place,
    returnToBank,
    reset,
    isComplete,
    nextEmptySlot,
    itemIn,
    slotOf,
    isSelected: (itemId) => state.selectedItemId === itemId,
    isHeld: () => state.selectedItemId !== null,
    itemIds: () => [...items],
    slotIds: () => [...slots],
  };
}
