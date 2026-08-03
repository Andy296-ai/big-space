<script setup lang="ts">
import { Move } from 'lucide-vue-next';
import { useT } from '../lib/i18n';

/** Координаты храним строками: пустое поле означает «взять значение по умолчанию (0)». */
const posX = defineModel<string>('posX', { required: true });
const posY = defineModel<string>('posY', { required: true });

const t = useT();
</script>

<template>
    <div>
        <label
            class="mb-1.5 flex items-center gap-1.5 font-semibold text-slate-300"
        >
            <Move class="h-3.5 w-3.5 text-blue-400" />
            <span>{{ t.positionSectionLabel }}</span>
        </label>

        <div class="grid grid-cols-2 gap-2">
            <input
                v-model="posX"
                type="number"
                step="any"
                inputmode="decimal"
                :placeholder="t.posXLabel"
                :aria-label="t.posXLabel"
                class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
            />
            <input
                v-model="posY"
                type="number"
                step="any"
                inputmode="decimal"
                :placeholder="t.posYLabel"
                :aria-label="t.posYLabel"
                class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
            />
        </div>

        <p class="mt-1 px-1 text-[10px] text-slate-500">
            {{ t.rootPositionHint }}
        </p>
    </div>
</template>
