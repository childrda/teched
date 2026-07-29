import { meetsLengthRequirement, remainingCharacters } from './response-field';

/**
 * Alpine component for a CER (claim–evidence–reasoning) block.
 *
 * @param {{
 *   blockId: string,
 *   pageId: string,
 *   fields: {id: string, minLength: number|null}[],
 *   strings: Record<string, string>,
 * }} config
 */
export function cerActivity(config = {}) {
  const fields = (config.fields ?? []).map((field) => ({
    id: field.id,
    minLength: field.minLength ?? null,
  }));

  return {
    blockId: config.blockId ?? '',
    pageId: config.pageId ?? '',
    strings: config.strings ?? {},
    values: Object.fromEntries(fields.map((field) => [field.id, ''])),
    disposeContributor: null,

    destroy() {
      this.disposeContributor?.();
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

    get isSatisfied() {
      return fields.every((field) =>
        meetsLengthRequirement(this.values[field.id], field.minLength),
      );
    },

    hintFor(fieldId) {
      const field = fields.find((candidate) => candidate.id === fieldId);

      if (! field) {
        return '';
      }

      const value = this.values[fieldId] ?? '';

      if (field.minLength === null || field.minLength === undefined) {
        return meetsLengthRequirement(value, null)
          ? ''
          : (this.strings.characters_needed ?? '');
      }

      const remaining = remainingCharacters(value, field.minLength);

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
