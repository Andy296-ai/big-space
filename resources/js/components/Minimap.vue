<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { THEME_TOKENS, translations } from '../types/settings';
import type { AppSettings } from '../types/settings';
import type { CameraState, EdgeData, NodeData } from './SpaceScene.vue';

const props = defineProps<{
    nodes: NodeData[];
    edges: EdgeData[];
    selectedNodeId: number | null;
    cameraPos: CameraState;
    settings: AppSettings;
}>();

const t = computed(() => translations[props.settings.lang]);
const tokens = computed(
    () => THEME_TOKENS[props.settings.theme] ?? THEME_TOKENS.cosmic,
);

const emit = defineEmits<{
    (e: 'focus-pos', payload: { x: number; y: number }): void;
}>();

const canvasRef = ref<HTMLCanvasElement | null>(null);

function drawMinimap() {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return;
    }

    const w = canvas.width;
    const h = canvas.height;

    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = tokens.value.canvasBg;
    ctx.fillRect(0, 0, w, h);

    if (props.nodes.length === 0) {
        return;
    }

    // Find min/max bounds
    let minX = -300,
        maxX = 300,
        minY = -300,
        maxY = 300;
    props.nodes.forEach((n) => {
        minX = Math.min(minX, n.pos_x);
        maxX = Math.max(maxX, n.pos_x);
        minY = Math.min(minY, n.pos_y);
        maxY = Math.max(maxY, n.pos_y);
    });

    const margin = 50;
    minX -= margin;
    maxX += margin;
    minY -= margin;
    maxY += margin;

    const rangeX = maxX - minX || 1;
    const rangeY = maxY - minY || 1;

    function toCanvasX(x: number) {
        return ((x - minX) / rangeX) * (w - 16) + 8;
    }
    function toCanvasY(y: number) {
        return (1 - (y - minY) / rangeY) * (h - 16) + 8;
    }

    // Draw Edges
    ctx.strokeStyle = tokens.value.gridMajor;
    ctx.lineWidth = 1;
    const nodeMap = new Map(props.nodes.map((n) => [n.id, n]));
    props.edges.forEach((e) => {
        const p = nodeMap.get(e.parent_id);
        const c = nodeMap.get(e.child_id);

        if (p && c) {
            ctx.beginPath();
            ctx.moveTo(toCanvasX(p.pos_x), toCanvasY(p.pos_y));
            ctx.lineTo(toCanvasX(c.pos_x), toCanvasY(c.pos_y));
            ctx.stroke();
        }
    });

    // Draw Nodes
    props.nodes.forEach((n) => {
        const cx = toCanvasX(n.pos_x);
        const cy = toCanvasY(n.pos_y);

        const isSelected = n.id === props.selectedNodeId;
        ctx.fillStyle = isSelected ? tokens.value.accent : n.color || '#3b82f6';
        ctx.beginPath();
        ctx.arc(cx, cy, isSelected ? 4 : 2.5, 0, Math.PI * 2);
        ctx.fill();
    });

    // Draw Camera Rect
    const camX = toCanvasX(props.cameraPos.x);
    const camY = toCanvasY(props.cameraPos.y);
    const rectW = Math.max(8, (props.cameraPos.view.w / rangeX) * w);
    const rectH = Math.max(8, (props.cameraPos.view.h / rangeY) * h);

    ctx.strokeStyle = tokens.value.accent;
    ctx.lineWidth = 1.5;
    ctx.strokeRect(camX - rectW / 2, camY - rectH / 2, rectW, rectH);
}

function onClickMinimap(event: MouseEvent) {
    const canvas = canvasRef.value;

    if (!canvas || props.nodes.length === 0) {
        return;
    }

    const rect = canvas.getBoundingClientRect();
    const clickX = event.clientX - rect.left;
    const clickY = event.clientY - rect.top;

    let minX = -300,
        maxX = 300,
        minY = -300,
        maxY = 300;
    props.nodes.forEach((n) => {
        minX = Math.min(minX, n.pos_x);
        maxX = Math.max(maxX, n.pos_x);
        minY = Math.min(minY, n.pos_y);
        maxY = Math.max(maxY, n.pos_y);
    });
    const margin = 50;
    minX -= margin;
    maxX += margin;
    minY -= margin;
    maxY += margin;

    const targetX = minX + ((clickX - 8) / (canvas.width - 16)) * (maxX - minX);
    const targetY =
        minY + (1 - (clickY - 8) / (canvas.height - 16)) * (maxY - minY);

    emit('focus-pos', { x: targetX, y: targetY });
}

watch(
    () => [
        props.nodes,
        props.edges,
        props.selectedNodeId,
        props.cameraPos,
        props.settings,
    ],
    () => {
        drawMinimap();
    },
    { deep: true },
);

onMounted(() => {
    drawMinimap();
});
</script>

<template>
    <div
        class="pointer-events-auto absolute right-4 bottom-4 z-20 rounded-2xl border border-slate-700/60 bg-slate-900/85 p-1.5 shadow-2xl backdrop-blur-md"
    >
        <canvas
            ref="canvasRef"
            width="160"
            height="120"
            class="block cursor-crosshair rounded-xl"
            @click="onClickMinimap"
        />
        <div
            class="mt-1 text-center text-[10px] font-medium tracking-wider text-slate-400"
        >
            {{ t.minimapLabel }}
        </div>
    </div>
</template>
