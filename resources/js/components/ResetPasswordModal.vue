<script setup lang="ts">
import { KeyRound, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { fill, useT } from '../lib/i18n';
import type { NodeData } from './SpaceScene.vue';

defineProps<{
    node: NodeData;
    error: string | null;
    isSaving: boolean;
}>();

const t = useT();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'submit', password: string): void;
}>();

const password = ref('');
const confirmPassword = ref('');

const validationError = computed(() => {
    if (password.value.length === 0 && confirmPassword.value.length === 0) {
        return null;
    }

    if (password.value.length < 9) {
        return t.value.passwordTooShort;
    }

    if (password.value !== confirmPassword.value) {
        return t.value.passwordsDontMatch;
    }

    return null;
});

const canSubmit = computed(
    () => password.value.length >= 9 && validationError.value === null,
);

function handleSubmit() {
    if (!canSubmit.value) {
        return;
    }

    emit('submit', password.value);
}
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md"
        @click.self="emit('close')"
    >
        <div
            class="animate-in fade-in zoom-in-95 max-h-[90vh] w-full max-w-sm space-y-4 overflow-y-auto rounded-3xl border border-slate-700/80 bg-slate-900 p-6 text-slate-100 shadow-2xl duration-200"
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div
                        class="rounded-xl border border-amber-500/30 bg-amber-600/20 p-2 text-amber-400"
                    >
                        <KeyRound class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold">
                            {{ t.resetPasswordTitle }}
                        </h3>
                        <p class="text-xs text-slate-400">
                            {{ node.title || t.untitledNode }}
                        </p>
                    </div>
                </div>
                <button
                    @click="emit('close')"
                    class="rounded-xl p-1.5 text-slate-400 hover:bg-slate-800 hover:text-slate-200"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="space-y-3 text-xs">
                <div>
                    <label class="mb-1 block font-semibold text-slate-300">{{
                        t.newPasswordLabel
                    }}</label>
                    <input
                        v-model="password"
                        type="password"
                        autocomplete="new-password"
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        @keydown.enter="handleSubmit"
                    />
                </div>
                <div>
                    <label class="mb-1 block font-semibold text-slate-300">{{
                        t.confirmPasswordLabel
                    }}</label>
                    <input
                        v-model="confirmPassword"
                        type="password"
                        autocomplete="new-password"
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        @keydown.enter="handleSubmit"
                    />
                </div>

                <p
                    v-if="validationError"
                    class="rounded-xl border border-amber-700/50 bg-amber-950/30 px-3 py-2 text-[11px] text-amber-300"
                >
                    {{ validationError }}
                </p>
                <p
                    v-if="error"
                    class="rounded-xl border border-rose-800/50 bg-rose-950/30 px-3 py-2 text-[11px] text-rose-300"
                >
                    {{ fill(t.genericErrorPrefix, { error }) }}
                </p>
            </div>

            <div
                class="flex items-center justify-end gap-2 border-t border-slate-800 pt-3"
            >
                <button
                    @click="emit('close')"
                    class="rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-slate-200"
                >
                    {{ t.cancel }}
                </button>
                <button
                    :disabled="!canSubmit || isSaving"
                    @click="handleSubmit"
                    class="flex items-center gap-1.5 rounded-xl bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-lg hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <KeyRound class="h-3.5 w-3.5" />
                    <span>{{ t.resetPasswordAction }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
