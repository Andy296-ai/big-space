import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PositionFields from './PositionFields.vue';

/** Same regression as MapFields.test.ts — see the comment there. */
describe('PositionFields', () => {
    function mountFields() {
        return mount(PositionFields, {
            props: { posX: '', posY: '' },
        });
    }

    it('renders X/Y as text inputs, not number inputs', () => {
        const wrapper = mountFields();
        const inputs = wrapper.findAll('input');

        expect(inputs).toHaveLength(2);

        for (const input of inputs) {
            expect(input.attributes('type')).not.toBe('number');
        }
    });

    it('keeps a typed coordinate as a string, including negative values', async () => {
        const wrapper = mountFields();

        await wrapper.find('input[aria-label="X"]').setValue('123');
        await wrapper.find('input[aria-label="Y"]').setValue('-456');

        const x = wrapper.emitted('update:posX');
        const y = wrapper.emitted('update:posY');

        expect(typeof x![0][0]).toBe('string');
        expect(x![0][0]).toBe('123');
        expect(typeof y![0][0]).toBe('string');
        expect(y![0][0]).toBe('-456');
    });
});
