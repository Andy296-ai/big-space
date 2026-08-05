<script setup lang="ts">
import {
    KeyRound,
    Loader2,
    ScrollText,
    Trash2,
    UserMinus,
    UserPlus,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { fill, useT } from '../lib/i18n';
import type { TranslationKeys } from '../types/settings';

interface ActivityLogEntry {
    id: number;
    action: string;
    subject_id: number | null;
    meta: Record<string, unknown> | null;
    created_at: string;
    actor: { id: number; name: string; email: string } | null;
}

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const t = useT();

const loading = ref(true);
const entries = ref<ActivityLogEntry[]>([]);

const ACTION_ICONS: Record<string, typeof UserPlus> = {
    'user.created': UserPlus,
    'user.deleted': UserMinus,
    'user.password_reset': KeyRound,
    'space.deleted': Trash2,
};

const ACTION_LABEL_KEYS: Record<string, keyof TranslationKeys> = {
    'user.created': 'actionUserCreated',
    'user.deleted': 'actionUserDeleted',
    'user.password_reset': 'actionPasswordReset',
    'space.deleted': 'actionSpaceDeleted',
};

function iconFor(action: string) {
    return ACTION_ICONS[action] ?? ScrollText;
}

function describe(entry: ActivityLogEntry): string {
    const labelKey = ACTION_LABEL_KEYS[entry.action];
    const name =
        (entry.meta?.name as string | undefined) ??
        (entry.meta?.username as string | undefined) ??
        `#${entry.subject_id}`;

    return labelKey ? fill(t.value[labelKey], { name }) : entry.action;
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString();
}

const rows = computed(() =>
    entries.value.map((entry) => ({
        entry,
        icon: iconFor(entry.action),
        text: describe(entry),
        actorName: entry.actor?.name ?? t.value.activityLogSystemActor,
        time: formatTime(entry.created_at),
    })),
);

onMounted(async () => {
    try {
        const res = await apiFetch('/api/admin/activity-log');
        const data = await res.json();
        entries.value = data.entries ?? [];
    } catch (err) {
        console.error('Failed to load activity log:', err);
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <div
                class="flex h-[80vh] w-[92vw] max-w-xl flex-col overflow-hidden rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-slate-800 px-5 py-3.5"
                >
                    <div class="flex min-w-0 items-center gap-2.5">
                        <div
                            class="rounded-xl border border-amber-500/30 bg-amber-600/20 p-2 text-amber-400"
                        >
                            <ScrollText class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-bold">
                                {{ t.activityLogTitle }}
                            </h3>
                            <p class="truncate text-[11px] text-slate-400">
                                {{ t.activityLogDesc }}
                            </p>
                        </div>
                    </div>
                    <button
                        @click="emit('close')"
                        :aria-label="t.close"
                        class="shrink-0 rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-800 hover:text-slate-200"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-auto p-3">
                    <div
                        v-if="loading"
                        class="flex h-full items-center justify-center text-slate-400"
                    >
                        <Loader2 class="h-5 w-5 animate-spin" />
                    </div>
                    <div
                        v-else-if="!rows.length"
                        class="flex h-full items-center justify-center text-xs text-slate-500"
                    >
                        {{ t.activityLogEmpty }}
                    </div>
                    <div v-else class="space-y-1.5">
                        <div
                            v-for="row in rows"
                            :key="row.entry.id"
                            class="flex items-start gap-3 rounded-2xl border border-slate-800 bg-slate-800/40 p-3"
                        >
                            <div
                                class="mt-0.5 shrink-0 rounded-lg border border-slate-700 bg-slate-800 p-1.5 text-slate-400"
                            >
                                <component :is="row.icon" class="h-3.5 w-3.5" />
                            </div>
                            <div class="min-w-0 flex-1 text-xs">
                                <p class="text-slate-200">
                                    <span
                                        class="font-semibold text-slate-100"
                                        >{{ row.actorName }}</span
                                    >
                                    {{ ' ' }}{{ row.text }}
                                </p>
                                <p class="mt-0.5 text-[10px] text-slate-500">
                                    {{ row.time }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
