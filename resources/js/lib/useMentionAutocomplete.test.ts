import { describe, expect, it } from 'vitest';
import { ref } from 'vue';
import { useMentionAutocomplete } from './useMentionAutocomplete';
import type { MentionCandidate } from './useMentionAutocomplete';

const candidates = ref<MentionCandidate[]>([
    { id: 1, name: 'Anna' },
    { id: 2, name: 'Ann' },
    { id: 3, name: 'Bob' },
]);

describe('useMentionAutocomplete', () => {
    it('detects an @ trigger at the cursor and lists matching candidates', () => {
        const mention = useMentionAutocomplete(candidates);

        mention.handleInput('hi @an', 6);

        expect(mention.query.value).toBe('an');
        expect(mention.matches.value.map((c) => c.name)).toEqual([
            'Anna',
            'Ann',
        ]);
    });

    it('closes when the text no longer has an open @ query', () => {
        const mention = useMentionAutocomplete(candidates);

        mention.handleInput('hi @an', 6);
        mention.handleInput('hi @an ', 7);

        expect(mention.query.value).toBeNull();
        expect(mention.matches.value).toEqual([]);
    });

    it('replaces the query with a mention token at the cursor position it was typed at', () => {
        const mention = useMentionAutocomplete(candidates);

        mention.handleInput('hi @bo', 6);
        const result = mention.applyMention('hi @bo', {
            id: 3,
            name: 'Bob',
        });

        expect(result.text).toBe('hi [[user:3]] ');
        expect(result.cursor).toBe(result.text.length);
    });

    it(
        'still removes the full query it tracked even if the caret moved away ' +
            'from it without a further input event (e.g. arrow keys) before the ' +
            'suggestion was clicked',
        () => {
            const mention = useMentionAutocomplete(candidates);

            // User typed "@bo" (cursor lands right after "o", at index 6).
            mention.handleInput('hi @bo', 6);
            // Then pressed Left twice — the caret is now between "@" and "b",
            // but no `input` event fires for pure caret movement, so
            // query.value/triggerIndex are still exactly what they were.
            // applyMention must not depend on where the caret ended up.
            const result = mention.applyMention('hi @bo', {
                id: 3,
                name: 'Bob',
            });

            expect(result.text).toBe('hi [[user:3]] ');
        },
    );

    it('leaves the text untouched when no trigger is currently open', () => {
        const mention = useMentionAutocomplete(candidates);

        const result = mention.applyMention('hi there', { id: 3, name: 'Bob' });

        expect(result.text).toBe('hi there');
    });
});
