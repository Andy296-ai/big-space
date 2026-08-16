<script setup lang="ts">
import {
    Check,
    Copy,
    Globe,
    Loader2,
    RefreshCw,
    UserMinus,
} from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { useT } from '../lib/i18n';

interface PublicLink {
    token: string;
    url: string;
}

/**
 * Общий блок "публичная read-only ссылка" — переиспользуется и для всего
 * пространства (ShareSpaceModal), и для поддерева узла (ShareNodeModal):
 * логика захвата/копирования/регенерации/отзыва идентична, различается
 * только apiUrl (какую область шарим).
 */
const props = defineProps<{
    apiUrl: string;
}>();

const t = useT();

const link = ref<PublicLink | null>(null);
const loading = ref(true);
const generating = ref(false);
const copied = ref(false);

async function load() {
    loading.value = true;

    try {
        const res = await apiFetch(props.apiUrl);
        link.value = await res.json();
    } catch (err) {
        console.error('Failed to load the public link status:', err);
    } finally {
        loading.value = false;
    }
}

/** POST заменяет уже существующую ссылку новым токеном — это и есть "регенерация". */
async function generate() {
    if (generating.value) {
        return;
    }

    generating.value = true;

    try {
        const res = await apiFetch(props.apiUrl, { method: 'POST' });
        link.value = await res.json();
    } catch (err) {
        console.error('Failed to generate the public link:', err);
    } finally {
        generating.value = false;
    }
}

async function revoke() {
    try {
        await apiFetch(props.apiUrl, { method: 'DELETE' });
        link.value = null;
    } catch (err) {
        console.error('Failed to revoke the public link:', err);
    }
}

async function copy() {
    if (!link.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(link.value.url);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch (err) {
        console.error('Failed to copy the public link:', err);
    }
}

onMounted(load);
</script>

<template>
    <div v-if="loading" class="flex justify-center py-2 text-slate-400">
        <Loader2 class="h-4 w-4 animate-spin" />
    </div>

    <div v-else-if="link" class="flex items-center gap-1.5">
        <input
            :value="link.url"
            readonly
            class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-[11px] text-slate-300"
        />
        <button
            @click="copy"
            :title="t.copyLinkAction"
            class="shrink-0 rounded-xl border border-slate-700 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700"
        >
            <Check v-if="copied" class="h-3.5 w-3.5 text-emerald-400" />
            <Copy v-else class="h-3.5 w-3.5" />
        </button>
        <button
            :disabled="generating"
            @click="generate"
            :title="t.regenerateLinkAction"
            class="shrink-0 rounded-xl border border-slate-700 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
            <Loader2 v-if="generating" class="h-3.5 w-3.5 animate-spin" />
            <RefreshCw v-else class="h-3.5 w-3.5" />
        </button>
        <button
            @click="revoke"
            :title="t.revokeLinkAction"
            class="shrink-0 rounded-xl border border-slate-700 bg-slate-800 p-2 text-slate-500 transition-colors hover:bg-rose-950/40 hover:text-rose-400"
        >
            <UserMinus class="h-3.5 w-3.5" />
        </button>
    </div>

    <button
        v-else
        :disabled="generating"
        @click="generate"
        class="flex w-full items-center justify-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-semibold text-slate-200 transition-colors hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
    >
        <Loader2 v-if="generating" class="h-3.5 w-3.5 animate-spin" />
        <Globe v-else class="h-3.5 w-3.5" />
        <span>{{ t.generateLinkAction }}</span>
    </button>
</template>
