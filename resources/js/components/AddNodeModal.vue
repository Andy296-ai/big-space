<script setup lang="ts">
import { X, Plus, Palette, Tag, UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useT } from '../lib/i18n';
import AttachmentEditor from './AttachmentEditor.vue';
import type { PendingAttachment } from './AttachmentEditor.vue';
import MapFields from './MapFields.vue';
import PositionFields from './PositionFields.vue';
import ShapeFields from './ShapeFields.vue';
import type { NodeData, NodeShape } from './SpaceScene.vue';

const props = defineProps<{
    parentNode: NodeData | null;
    /** В Admin-пространстве «Добавить корень» создаёт не узел, а пользователя. */
    isAdminSpace?: boolean;
}>();

const t = useT();

const emit = defineEmits<{
    (e: 'close'): void;
    (
        e: 'submit',
        payload: {
            title: string;
            description: string;
            color: string;
            tags: string;
            map_lat: number | null;
            map_lon: number | null;
            map_title: string | null;
            pos_x: number | null;
            pos_y: number | null;
            shape: NodeShape;
            logoFile: File | null;
            pending: PendingAttachment[];
        },
    ): void;
    (
        e: 'submit-user',
        payload: {
            title: string;
            username: string;
            email: string;
            password: string;
            color: string;
            shape: NodeShape;
            tags: string;
            logoFile: File | null;
        },
    ): void;
}>();

const isUserForm = computed(() => props.isAdminSpace && !props.parentNode);

const title = ref('');
const description = ref('');
const color = ref('');
const tags = ref('');
const mapLat = ref('');
const mapLon = ref('');
const mapTitle = ref('');
const posX = ref('');
const posY = ref('');
const shape = ref<NodeShape>('circle');
const logoFile = ref<File | null>(null);
const pending = ref<PendingAttachment[]>([]);
const username = ref('');
const email = ref('');
const password = ref('');

/** Пустое поле означает «точки нет», а не ноль. */
function toCoord(value: string): number | null {
    const trimmed = value.trim();

    return trimmed === '' || Number.isNaN(Number(trimmed))
        ? null
        : Number(trimmed);
}

const presetColors = [
    '#3b82f6',
    '#10b981',
    '#8b5cf6',
    '#f59e0b',
    '#ec4899',
    '#06b6d4',
    '#84cc16',
    '#a855f7',
    '#f97316',
    '#14b8a6',
];

function handleSubmit() {
    if (!title.value.trim()) {
        return;
    }

    if (isUserForm.value) {
        if (
            !username.value.trim() ||
            !email.value.trim() ||
            !password.value.trim()
        ) {
            return;
        }

        emit('submit-user', {
            title: title.value.trim(),
            username: username.value.trim(),
            email: email.value.trim(),
            password: password.value,
            color: color.value,
            shape: shape.value,
            tags: tags.value.trim(),
            logoFile: logoFile.value,
        });

        return;
    }

    emit('submit', {
        title: title.value.trim(),
        description: description.value.trim(),
        color: color.value,
        tags: tags.value.trim(),
        map_lat: toCoord(mapLat.value),
        map_lon: toCoord(mapLon.value),
        map_title: mapTitle.value.trim() || null,
        pos_x: toCoord(posX.value),
        pos_y: toCoord(posY.value),
        shape: shape.value,
        logoFile: logoFile.value,
        pending: pending.value,
    });
}
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-md"
        @click.self="emit('close')"
    >
        <div
            class="animate-in fade-in zoom-in-95 flex max-h-[90vh] w-full max-w-md flex-col rounded-3xl border border-slate-700/80 bg-slate-900 text-slate-100 shadow-2xl duration-200"
        >
            <div class="flex items-center justify-between p-6 pb-4">
                <div class="flex items-center gap-2.5">
                    <div
                        class="rounded-xl border border-blue-500/30 bg-blue-600/20 p-2 text-blue-400"
                    >
                        <UserPlus v-if="isUserForm" class="h-5 w-5" />
                        <Plus v-else class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-base font-bold">
                            {{
                                isUserForm
                                    ? t.addUserTitle
                                    : parentNode
                                      ? t.addChildTitle
                                      : t.addRootNode
                            }}
                        </h3>
                        <p v-if="isUserForm" class="text-xs text-slate-400">
                            {{ t.newUserHint }}
                        </p>
                        <p
                            v-else-if="parentNode"
                            class="text-xs text-slate-400"
                        >
                            {{ t.parentLabel }}: {{ parentNode.title }}
                        </p>
                        <p v-else class="text-xs text-slate-400">
                            {{ t.newRootHint }}
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

            <div
                class="min-h-0 flex-1 space-y-3.5 overflow-y-auto px-6 pb-2 text-xs"
            >
                <div>
                    <label class="mb-1 block font-semibold text-slate-300"
                        >{{
                            isUserForm ? t.displayNameLabel : t.titleLabel
                        }}
                        *</label
                    >
                    <input
                        v-model="title"
                        type="text"
                        :placeholder="t.nodeTitlePlaceholder"
                        required
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <template v-if="isUserForm">
                    <div>
                        <label class="mb-1 block font-semibold text-slate-300"
                            >{{ t.usernameLabel }} *</label
                        >
                        <input
                            v-model="username"
                            type="text"
                            required
                            autocomplete="off"
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-semibold text-slate-300"
                            >{{ t.userEmailLabel }} *</label
                        >
                        <input
                            v-model="email"
                            type="email"
                            required
                            autocomplete="off"
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-semibold text-slate-300"
                            >{{ t.passwordLabel }} *</label
                        >
                        <input
                            v-model="password"
                            type="password"
                            required
                            autocomplete="new-password"
                            class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                        />
                    </div>
                </template>

                <div v-else>
                    <label class="mb-1 block font-semibold text-slate-300">{{
                        t.descriptionLabel
                    }}</label>
                    <textarea
                        v-model="description"
                        rows="3"
                        :placeholder="t.nodeDetailsPlaceholder"
                        class="w-full resize-none rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <div>
                    <label
                        class="mb-1.5 block flex items-center gap-1.5 font-semibold text-slate-300"
                    >
                        <Palette class="h-3.5 w-3.5 text-slate-400" />
                        <span>{{ t.colorLabel }}</span>
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="c in presetColors"
                            :key="c"
                            type="button"
                            @click="color = c"
                            class="h-6 w-6 rounded-full border border-white/20 transition-transform active:scale-95"
                            :class="{
                                'ring-2 ring-white ring-offset-2 ring-offset-slate-900':
                                    color === c,
                            }"
                            :style="{ backgroundColor: c }"
                        />
                        <button
                            type="button"
                            @click="color = ''"
                            class="rounded-lg border border-slate-700 bg-slate-800 px-2 py-1 text-[10px] text-slate-400 hover:text-slate-200"
                        >
                            {{ t.colorAuto }}
                        </button>
                    </div>
                </div>

                <div>
                    <label
                        class="mb-1 block flex items-center gap-1.5 font-semibold text-slate-300"
                    >
                        <Tag class="h-3.5 w-3.5 text-slate-400" />
                        <span>{{ t.tagsLabel }}</span>
                    </label>
                    <input
                        v-model="tags"
                        type="text"
                        :placeholder="t.tagsPlaceholder"
                        class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <template v-if="!isUserForm">
                    <MapFields
                        v-model:lat="mapLat"
                        v-model:lon="mapLon"
                        v-model:map-title="mapTitle"
                    />

                    <PositionFields
                        v-if="!parentNode"
                        v-model:pos-x="posX"
                        v-model:pos-y="posY"
                    />
                </template>

                <ShapeFields
                    v-model:shape="shape"
                    v-model:logo-file="logoFile"
                />

                <AttachmentEditor
                    v-if="!isUserForm"
                    v-model:pending="pending"
                />
            </div>

            <div
                class="flex items-center justify-end gap-2 border-t border-slate-800 p-6 pt-3"
            >
                <button
                    @click="emit('close')"
                    class="rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-slate-200"
                >
                    {{ t.cancel }}
                </button>
                <button
                    @click="handleSubmit"
                    class="rounded-xl bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-md hover:bg-blue-500"
                >
                    {{ isUserForm ? t.createUserAction : t.addNode }}
                </button>
            </div>
        </div>
    </div>
</template>
