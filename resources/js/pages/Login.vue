<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { LockKeyhole } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import NodusMark from '../components/NodusMark.vue';
import {
    applyTheme,
    loadSettings,
    saveSettings,
    translations,
} from '../types/settings';
import type { AppSettings, Language } from '../types/settings';

const settings = ref<AppSettings>(loadSettings());
const t = computed(() => translations[settings.value.lang]);

const languageOptions: { id: Language; label: string }[] = [
    { id: 'en', label: 'EN' },
    { id: 'ru', label: 'RU' },
    { id: 'tg', label: 'TJ' },
    { id: 'fa', label: 'FA' },
];

const form = useForm({
    username: '',
    password: '',
    remember: false,
});

/**
 * Сервер отдаёт код, а не готовый текст — иначе сообщение об ошибке было бы
 * единственным местом интерфейса, которое не переводится.
 */
const errorMessage = computed(() => {
    const raw = form.errors.username ?? form.errors.password;

    if (!raw) {
        return null;
    }

    if (raw === 'invalid_credentials') {
        return t.value.invalidCredentials;
    }

    if (raw === 'too_many_attempts') {
        return t.value.tooManyAttempts;
    }

    return raw;
});

function selectLanguage(lang: Language) {
    settings.value = { ...settings.value, lang };
    saveSettings(settings.value);
    applyTheme(settings.value);
}

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}

onMounted(() => applyTheme(settings.value));
</script>

<template>
    <Head :title="t.loginTitle" />

    <div
        class="relative flex min-h-screen w-full items-center justify-center bg-slate-950 p-4 font-sans"
    >
        <!-- Переключатель языка доступен до входа: иначе на незнакомом языке не разобраться -->
        <div
            class="absolute end-4 top-4 flex items-center gap-1 rounded-2xl border border-slate-700/60 bg-slate-900/80 p-1"
        >
            <button
                v-for="lang in languageOptions"
                :key="lang.id"
                type="button"
                @click="selectLanguage(lang.id)"
                :class="[
                    'rounded-xl px-2.5 py-1 text-[11px] font-bold transition-colors',
                    settings.lang === lang.id
                        ? 'bg-blue-600/25 text-blue-300'
                        : 'text-slate-400 hover:text-slate-200',
                ]"
            >
                {{ lang.label }}
            </button>
        </div>

        <div
            class="w-full max-w-sm rounded-3xl border border-slate-700/80 bg-slate-900 p-7 text-slate-100 shadow-2xl"
        >
            <div class="mb-1 flex items-center gap-3">
                <div
                    class="rounded-xl border border-blue-500/30 bg-blue-600/20 p-2 text-blue-400"
                >
                    <NodusMark class="h-5 w-5" />
                </div>
                <div class="min-w-0">
                    <h1 class="text-lg leading-tight font-bold">
                        {{ t.loginTitle }}
                    </h1>
                    <p class="truncate text-xs text-slate-400">
                        Infinite Space 3D
                    </p>
                </div>
            </div>

            <p class="mb-5 text-xs text-slate-400">{{ t.loginSubtitle }}</p>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label
                        for="username"
                        class="mb-1.5 block text-xs font-semibold text-slate-300"
                    >
                        {{ t.usernameLabel }}
                    </label>
                    <input
                        id="username"
                        v-model="form.username"
                        type="text"
                        autocomplete="username"
                        autofocus
                        required
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-sm text-slate-100 transition-colors focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <div>
                    <label
                        for="password"
                        class="mb-1.5 block text-xs font-semibold text-slate-300"
                    >
                        {{ t.passwordLabel }}
                    </label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-sm text-slate-100 transition-colors focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <label
                    class="flex cursor-pointer items-center gap-2 text-xs text-slate-300 select-none"
                >
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="rounded border-slate-700 bg-slate-800 text-blue-500"
                    />
                    <span>{{ t.rememberMe }}</span>
                </label>

                <p
                    v-if="errorMessage"
                    class="rounded-xl border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-xs text-rose-400"
                >
                    {{ errorMessage }}
                </p>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg transition-all hover:bg-blue-500 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <LockKeyhole class="h-4 w-4" />
                    <span>{{ form.processing ? t.signingIn : t.signIn }}</span>
                </button>
            </form>
        </div>
    </div>
</template>
