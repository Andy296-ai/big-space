<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Bell, CheckCheck, UserPlus } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { getEcho } from '../lib/echo';
import { fill, useT } from '../lib/i18n';

interface NotificationEntry {
    id: string;
    type: string;
    data: Record<string, unknown>;
    read_at: string | null;
    created_at: string;
}

const t = useT();

const open = ref(false);
const notifications = ref<NotificationEntry[]>([]);
const unreadCount = ref(0);
const rootEl = ref<HTMLDivElement | null>(null);
// Teleported to <body> and positioned manually: the HUD's own header pills
// use backdrop-filter, which forces each pill into its own stacking context —
// a plain z-index on the dropdown can't out-rank a *later* sibling pill that
// happens to overlap it on screen (e.g. the Nodes/Edges stats pill).
const dropdownPos = ref({ top: 0, left: 0 });

function toggleOpen() {
    if (!open.value && rootEl.value) {
        const rect = rootEl.value.getBoundingClientRect();
        dropdownPos.value = { top: rect.bottom + 8, left: rect.right - 320 };
    }

    open.value = !open.value;

    if (open.value) {
        load();
    }
}

async function load() {
    try {
        const res = await apiFetch('/api/notifications');
        const data = await res.json();
        notifications.value = data.notifications ?? [];
        unreadCount.value = data.unread_count ?? 0;
    } catch (err) {
        console.error('Failed to load notifications:', err);
    }
}

function describe(entry: NotificationEntry): string {
    if (entry.type.endsWith('SpaceAccessGranted')) {
        const roleKey =
            entry.data.role === 'editor'
                ? 'roleEditorBadge'
                : 'roleViewerBadge';

        return fill(t.value.notificationAccessGranted, {
            owner: (entry.data.owner_name as string | undefined) ?? '?',
            role: t.value[roleKey],
            space: (entry.data.space_name as string | undefined) ?? '?',
        });
    }

    return entry.type;
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString();
}

async function markAllRead() {
    notifications.value = notifications.value.map((n) => ({
        ...n,
        read_at: n.read_at ?? new Date().toISOString(),
    }));
    unreadCount.value = 0;

    try {
        await apiFetch('/api/notifications/read-all', { method: 'POST' });
    } catch (err) {
        console.error('Failed to mark all notifications as read:', err);
    }
}

async function handleSelect(entry: NotificationEntry) {
    open.value = false;

    if (!entry.read_at) {
        entry.read_at = new Date().toISOString();
        unreadCount.value = Math.max(0, unreadCount.value - 1);

        try {
            await apiFetch(`/api/notifications/${entry.id}/read`, {
                method: 'POST',
            });
        } catch (err) {
            console.error('Failed to mark notification as read:', err);
        }
    }

    if (entry.type.endsWith('SpaceAccessGranted') && entry.data.space_slug) {
        router.get('/', { space: entry.data.space_slug as string });
    }
}

const dropdownEl = ref<HTMLDivElement | null>(null);

function handleClickOutside(event: MouseEvent) {
    const target = event.target as Node;

    if (
        open.value &&
        !rootEl.value?.contains(target) &&
        !dropdownEl.value?.contains(target)
    ) {
        open.value = false;
    }
}

const currentUserId = computed(() => usePage().props.auth.user.id);
const channelName = computed(() => `App.Models.User.${currentUserId.value}`);

onMounted(() => {
    load();
    document.addEventListener('click', handleClickOutside);
    getEcho().private(channelName.value).listen('.notification.posted', load);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    getEcho()
        .private(channelName.value)
        .stopListening('.notification.posted', load);
});
</script>

<template>
    <div ref="rootEl" class="relative">
        <button
            @click="toggleOpen"
            class="relative rounded-xl border border-slate-600/50 bg-slate-800 p-2 text-slate-300 transition-colors hover:bg-slate-700 hover:text-white"
            :title="t.notificationsAction"
        >
            <Bell class="h-4 w-4" />
            <span
                v-if="unreadCount > 0"
                class="absolute -top-1.5 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white"
            >
                {{ unreadCount > 9 ? '9+' : unreadCount }}
            </span>
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="dropdownEl"
                :style="{
                    top: `${dropdownPos.top}px`,
                    left: `${dropdownPos.left}px`,
                }"
                class="fixed z-50 w-80 overflow-hidden rounded-2xl border border-slate-700/80 bg-slate-900/95 shadow-2xl backdrop-blur-md"
            >
                <div
                    class="flex items-center justify-between gap-2 border-b border-slate-800 px-4 py-3"
                >
                    <span class="text-sm font-bold text-slate-100">{{
                        t.notificationsTitle
                    }}</span>
                    <button
                        v-if="unreadCount > 0"
                        @click="markAllRead"
                        class="flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-semibold text-blue-400 transition-colors hover:bg-slate-800 hover:text-blue-300"
                    >
                        <CheckCheck class="h-3.5 w-3.5" />
                        <span>{{ t.notificationsMarkAllRead }}</span>
                    </button>
                </div>

                <div class="max-h-96 overflow-y-auto">
                    <div
                        v-if="!notifications.length"
                        class="px-4 py-6 text-center text-xs text-slate-500"
                    >
                        {{ t.notificationsEmpty }}
                    </div>
                    <button
                        v-for="entry in notifications"
                        :key="entry.id"
                        type="button"
                        @click="handleSelect(entry)"
                        class="flex w-full items-start gap-2.5 border-b border-slate-800/60 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-slate-800/60"
                        :class="{ 'bg-blue-600/10': !entry.read_at }"
                    >
                        <div
                            class="mt-0.5 shrink-0 rounded-lg border border-blue-500/30 bg-blue-600/20 p-1.5 text-blue-400"
                        >
                            <UserPlus class="h-3.5 w-3.5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-slate-200">
                                {{ describe(entry) }}
                            </p>
                            <p class="mt-0.5 text-[10px] text-slate-500">
                                {{ formatTime(entry.created_at) }}
                            </p>
                        </div>
                        <span
                            v-if="!entry.read_at"
                            class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-400"
                        />
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
