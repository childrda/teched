import { describe, expect, it } from 'vitest';

import { meetsLengthRequirement, remainingCharacters } from '../../resources/js/lesson-player/response-field.js';
import { shortResponseActivity } from '../../resources/js/lesson-player/short-response.js';
import { cerActivity } from '../../resources/js/lesson-player/cer.js';

describe('meetsLengthRequirement', () => {
    it('requires a non-empty trimmed value when min_length is null', () => {
        expect(meetsLengthRequirement('', null)).toBe(false);
        expect(meetsLengthRequirement('   ', null)).toBe(false);
        expect(meetsLengthRequirement('ok', null)).toBe(true);
    });

    it('is unsatisfied below the minimum, satisfied at and above it', () => {
        expect(meetsLengthRequirement('abcd', 5)).toBe(false);
        expect(meetsLengthRequirement('abcde', 5)).toBe(true);
        expect(meetsLengthRequirement('abcdef', 5)).toBe(true);
        expect(meetsLengthRequirement('  abcd  ', 5)).toBe(false);
        expect(meetsLengthRequirement('  abcde  ', 5)).toBe(true);
    });
});

describe('shortResponseActivity', () => {
    it('exposes a response contributor whose satisfaction follows the length rule', () => {
        const component = shortResponseActivity({
            blockId: 'B1',
            pageId: 'P1',
            minLength: 5,
            strings: { gate: 'Write more.' },
        });

        const contributor = component.contributor();

        expect(contributor.category).toBe('response');
        expect(contributor.isSatisfied()).toBe(false);

        component.value = '12345';

        expect(contributor.isSatisfied()).toBe(true);
    });

    it('requires non-empty text when minLength is null', () => {
        const component = shortResponseActivity({
            blockId: 'B1',
            pageId: 'P1',
            minLength: null,
            strings: {},
        });

        expect(component.isSatisfied).toBe(false);

        component.value = 'x';

        expect(component.isSatisfied).toBe(true);
    });
});

describe('cerActivity', () => {
    it('is satisfied only when every field meets its own requirement', () => {
        const component = cerActivity({
            blockId: 'B1',
            pageId: 'P1',
            fields: [
                { id: 'claim', minLength: 3 },
                { id: 'evidence', minLength: null },
            ],
            strings: { gate: 'Finish CER.' },
        });

        expect(component.contributor().isSatisfied()).toBe(false);

        component.values.claim = 'yes';
        expect(component.contributor().isSatisfied()).toBe(false);

        component.values.evidence = 'because';
        expect(component.contributor().isSatisfied()).toBe(true);
    });
});

describe('remainingCharacters', () => {
    it('counts down to the minimum and goes negative past it', () => {
        expect(remainingCharacters('ab', 5)).toBe(3);
        expect(remainingCharacters('abcde', 5)).toBe(0);
        expect(remainingCharacters('abcdef', 5)).toBe(-1);
        expect(remainingCharacters('x', null)).toBeNull();
    });
});
