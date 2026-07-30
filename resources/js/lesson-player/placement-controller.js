import { createPlacement } from './placement';
import { seededShuffle } from './seeded-shuffle';

/**
 * The Alpine side of a placement activity, shared by the matching and
 * image-labeling renderers. One controller, configured differently, rather
 * than the same interaction code written twice.
 *
 * It owns three things the state machine deliberately does not: the labels a
 * student sees, the live-region wording (translated in Blade and passed in),
 * and the small amount of DOM work that focus management needs. It owns no
 * answer key and decides nothing about correctness.
 *
 * Bank shuffle stays here (not in PHP): Alpine draws the bank so the shuffled
 * order is the DOM order, which keeps tab order and reading order matching
 * what a student sees. Seeded via attempt.shuffle_seed + block_id.
 *
 * @param {{
 *   blockId: string,
 *   pageId: string,
 *   completionType: string,
 *   shuffle: boolean,
 *   items: {id: string, label: string, name: string}[],
 *   slots: {id: string, name: string, description: string}[],
 *   strings: Record<string, string>,
 * }} config
 */
export function placementActivity(config = {}) {
  const definition = {
    items: config.items ?? [],
    slots: config.slots ?? [],
  };

  const itemsById = Object.fromEntries(definition.items.map((item) => [item.id, item]));
  const slotsById = Object.fromEntries(definition.slots.map((slot) => [slot.id, slot]));

  // Kept in the closure rather than on the component: the operations hold no
  // state of their own, and a reactive framework has no reason to proxy them.
  let placement = null;

  return {
    blockId: config.blockId ?? '',
    pageId: config.pageId ?? '',
    strings: config.strings ?? {},
    readOnly: false,

    /** The state the machine mutates and every binding reads. */
    state: {
      bank: [],
      placements: {},
      selectedItemId: null,
    },

    announcement: '',

    /** False once the diagram fails to load; the numbered list carries on. */
    imageAvailable: true,

    /**
     * Which bank item started the current selection, so Escape can hand
     * focus back. An id, never an element: state stays free of DOM nodes.
     */
    pickedUpFrom: null,

    /** Completion is announced on the transition, not on every change. */
    wasComplete: false,

    disposeContributor: null,

    init() {
      const player = this.player();
      this.readOnly = player?.readOnly === true;

      const itemIds = definition.items.map((item) => item.id);
      const orderedIds =
        config.shuffle === true
          ? seededShuffle(itemIds, player?.shuffleSeed?.() ?? '', this.blockId)
          : itemIds;

      placement = createPlacement(this.state, {
        itemIds: orderedIds,
        slotIds: definition.slots.map((slot) => slot.id),
      });

      this.restoreState(player?.stateFor?.(this.blockId));
      this.wasComplete = placement.isComplete();

      if (config.completionType === 'pass_activity') {
        console.warn(
          `Placement block ${this.blockId} sits on page ${this.pageId}, whose completion rule ` +
            'is pass_activity. Placement activities cannot be graded until Phase 2C, so ' +
            'isPassed() returns false and Continue will never enable on this page.',
        );
      }
    },

    player() {
      const root = this.$el?.closest?.('[data-lesson-code]');

      return root && window.Alpine ? window.Alpine.$data(root) : null;
    },

    serializeState() {
      return { placements: { ...this.state.placements } };
    },

    restoreState(state) {
      if (! state || typeof state !== 'object' || typeof state.placements !== 'object' || ! state.placements) {
        return;
      }

      try {
        for (const slot of definition.slots) {
          const itemId = state.placements[slot.id];

          if (itemId == null) {
            continue;
          }

          if (typeof itemId !== 'string' || ! itemsById[itemId]) {
            continue;
          }

          placement.select(itemId);
          placement.place(slot.id);
        }
      } catch {
        // Corrupt saved state → leave the activity empty.
        placement.reset();
      }
    },

    persist() {
      if (this.readOnly) {
        return;
      }

      this.player()?.queueSave?.(this.blockId, this.serializeState());
    },

    destroy() {
      this.disposeContributor?.();
    },

    /**
     * Stores the registry's remove handle. Blade registers the contributor
     * from x-init so that the player's own method hands the handle over;
     * returning it from here instead would have Alpine invoke it.
     */
    captureDisposer(dispose) {
      this.disposeContributor = typeof dispose === 'function' ? dispose : null;
    },

    /**
     * One contributor for the whole activity, registered once. The hotspot
     * layer and the list layer are two views of the same slots, not two
     * activities, and a page gate cares about the activity.
     */
    contributor() {
      return {
        id: `${this.blockId}:placement`,
        category: 'gradable',
        isSatisfied: () => this.isComplete,
        // TODO Phase 2C: replace with the server-graded result.
        isPassed: () => false,
        message: this.strings.gate ?? '',
      };
    },

    get bankItems() {
      return this.state.bank.map((itemId) => itemsById[itemId]).filter(Boolean);
    },

    get isComplete() {
      return placement?.isComplete() === true;
    },

    get holding() {
      return this.state.selectedItemId !== null;
    },

    isHolding(itemId) {
      return this.state.selectedItemId === itemId;
    },

    labelFor(itemId) {
      return itemsById[itemId]?.label ?? '';
    },

    /** The name announcements use, which disambiguates repeated labels. */
    nameFor(itemId) {
      return itemsById[itemId]?.name ?? this.labelFor(itemId);
    },

    itemIn(slotId) {
      return placement?.itemIn(slotId) ?? null;
    },

    isFilled(slotId) {
      return this.itemIn(slotId) !== null;
    },

    /** What a filled slot shows: the label, or '' while it is empty. */
    filledLabel(slotId) {
      const itemId = this.itemIn(slotId);

      return itemId === null ? '' : this.labelFor(itemId);
    },

    /**
     * A slot button's accessible name, identical in every layer: which slot
     * it is, what it asks for, and what is in it.
     */
    slotName(slotId) {
      const slot = slotsById[slotId] ?? {};
      const itemId = this.itemIn(slotId);

      return fill(itemId === null ? this.strings.slot_empty : this.strings.slot_filled, {
        slot: slot.name ?? '',
        description: slot.description ?? '',
        label: itemId === null ? '' : this.nameFor(itemId),
      });
    },

    /** Picks a bank item up, or puts it down when it was already held. */
    toggleItem(itemId) {
      if (this.readOnly) {
        return;
      }

      if (placement.select(itemId) === null) {
        this.pickedUpFrom = null;
        this.announce(this.strings.cancelled);

        return;
      }

      this.pickedUpFrom = itemId;
      this.announce(fill(this.strings.picked_up, { label: this.nameFor(itemId) }));
    },

    /**
     * What a slot does when it is activated, in any layer and by pointer,
     * touch, or keyboard: take what is held, or give back what it holds.
     */
    activateSlot(slotId, layer) {
      if (this.readOnly) {
        return;
      }

      if (this.holding) {
        this.placeInto(slotId, layer);

        return;
      }

      const returned = placement.returnToBank(slotId);

      if (returned === null) {
        this.announce(this.strings.select_first);

        return;
      }

      this.persist();
      this.report([fill(this.strings.returned, { label: this.nameFor(returned) })]);
    },

    placeInto(slotId, layer) {
      const result = placement.place(slotId);

      if (result === null) {
        this.announce(this.strings.select_first);

        return;
      }

      this.pickedUpFrom = null;

      const values = { label: this.nameFor(result.itemId), slot: slotsById[slotId]?.name ?? '' };

      let message = fill(this.strings.placed_at, values);

      if (result.displacedItemId !== null) {
        message = fill(this.strings.placed_over, {
          ...values,
          displaced: this.nameFor(result.displacedItemId),
        });
      } else if (result.movedFromSlotId !== null) {
        message = fill(this.strings.moved_to, values);
      }

      this.persist();
      this.report([message]);
      this.focusNextEmptySlot(slotId, layer);
    },

    /** Escape, or a second tap on the held item. */
    cancel() {
      if (!this.holding || this.readOnly) {
        return;
      }

      const returnFocusTo = this.pickedUpFrom;

      placement.clearSelection();
      this.pickedUpFrom = null;
      this.announce(this.strings.cancelled);

      if (returnFocusTo !== null) {
        this.focusItem(returnFocusTo);
      }
    },

    resetActivity() {
      if (this.readOnly) {
        return;
      }

      placement.reset();

      this.pickedUpFrom = null;
      this.wasComplete = false;
      this.persist();

      this.announce(this.strings.reset);
    },

    /**
     * Native drag is a mouse convenience layered on the same operations: it
     * holds the item and places it, exactly as a click would. There is no
     * drag-only state to fall out of step, and touch never comes through
     * here — iOS does not fire these events at all, which is why tapping has
     * to be, and is, a complete path on its own.
     */
    dragItem(itemId, event) {
      this.hold(itemId);

      event.dataTransfer?.setData('text/plain', itemId);

      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
      }
    },

    dropOnSlot(slotId, layer, event) {
      const itemId = event.dataTransfer?.getData('text/plain');

      if (itemId) {
        this.hold(itemId);
      }

      if (this.holding) {
        this.placeInto(slotId, layer);
      }
    },

    /**
     * dragend fires after every drag, dropped or not. After a successful
     * drop nothing is held and this is a no-op; after an aborted drag it
     * cancels the selection so the item is not silently left picked up.
     * Focus is not moved: pickedUpFrom is only set by tapping, so a
     * mouse-initiated drag cancels without yanking focus anywhere.
     */
    dragEnded() {
      this.cancel();
    },

    /** Selection without the toggle, for a drag that has already begun. */
    hold(itemId) {
      if (!this.isHolding(itemId)) {
        placement.select(itemId);
      }
    },

    /**
     * Focus advances to the next slot needing an item, within the layer the
     * student is already working in. Being dropped from the diagram into the
     * list below, or the other way, loses their place.
     */
    focusNextEmptySlot(slotId, layer) {
      const next = placement.nextEmptySlot(slotId);

      this.focusSlot(next ?? slotId, layer);
    },

    focusSlot(slotId, layer) {
      this.$nextTick(() => {
        this.$root
          .querySelector(`[data-placement-layer=${quote(layer)}] [data-slot-id=${quote(slotId)}]`)
          ?.focus();
      });
    },

    /** Silent when the item is no longer in the bank: there is nothing to focus. */
    focusItem(itemId) {
      this.$nextTick(() => {
        this.$root.querySelector(`[data-item-id=${quote(itemId)}]`)?.focus();
      });
    },

    /** A missing image hides the markers; the numbered list still works. */
    imageFailed(url) {
      this.imageAvailable = false;

      console.warn('Lesson player could not load a labeling image.', {
        block_id: this.blockId,
        image_url: url,
      });
    },

    /**
     * Announces a change, adding the completion message only on the move
     * that finishes the activity. Repeating it on every later change would
     * bury whatever the student just did.
     */
    report(messages) {
      const complete = this.isComplete;

      if (complete && !this.wasComplete) {
        messages.push(fill(this.strings.complete, { count: definition.slots.length }));
      }

      this.wasComplete = complete;

      this.announce(messages.filter(Boolean).join(' '));
    },

    /** Clearing first guarantees the live region sees a change to report. */
    announce(message) {
      if (!message) {
        return;
      }

      this.announcement = '';

      this.$nextTick(() => {
        this.announcement = message;
      });
    },
  };
}

/** Fills a translated template: fill(':label placed at :slot', {...}). */
function fill(template, values) {
  return Object.entries(values).reduce(
    (text, [key, value]) => text.replaceAll(`:${key}`, value),
    template ?? '',
  );
}

/** Ids come from author content, so they are quoted, not trusted. */
function quote(value) {
  return `"${String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}
