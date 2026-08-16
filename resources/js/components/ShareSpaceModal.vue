<script setup lang="ts">
import { Globe, Loader2, Share2, UserMinus, X } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { apiFetch } from '../lib/api';
import { useT } from '../lib/i18n';
import PublicLinkManager from './PublicLinkManager.vue';
import type { SpaceItem } from './SpaceChooserModal.vue';

interface CollaboratorEntry {
    id: number;
    name: string;
    email: string;
    pivot: { role: 'viewer' | 'editor' };
}

const props = defineProps<{
    space: SpaceItem;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const t = useT();

const loading = ref(true);
const collaborators = ref<CollaboratorEntry[]>([]);
const identifier = ref('');
const role = ref<'viewer' | 'editor'>('viewer');
const isSaving = ref(false);
const error = ref<string | null>(null);

async function load() {
    loading.value = true;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.space.id}/collaborators`,
        );
        collaborators.value = await res.json();
    } catch (err) {
        console.error('Failed to load collaborators:', err);
    } finally {
        loading.value = false;
    }
}

async function handleShare() {
    if (!identifier.value.trim() || isSaving.value) {
        return;
    }

    isSaving.value = true;
    error.value = null;

    try {
        const res = await apiFetch(
            `/api/spaces/${props.space.id}/collaborators`,
            {
                method: 'POST',
                body: JSON.stringify({
                    identifier: identifier.value.trim(),
                    role: role.value,
                }),
            },
        );

        if (!res.ok) {
            const data = await res.json();
            error.value =
                data.errors?.identifier?.[0] ??
                data.message ??
                'Failed to share.';

            return;
        }

        identifier.value = '';
        await load();
    } catch (err) {
        console.error('Failed to share space:', err);
    } finally {
        isSaving.value = false;
    }
}

async function changeRole(
    collaborator: CollaboratorEntry,
    newRole: 'viewer' | 'editor',
) {
    collaborator.pivot.role = newRole; // оптимистично — откатим при ошибке

    try {
        await apiFetch(
            `/api/spaces/${props.space.id}/collaborators/${collaborator.id}`,
            {
                method: 'PUT',
                body: JSON.stringify({ role: newRole }),
            },
        );
    } catch (err) {
        console.error('Failed to change role:', err);
        await load();
    }
}

async function revoke(collaborator: CollaboratorEntry) {
    try {
        await apiFetch(
            `/api/spaces/${props.space.id}/collaborators/${collaborator.id}`,
            {
                method: 'DELETE',
            },
        );
        collaborators.value = collaborators.value.filter(
            (c) => c.id !== collaborator.id,
        );
    } catch (err) {
        console.error('Failed to revoke access:', err);
    }
}

onMounted(load);
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md"
        @click.self="emit('close')"
    >
        <div
            class="animate-in fade-in zoom-in-95 max-h-[90vh] w-full max-w-md space-y-4 overflow-y-auto rounded-3xl border border-slate-700/80 bg-slate-900 p-6 text-slate-100 shadow-2xl duration-200"
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div
                        class="rounded-xl border border-emerald-500/30 bg-emerald-600/20 p-2 text-emerald-400"
                    >
                        <Share2 class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold">
                            {{ t.shareSpaceTitle }}
                        </h3>
                        <p class="text-xs text-slate-400">
                            {{ space.name }}
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

            <p class="text-xs text-slate-400">{{ t.shareSpaceDesc }}</p>

            <!-- Форма приглашения -->
            <div class="flex items-end gap-2 text-xs">
                <div class="flex-1">
                    <label class="mb-1 block font-semibold text-slate-300">{{
                        t.shareIdentifierLabel
                    }}</label>
                    <input
                        v-model="identifier"
                        type="text"
                        :placeholder="t.shareIdentifierPlaceholder"
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        @keydown.enter="handleShare"
                    />
                </div>
                <div>
                    <label class="mb-1 block font-semibold text-slate-300">{{
                        t.shareRoleLabel
                    }}</label>
                    <select
                        v-model="role"
                        class="rounded-xl border border-slate-700 bg-slate-800 px-2 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                    >
                        <option value="viewer">{{ t.roleViewerBadge }}</option>
                        <option value="editor">{{ t.roleEditorBadge }}</option>
                    </select>
                </div>
                <button
                    :disabled="!identifier.trim() || isSaving"
                    @click="handleShare"
                    class="rounded-xl bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white shadow-md hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ t.shareAction }}
                </button>
            </div>
            <p
                v-if="error"
                class="rounded-xl border border-rose-800/50 bg-rose-950/30 px-3 py-2 text-[11px] text-rose-300"
            >
                {{ error }}
            </p>

            <!-- Публичная read-only ссылка на всё пространство -->
            <div class="space-y-2 border-t border-slate-800 pt-3">
                <div class="flex items-center gap-2">
                    <Globe class="h-3.5 w-3.5 text-slate-400" />
                    <span class="text-xs font-semibold text-slate-300">{{
                        t.publicLinkLabel
                    }}</span>
                </div>
                <p class="text-[11px] text-slate-500">
                    {{ t.publicLinkDesc }}
                </p>

                <PublicLinkManager :api-url="`/api/spaces/${space.id}/share`" />
            </div>

            <!-- Список участников -->
            <div
                class="max-h-56 space-y-1.5 overflow-y-auto border-t border-slate-800 pt-3"
            >
                <div
                    v-if="loading"
                    class="flex justify-center py-4 text-slate-400"
                >
                    <Loader2 class="h-4 w-4 animate-spin" />
                </div>
                <p
                    v-else-if="!collaborators.length"
                    class="py-2 text-center text-[11px] text-slate-500"
                >
                    {{ t.shareEmptyLabel }}
                </p>
                <div
                    v-for="c in collaborators"
                    :key="c.id"
                    class="flex items-center justify-between gap-2 rounded-xl border border-slate-800 bg-slate-800/40 p-2.5 text-xs"
                >
                    <div class="min-w-0">
                        <div class="truncate font-semibold text-slate-200">
                            {{ c.name }}
                        </div>
                        <div class="truncate text-[10px] text-slate-500">
                            {{ c.email }}
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <select
                            :value="c.pivot.role"
                            @change="
                                changeRole(
                                    c,
                                    ($event.target as HTMLSelectElement)
                                        .value as 'viewer' | 'editor',
                                )
                            "
                            class="rounded-lg border border-slate-700 bg-slate-800 px-1.5 py-1 text-[11px] text-slate-200 focus:border-blue-500 focus:outline-none"
                        >
                            <option value="viewer">
                                {{ t.roleViewerBadge }}
                            </option>
                            <option value="editor">
                                {{ t.roleEditorBadge }}
                            </option>
                        </select>
                        <button
                            @click="revoke(c)"
                            :title="t.revokeAccessAction"
                            class="rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-rose-950/40 hover:text-rose-400"
                        >
                            <UserMinus class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
