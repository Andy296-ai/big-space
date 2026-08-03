<script setup lang="ts">
import { MapPin } from 'lucide-vue-next';
import { useT } from '../lib/i18n';

/** Координаты храним строками: пустое поле должно означать «точки нет». */
const lat = defineModel<string>('lat', { required: true });
const lon = defineModel<string>('lon', { required: true });
const mapTitle = defineModel<string>('mapTitle', { required: true });

const t = useT();
</script>

<template>
    <div>
        <label
            class="mb-1.5 flex items-center gap-1.5 font-semibold text-slate-300"
        >
            <MapPin class="h-3.5 w-3.5 text-emerald-400" />
            <span>{{ t.mapSectionLabel }}</span>
        </label>

        <div class="grid grid-cols-2 gap-2">
            <input
                v-model="lat"
                type="text"
                inputmode="decimal"
                :placeholder="t.latLabel"
                :aria-label="t.latLabel"
                class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
            />
            <input
                v-model="lon"
                type="text"
                inputmode="decimal"
                :placeholder="t.lonLabel"
                :aria-label="t.lonLabel"
                class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
            />
        </div>

        <input
            v-model="mapTitle"
            type="text"
            :placeholder="t.mapTitleLabel"
            :aria-label="t.mapTitleLabel"
            class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-100 focus:border-blue-500 focus:outline-none"
        />
    </div>
</template>
