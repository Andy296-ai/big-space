import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MapFields from './MapFields.vue';

/**
 * Regression guard: these fields must stay `type="text"`. Vue's compiler
 * auto-coerces `v-model` on `type="number"`/`type="range"` inputs to a JS
 * number even without the `.number` modifier, which silently breaks
 * `defineModel<string>()` and crashes any `.trim()` call downstream (see
 * AddNodeModal's `toCoord`). This bit us for real in manual testing.
 */
describe('MapFields', () => {
    function mountFields() {
        return mount(MapFields, {
            props: { lat: '', lon: '', mapTitle: '' },
        });
    }

    it('renders latitude/longitude as text inputs, not number inputs', () => {
        const wrapper = mountFields();
        const inputs = wrapper.findAll('input');

        for (const input of inputs) {
            expect(input.attributes('type')).not.toBe('number');
        }
    });

    it('keeps the typed latitude as a string on the emitted model update', async () => {
        const wrapper = mountFields();

        await wrapper.find('input[aria-label="Latitude"]').setValue('38.5598');

        const emitted = wrapper.emitted('update:lat');

        expect(emitted).toBeTruthy();
        expect(typeof emitted![0][0]).toBe('string');
        expect(emitted![0][0]).toBe('38.5598');
    });

    it('keeps a negative longitude as a string', async () => {
        const wrapper = mountFields();

        await wrapper.find('input[aria-label="Longitude"]').setValue('-68.78');

        const emitted = wrapper.emitted('update:lon');

        expect(typeof emitted![0][0]).toBe('string');
        expect(emitted![0][0]).toBe('-68.78');
    });
});
