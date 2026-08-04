import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    applyTheme,
    DEFAULT_SETTINGS,
    loadSettings,
    NODE_SCALE_MAX,
    NODE_SCALE_MIN,
    saveSettings,
} from './settings';

const STORAGE_KEY = 'infinite-space-settings';

describe('loadSettings', () => {
    beforeEach(() => localStorage.clear());

    it('falls back to defaults when nothing is stored', () => {
        expect(loadSettings()).toEqual(DEFAULT_SETTINGS);
    });

    it('falls back to defaults when the stored value is not valid JSON', () => {
        localStorage.setItem(STORAGE_KEY, '{not json');

        expect(loadSettings()).toEqual(DEFAULT_SETTINGS);
    });

    it('keeps valid stored values and fills in anything missing', () => {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ theme: 'midnight', showGrid: false }),
        );

        const settings = loadSettings();

        expect(settings.theme).toBe('midnight');
        expect(settings.showGrid).toBe(false);
        // Untouched fields still come from the defaults.
        expect(settings.lang).toBe(DEFAULT_SETTINGS.lang);
    });

    it('discards an unknown enum value rather than trusting a corrupted store', () => {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ theme: 'not-a-real-theme' }),
        );

        expect(loadSettings().theme).toBe(DEFAULT_SETTINGS.theme);
    });

    it('clamps an out-of-range node scale instead of dropping it entirely', () => {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ nodeScale: NODE_SCALE_MAX + 50 }),
        );

        expect(loadSettings().nodeScale).toBe(NODE_SCALE_MAX);

        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ nodeScale: NODE_SCALE_MIN - 50 }),
        );

        expect(loadSettings().nodeScale).toBe(NODE_SCALE_MIN);
    });

    it('falls back to the default node scale when it is not a finite number', () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ nodeScale: 'NaN' }));

        expect(loadSettings().nodeScale).toBe(DEFAULT_SETTINGS.nodeScale);
    });
});

describe('saveSettings', () => {
    beforeEach(() => localStorage.clear());

    it('round-trips through loadSettings', () => {
        const custom = { ...DEFAULT_SETTINGS, theme: 'cyberpunk' as const };

        saveSettings(custom);

        expect(loadSettings()).toEqual(custom);
    });
});

describe('applyTheme', () => {
    afterEach(() => {
        document.documentElement.removeAttribute('data-theme');
        document.documentElement.removeAttribute('lang');
        document.documentElement.removeAttribute('dir');
    });

    it('sets theme, language and left-to-right direction on <html>', () => {
        applyTheme({ ...DEFAULT_SETTINGS, theme: 'midnight', lang: 'ru' });

        expect(document.documentElement.dataset.theme).toBe('midnight');
        expect(document.documentElement.lang).toBe('ru');
        expect(document.documentElement.dir).toBe('ltr');
    });

    it('switches to right-to-left for Farsi', () => {
        applyTheme({ ...DEFAULT_SETTINGS, lang: 'fa' });

        expect(document.documentElement.dir).toBe('rtl');
    });
});
