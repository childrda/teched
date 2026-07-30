import { meetsLengthRequirement, remainingCharacters } from './response-field';

/**
 * Alpine component for a short_response block.
 *
 * @param {{
 *   blockId: string,
 *   pageId: string,
 *   minLength: number|null,
 *   strings: Record<string, string>,
 * }} config
 */
export function shortResponseActivity(config = {}) {
  return {
    blockId: config.blockId ?? '',
    pageId: config.pageId ?? '',
    minLength: config.minLength ?? null,
    strings: config.strings ?? {},
    value: '',
    readOnly: false,
    disposeContributor: null,

    init() {
      const player = this.player();

      this.readOnly = player?.readOnly === true;
      this.restoreState(player?.stateFor?.(this.blockId));
    },

    destroy() {
      this.disposeContributor?.();
    },

    player() {
      const root = this.$el?.closest?.('[data-lesson-code]');

      return root && window.Alpine ? window.Alpine.$data(root) : null;
    },

    captureDisposer(dispose) {
      this.disposeContributor = typeof dispose === 'function' ? dispose : null;
    },

    contributor() {
      return {
        id: `${this.blockId}:response`,
        category: 'response',
        isSatisfied: () => this.isSatisfied,
        message: this.strings.gate ?? '',
      };
    },

    serializeState() {
      return { value: this.value };
    },

    restoreState(state) {
      if (! state || typeof state !== 'object' || typeof state.value !== 'string') {
        return;
      }

      this.value = state.value;
    },

    onInput() {
      if (this.readOnly) {
        return;
      }

      this.player()?.queueSave?.(this.blockId, this.serializeState());
    },

    get isSatisfied() {
      return meetsLengthRequirement(this.value, this.minLength);
    },

    get hint() {
      if (this.minLength === null || this.minLength === undefined) {
        return this.isSatisfied ? '' : (this.strings.characters_needed ?? '');
      }

      const remaining = remainingCharacters(this.value, this.minLength);

      if (remaining === null) {
        return '';
      }

      if (remaining > 0) {
        return fill(this.strings.characters_remaining, { count: remaining });
      }

      if (remaining < 0) {
        return fill(this.strings.characters_over, { count: Math.abs(remaining) });
      }

      return this.strings.characters_met ?? '';
    },
  };
}

function fill(template, values) {
  return Object.entries(values).reduce(
    (text, [key, value]) => text.replaceAll(`:${key}`, String(value)),
    template ?? '',
  );
}
