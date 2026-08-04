import { describe, expect, it } from 'vitest';
import { fill } from './i18n';

describe('fill', () => {
    it('replaces a single placeholder', () => {
        expect(fill('Hello {name}', { name: 'World' })).toBe('Hello World');
    });

    it('replaces multiple distinct placeholders', () => {
        expect(fill('{a} and {b}', { a: 'foo', b: 'bar' })).toBe('foo and bar');
    });

    it('replaces every occurrence of a repeated placeholder', () => {
        expect(fill('{n} + {n} = double {n}', { n: 2 })).toBe(
            '2 + 2 = double 2',
        );
    });

    it('leaves unmatched placeholders untouched', () => {
        expect(fill('Hello {name}', {})).toBe('Hello {name}');
    });

    it('returns the template unchanged when there is nothing to fill', () => {
        expect(fill('No placeholders here', { unused: 'value' })).toBe(
            'No placeholders here',
        );
    });
});
