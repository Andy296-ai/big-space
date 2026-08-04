<script setup lang="ts">
import {
    Circle,
    Diamond,
    Hexagon,
    ImagePlus,
    Square,
    Triangle,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { useT } from '../lib/i18n';
import type { NodeShape } from './SpaceScene.vue';

const props = defineProps<{
    existingLogoUrl?: string | null;
}>();

const shape = defineModel<NodeShape>('shape', { required: true });
const logoFile = defineModel<File | null>('logoFile', { required: true });

const t = useT();

const shapeOptions: { id: NodeShape; icon: typeof Circle; labelKey: 'shapeCircle' | 'shapeSquare' | 'shapeTriangle' | 'shapeDiamond' | 'shapeHexagon' }[] = [
    { id: 'circle', icon: Circle, labelKey: 'shapeCircle' },
    { id: 'square', icon: Square, labelKey: 'shapeSquare' },
    { id: 'triangle', icon: Triangle, labelKey: 'shapeTriangle' },
    { id: 'diamond', icon: Diamond, labelKey: 'shapeDiamond' },
    { id: 'hexagon', icon: Hexagon, labelKey: 'shapeHexagon' },
];

const previewUrl = computed(() => {
    if (logoFile.value) {
        return URL.createObjectURL(logoFile.value);
    }

    return props.existingLogoUrl ?? null;
});

function onFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    logoFile.value = file;
}
</script>

<template>
    <div>
        <label class="mb-1.5 block font-semibold text-slate-300">{{
            t.shapeSectionLabel
        }}</label>
        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="opt in shapeOptions"
                :key="opt.id"
                type="button"
                @click="shape = opt.id"
                :title="t[opt.labelKey]"
                :aria-label="t[opt.labelKey]"
                class="rounded-xl border p-2 transition-all"
                :class="
                    shape === opt.id
                        ? 'border-blue-500 bg-blue-600/20 text-blue-300'
                        : 'border-slate-700 bg-slate-800 text-slate-400 hover:text-slate-200'
                "
            >
                <component :is="opt.icon" class="h-4 w-4" />
            </button>
        </div>

        <label class="mt-3 mb-1.5 block font-semibold text-slate-300">{{
            t.logoSectionLabel
        }}</label>
        <div class="flex items-center gap-3">
            <img
                v-if="previewUrl"
                :src="previewUrl"
                alt=""
                class="h-10 w-10 shrink-0 rounded-full border border-slate-700 object-cover"
            />
            <label
                class="flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-slate-300 transition-colors hover:bg-slate-700"
            >
                <ImagePlus class="h-3.5 w-3.5" />
                <span>{{ t.uploadLogoAction }}</span>
                <input
                    type="file"
                    accept="image/*"
                    class="hidden"
                    @change="onFileChange"
                />
            </label>
        </div>
    </div>
</template>
