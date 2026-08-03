import { computed, inject, provide } from 'vue';
import type { ComputedRef, InjectionKey, Ref } from 'vue';
import { DEFAULT_SETTINGS, translations } from '../types/settings';
import type { AppSettings, TranslationKeys } from '../types/settings';

const SETTINGS_KEY: InjectionKey<Ref<AppSettings>> = Symbol('app-settings');

/** Вызывается один раз на странице; модалки ниже по дереву берут язык отсюда. */
export function provideSettings(settings: Ref<AppSettings>): void {
    provide(SETTINGS_KEY, settings);
}

/**
 * Переводы текущего языка. Избавляет от прокидывания settings пропом через
 * каждую модалку. Вне provideSettings падает на язык по умолчанию.
 */
export function useT(): ComputedRef<TranslationKeys> {
    const settings = inject(SETTINGS_KEY, null);

    return computed(
        () => translations[settings?.value.lang ?? DEFAULT_SETTINGS.lang],
    );
}

/** Подставляет значения в плейсхолдеры вида {name}. */
export function fill(
    template: string,
    values: Record<string, string | number>,
): string {
    return Object.entries(values).reduce(
        (text, [key, value]) => text.replaceAll(`{${key}}`, String(value)),
        template,
    );
}
