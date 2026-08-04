<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { THEME_TOKENS } from '../types/settings';
import type { AppSettings } from '../types/settings';

export interface NodeData {
    id: number;
    title: string;
    description: string;
    pos_x: number;
    pos_y: number;
    pos_z: number;
    depth: number;
    color: string;
    tags: string;
    shape: NodeShape;
    logo_url: string | null;
    tree_root_id: number | null;
    map_lat: number | null;
    map_lon: number | null;
    map_title: string | null;
    linked_user_id: number | null;
    linked_space_id: number | null;
    // Приходят только из /graph; у только что созданного узла их ещё нет.
    attachments?: AttachmentData[];
    created_at: string;
    updated_at: string;
}

export type NodeShape = 'circle' | 'square' | 'triangle' | 'diamond' | 'hexagon';

/** Файл или ссылка, прикреплённые к узлу. */
export interface AttachmentData {
    id: number;
    kind: 'file' | 'link';
    label: string;
    /** Пусто у загруженных файлов — они отдаются своим маршрутом. */
    url: string | null;
    /** true, если файл лежит в приватном хранилище приложения. */
    stored: boolean;
    badge: string;
    /** Можно открыть во встроенном просмотрщике вместо скачивания. */
    previewable: boolean;
    /** Подмножество previewable — содержимое можно ещё и перезаписать. */
    editable: boolean;
}

export interface EdgeData {
    id: number;
    parent_id: number;
    child_id: number;
}

export interface CameraState {
    x: number;
    y: number;
    scale: number;
    view: { w: number; h: number };
    bounds: { minX: number; maxX: number; minY: number; maxY: number };
}

const props = defineProps<{
    nodes: NodeData[];
    edges: EdgeData[];
    selectedNodeId: number | null;
    hoveredNodeId: number | null;
    filteredNodeIds: Set<number> | null;
    settings: AppSettings;
}>();

const emit = defineEmits<{
    (e: 'select-node', node: NodeData | null): void;
    (e: 'hover-node', node: NodeData | null): void;
    (
        e: 'move-node',
        payload: { id: number; pos_x: number; pos_y: number; pos_z: number },
    ): void;
    (e: 'update-camera', payload: CameraState): void;
}>();

const containerRef = ref<HTMLDivElement | null>(null);
const canvasRef = ref<HTMLCanvasElement | null>(null);

// Размер узла — в мировых координатах, тех же, что pos_x / pos_y.
const BASE_RADIUS = 14;
// Подпись же считается в экранных пикселях: её читаемость не должна зависеть
// от того, насколько мелкими сделаны сами кружки.
const LABEL_FONT = 12;
const LABEL_MIN_FONT = 9;
const LABEL_MAX_FONT = 18;
const LABEL_GAP = 8;
const LABEL_MAX_W = 100;
const LABEL_HIDE_SCALE = 0.3;
const MIN_SCALE = 0.15;
const MAX_SCALE = 3;

const PALETTE = [
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

/** Камера: какая точка мира в центре экрана и во сколько раз всё увеличено. */
const camera = { x: 0, y: 0, scale: 1 };
const cameraTarget = { x: 0, y: 0, scale: 1 };

let animationFrameId = 0;
let needsRedraw = true;
let viewW = 0;
let viewH = 0;

let isPanning = false;
let draggedNodeId: number | null = null;
let dragOffset = { x: 0, y: 0 };
let pointerStart = { x: 0, y: 0 };

function tokens() {
    return THEME_TOKENS[props.settings.theme] ?? THEME_TOKENS.cosmic;
}

function nodeColor(node: NodeData): string {
    if (node.color && node.color.trim() !== '') {
        return node.color;
    }

    // По уровню — как в оргсхемах: каждый ярус своим цветом.
    const key =
        props.settings.colorMode === 'depth'
            ? node.depth
            : (node.tree_root_id ?? node.id);

    return PALETTE[Math.abs(key) % PALETTE.length];
}

/** Чем глубже узел, тем он мельче. */
function nodeRadius(node: NodeData): number {
    return (
        Math.max(8, BASE_RADIUS - node.depth * 0.8) * props.settings.nodeScale
    );
}

/** Единая трассировка контура фигуры узла — для заливки, обводки и свечения. */
function traceNodeShape(
    ctx: CanvasRenderingContext2D,
    cx: number,
    cy: number,
    radius: number,
    shape: NodeShape,
) {
    ctx.beginPath();

    switch (shape) {
        case 'square': {
            const s = radius * Math.SQRT2;
            ctx.rect(cx - s / 2, cy - s / 2, s, s);
            break;
        }
        case 'triangle': {
            const h = radius * 1.3;
            ctx.moveTo(cx, cy - h);
            ctx.lineTo(cx - h * 0.94, cy + h * 0.66);
            ctx.lineTo(cx + h * 0.94, cy + h * 0.66);
            ctx.closePath();
            break;
        }
        case 'diamond':
            ctx.moveTo(cx, cy - radius * 1.25);
            ctx.lineTo(cx + radius * 1.25, cy);
            ctx.lineTo(cx, cy + radius * 1.25);
            ctx.lineTo(cx - radius * 1.25, cy);
            ctx.closePath();
            break;
        case 'hexagon':
            for (let i = 0; i < 6; i++) {
                const angle = (Math.PI / 3) * i - Math.PI / 2;
                const x = cx + radius * 1.1 * Math.cos(angle);
                const y = cy + radius * 1.1 * Math.sin(angle);

                if (i === 0) {
                    ctx.moveTo(x, y);
                } else {
                    ctx.lineTo(x, y);
                }
            }

            ctx.closePath();
            break;
        case 'circle':
        default:
            ctx.arc(cx, cy, radius, 0, Math.PI * 2);
            break;
    }
}

/** Картинки логотипов грузятся асинхронно и кэшируются по URL на весь сеанс сцены. */
const logoCache = new Map<string, HTMLImageElement | 'loading' | 'error'>();

function getLogoImage(url: string): HTMLImageElement | null {
    const cached = logoCache.get(url);

    if (cached instanceof HTMLImageElement) {
        return cached;
    }

    if (cached === undefined) {
        logoCache.set(url, 'loading');
        const img = new Image();

        img.onload = () => {
            logoCache.set(url, img);
            needsRedraw = true;
        };
        img.onerror = () => logoCache.set(url, 'error');
        img.src = url;
    }

    return null;
}

function worldToScreen(wx: number, wy: number) {
    return {
        x: (wx - camera.x) * camera.scale + viewW / 2,
        // В мире Y растёт вверх, на канвасе — вниз.
        y: (camera.y - wy) * camera.scale + viewH / 2,
    };
}

function screenToWorld(sx: number, sy: number) {
    return {
        x: (sx - viewW / 2) / camera.scale + camera.x,
        y: camera.y - (sy - viewH / 2) / camera.scale,
    };
}

function nodeAt(wx: number, wy: number): NodeData | null {
    // С конца: нарисованные поверх перехватывают клик первыми.
    for (let i = props.nodes.length - 1; i >= 0; i--) {
        const n = props.nodes[i];
        // На отдалении кружок меньше пары пикселей, поэтому область попадания
        // не даём ужать ниже разумного минимума.
        const hit = Math.max(nodeRadius(n), 11 / camera.scale);

        if ((wx - n.pos_x) ** 2 + (wy - n.pos_y) ** 2 <= hit ** 2) {
            return n;
        }
    }

    return null;
}

function isDimmed(node: NodeData): boolean {
    return (
        props.filteredNodeIds !== null && !props.filteredNodeIds.has(node.id)
    );
}

function fitCanvas() {
    const canvas = canvasRef.value;
    const container = containerRef.value;

    if (!canvas || !container) {
        return;
    }

    const dpr = Math.min(window.devicePixelRatio || 1, 2);

    viewW = container.clientWidth;
    viewH = container.clientHeight;

    canvas.width = Math.round(viewW * dpr);
    canvas.height = Math.round(viewH * dpr);
    canvas.style.width = `${viewW}px`;
    canvas.style.height = `${viewH}px`;

    canvas.getContext('2d')?.setTransform(dpr, 0, 0, dpr, 0, 0);
    needsRedraw = true;
}

function drawGrid(ctx: CanvasRenderingContext2D) {
    const step = 100 * camera.scale;

    // Слишком мелко — сетка слилась бы в сплошную заливку.
    if (step < 8) {
        return;
    }

    const origin = worldToScreen(0, 0);

    ctx.strokeStyle = tokens().gridMinor;
    ctx.lineWidth = 1;
    ctx.beginPath();

    for (let x = origin.x % step; x < viewW; x += step) {
        ctx.moveTo(Math.round(x) + 0.5, 0);
        ctx.lineTo(Math.round(x) + 0.5, viewH);
    }

    for (let y = origin.y % step; y < viewH; y += step) {
        ctx.moveTo(0, Math.round(y) + 0.5);
        ctx.lineTo(viewW, Math.round(y) + 0.5);
    }

    ctx.stroke();
}

function drawAxes(ctx: CanvasRenderingContext2D) {
    const origin = worldToScreen(0, 0);

    ctx.strokeStyle = tokens().gridMajor;
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(0, origin.y);
    ctx.lineTo(viewW, origin.y);
    ctx.moveTo(origin.x, 0);
    ctx.lineTo(origin.x, viewH);
    ctx.stroke();
}

/**
 * Связь рисуется S-образной кривой: контрольные точки уводятся вдоль той оси,
 * по которой узлы разнесены сильнее. Выходит как на схемах-оргструктурах.
 */
function drawEdge(
    ctx: CanvasRenderingContext2D,
    parent: NodeData,
    child: NodeData,
    color: string,
    alpha: number,
    width: number,
) {
    const dx = child.pos_x - parent.pos_x;
    const dy = child.pos_y - parent.pos_y;
    const len = Math.hypot(dx, dy) || 1;
    const horizontal = Math.abs(dx) > Math.abs(dy);

    // Упираемся в окружность узла, а не в его центр.
    const from = worldToScreen(
        parent.pos_x + (dx / len) * nodeRadius(parent),
        parent.pos_y + (dy / len) * nodeRadius(parent),
    );
    const to = worldToScreen(
        child.pos_x - (dx / len) * nodeRadius(child),
        child.pos_y - (dy / len) * nodeRadius(child),
    );

    ctx.save();
    ctx.globalAlpha = alpha;
    ctx.strokeStyle = color;
    ctx.lineWidth = width;
    ctx.beginPath();
    ctx.moveTo(from.x, from.y);

    if (props.settings.curvedEdges) {
        const mx = (from.x + to.x) / 2;
        const my = (from.y + to.y) / 2;

        if (horizontal) {
            ctx.bezierCurveTo(mx, from.y, mx, to.y, to.x, to.y);
        } else {
            ctx.bezierCurveTo(from.x, my, to.x, my, to.x, to.y);
        }
    } else {
        ctx.lineTo(to.x, to.y);
    }

    ctx.stroke();

    // Стрелка: граф направленный, без неё не видно, кто кому родитель.
    // У кривой касательная на конце идёт вдоль главной оси, у прямой — вдоль неё самой.
    const head = 7;
    const angle = props.settings.curvedEdges
        ? horizontal
            ? dx < 0
                ? Math.PI
                : 0
            : to.y < from.y
              ? -Math.PI / 2
              : Math.PI / 2
        : Math.atan2(to.y - from.y, to.x - from.x);

    ctx.beginPath();
    ctx.moveTo(to.x, to.y);
    ctx.lineTo(
        to.x - head * Math.cos(angle - Math.PI / 7),
        to.y - head * Math.sin(angle - Math.PI / 7),
    );
    ctx.lineTo(
        to.x - head * Math.cos(angle + Math.PI / 7),
        to.y - head * Math.sin(angle + Math.PI / 7),
    );
    ctx.closePath();
    ctx.fillStyle = color;
    ctx.fill();
    ctx.restore();
}

/** Подпись в рамке над узлом — так же, как было в прежнем виде. */
function drawLabel(
    ctx: CanvasRenderingContext2D,
    node: NodeData,
    color: string,
    alpha: number,
) {
    // Прячем подписи только когда действительно далеко отъехали, а не когда
    // пользователь уменьшил узлы: это независимые вещи.
    if (camera.scale < LABEL_HIDE_SCALE) {
        return;
    }

    const fontPx = Math.max(
        LABEL_MIN_FONT,
        Math.min(LABEL_MAX_FONT, LABEL_FONT * camera.scale),
    );

    const theme = tokens();
    const center = worldToScreen(node.pos_x, node.pos_y);
    const radius = nodeRadius(node) * camera.scale;

    ctx.save();
    ctx.globalAlpha = alpha;
    ctx.font = `600 ${fontPx}px Instrument Sans, system-ui, sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    let text = node.title || `#${node.id}`;
    const padX = fontPx * 0.6;
    // Обрезаем по внутренней ширине: с отступами рамка должна уложиться в
    // LABEL_MAX_W, иначе подписи соседних узлов начнут задевать друг друга.
    const maxWidth = Math.max(90, LABEL_MAX_W * camera.scale) - padX * 2;

    if (ctx.measureText(text).width > maxWidth) {
        while (
            text.length > 1 &&
            ctx.measureText(`${text}…`).width > maxWidth
        ) {
            text = text.slice(0, -1);
        }

        text += '…';
    }

    const boxW = ctx.measureText(text).width + padX * 2;
    const boxH = fontPx * 1.8;
    const boxX = center.x - boxW / 2;
    const boxY = center.y - radius - LABEL_GAP - boxH;

    ctx.beginPath();
    ctx.roundRect(boxX, boxY, boxW, boxH, boxH * 0.35);
    ctx.fillStyle = theme.labelBg;
    ctx.fill();
    ctx.lineWidth = Math.max(1, fontPx * 0.14);
    ctx.strokeStyle = color;
    ctx.stroke();

    ctx.fillStyle = theme.labelText;
    ctx.fillText(text, center.x, boxY + boxH / 2);
    ctx.restore();
}

function drawNode(ctx: CanvasRenderingContext2D, node: NodeData) {
    const center = worldToScreen(node.pos_x, node.pos_y);
    const radius = nodeRadius(node) * camera.scale;
    const shape = node.shape || 'circle';

    const selected = props.selectedNodeId === node.id;
    const hovered = props.hoveredNodeId === node.id;
    const color = nodeColor(node);
    const theme = tokens();
    const alpha = isDimmed(node) ? 0.2 : 1;

    ctx.save();
    ctx.globalAlpha = alpha;

    // Свечение выделенного и подсвеченного узла.
    if (selected || hovered) {
        traceNodeShape(ctx, center.x, center.y, radius * 1.55, shape);
        ctx.fillStyle = selected ? theme.accent : color;
        ctx.globalAlpha = alpha * (selected ? 0.28 : 0.18);
        ctx.fill();
        ctx.globalAlpha = alpha;
    }

    traceNodeShape(ctx, center.x, center.y, radius, shape);
    ctx.fillStyle = color;
    ctx.fill();

    // Логотип рисуется поверх заливки, обрезанный по контуру фигуры — пока
    // картинка не загрузилась, виден просто цветной фон.
    const logo = node.logo_url ? getLogoImage(node.logo_url) : null;

    if (logo) {
        ctx.save();
        traceNodeShape(ctx, center.x, center.y, radius, shape);
        ctx.clip();
        ctx.drawImage(
            logo,
            center.x - radius,
            center.y - radius,
            radius * 2,
            radius * 2,
        );
        ctx.restore();
    }

    traceNodeShape(ctx, center.x, center.y, radius, shape);
    ctx.lineWidth = selected ? 2.5 : hovered ? 2 : 1;
    ctx.strokeStyle = selected ? theme.accent : theme.labelBg;
    ctx.stroke();
    ctx.restore();

    if (props.settings.showNodeLabels) {
        drawLabel(ctx, node, color, alpha);
    }
}

function draw() {
    const ctx = canvasRef.value?.getContext('2d');

    if (!ctx) {
        return;
    }

    const theme = tokens();

    ctx.fillStyle = theme.canvasBg;
    ctx.fillRect(0, 0, viewW, viewH);

    if (props.settings.showGrid) {
        drawGrid(ctx);
    }

    if (props.settings.showAxes) {
        drawAxes(ctx);
    }

    const nodeMap = new Map(props.nodes.map((n) => [n.id, n]));
    const focusId = props.selectedNodeId ?? props.hoveredNodeId;

    props.edges.forEach((edge) => {
        const parent = nodeMap.get(edge.parent_id);
        const child = nodeMap.get(edge.child_id);

        if (!parent || !child) {
            return;
        }

        const connected =
            focusId !== null &&
            (edge.parent_id === focusId || edge.child_id === focusId);
        const dimmed = isDimmed(parent) || isDimmed(child);

        drawEdge(
            ctx,
            parent,
            child,
            connected ? theme.accent : nodeColor(parent),
            dimmed ? 0.1 : connected ? 1 : focusId !== null ? 0.18 : 0.55,
            connected ? 2.5 : 1.5,
        );
    });

    props.nodes.forEach((node) => drawNode(ctx, node));
}

function reportCamera() {
    const xs = props.nodes.map((n) => n.pos_x);
    const ys = props.nodes.map((n) => n.pos_y);

    emit('update-camera', {
        x: camera.x,
        y: camera.y,
        scale: camera.scale,
        view: { w: viewW / camera.scale, h: viewH / camera.scale },
        bounds: {
            minX: xs.length ? Math.min(...xs) : 0,
            maxX: xs.length ? Math.max(...xs) : 0,
            minY: ys.length ? Math.min(...ys) : 0,
            maxY: ys.length ? Math.max(...ys) : 0,
        },
    });
}

function tick() {
    animationFrameId = requestAnimationFrame(tick);

    const ease = props.settings.reduceMotion ? 1 : 0.18;
    const dx = cameraTarget.x - camera.x;
    const dy = cameraTarget.y - camera.y;
    const ds = cameraTarget.scale - camera.scale;

    if (Math.abs(dx) > 0.01 || Math.abs(dy) > 0.01 || Math.abs(ds) > 0.0001) {
        camera.x += dx * ease;
        camera.y += dy * ease;
        camera.scale += ds * ease;
        needsRedraw = true;
        reportCamera();
    }

    // Перерисовываем только когда что-то изменилось, а не каждый кадр.
    if (needsRedraw) {
        needsRedraw = false;
        draw();
    }
}

function onPointerDown(event: PointerEvent) {
    const rect = canvasRef.value?.getBoundingClientRect();

    if (!rect) {
        return;
    }

    const world = screenToWorld(
        event.clientX - rect.left,
        event.clientY - rect.top,
    );
    const node = nodeAt(world.x, world.y);

    if (node) {
        emit('select-node', node);
        draggedNodeId = node.id;
        dragOffset = { x: world.x - node.pos_x, y: world.y - node.pos_y };
    } else {
        emit('select-node', null);
        isPanning = true;
        pointerStart = { x: event.clientX, y: event.clientY };
    }

    (event.target as HTMLElement).setPointerCapture?.(event.pointerId);
}

function onPointerMove(event: PointerEvent) {
    const rect = canvasRef.value?.getBoundingClientRect();

    if (!rect) {
        return;
    }

    if (isPanning) {
        cameraTarget.x -= (event.clientX - pointerStart.x) / camera.scale;
        cameraTarget.y += (event.clientY - pointerStart.y) / camera.scale;
        pointerStart = { x: event.clientX, y: event.clientY };

        return;
    }

    const world = screenToWorld(
        event.clientX - rect.left,
        event.clientY - rect.top,
    );

    if (draggedNodeId !== null) {
        const node = props.nodes.find((n) => n.id === draggedNodeId);

        if (node) {
            node.pos_x = Math.round(world.x - dragOffset.x);
            node.pos_y = Math.round(world.y - dragOffset.y);
            needsRedraw = true;
        }

        return;
    }

    const hovered = nodeAt(world.x, world.y);

    emit('hover-node', hovered);

    if (containerRef.value) {
        containerRef.value.style.cursor = hovered ? 'pointer' : 'default';
    }
}

function onPointerUp() {
    if (draggedNodeId !== null) {
        const node = props.nodes.find((n) => n.id === draggedNodeId);

        if (node) {
            emit('move-node', {
                id: node.id,
                pos_x: node.pos_x,
                pos_y: node.pos_y,
                pos_z: 0,
            });
        }
    }

    isPanning = false;
    draggedNodeId = null;
}

function onWheel(event: WheelEvent) {
    event.preventDefault();

    const rect = canvasRef.value?.getBoundingClientRect();

    if (!rect) {
        return;
    }

    // Приближаем к курсору, а не к центру экрана.
    const anchor = screenToWorld(
        event.clientX - rect.left,
        event.clientY - rect.top,
    );
    const next = Math.min(
        MAX_SCALE,
        Math.max(
            MIN_SCALE,
            cameraTarget.scale * (event.deltaY > 0 ? 0.9 : 1.1),
        ),
    );
    const ratio = 1 - cameraTarget.scale / next;

    cameraTarget.x += (anchor.x - cameraTarget.x) * ratio;
    cameraTarget.y += (anchor.y - cameraTarget.y) * ratio;
    cameraTarget.scale = next;
}

function focusNode(nodeId: number) {
    const node = props.nodes.find((n) => n.id === nodeId);

    if (!node) {
        return;
    }

    cameraTarget.x = node.pos_x;
    cameraTarget.y = node.pos_y;
    cameraTarget.scale = Math.max(cameraTarget.scale, 0.8);
}

function focusPos(pos: { x: number; y: number }) {
    cameraTarget.x = pos.x;
    cameraTarget.y = pos.y;
}

/** Вписывает весь граф в экран. */
function fitToContent() {
    if (props.nodes.length === 0 || viewW === 0) {
        return;
    }

    const xs = props.nodes.map((n) => n.pos_x);
    const ys = props.nodes.map((n) => n.pos_y);
    const margin = BASE_RADIUS * props.settings.nodeScale * 6;
    const spanX = Math.max(...xs) - Math.min(...xs) + margin;
    const spanY = Math.max(...ys) - Math.min(...ys) + margin;

    cameraTarget.x = (Math.min(...xs) + Math.max(...xs)) / 2;
    cameraTarget.y = (Math.min(...ys) + Math.max(...ys)) / 2;
    cameraTarget.scale = Math.min(
        MAX_SCALE,
        Math.max(MIN_SCALE, Math.min(viewW / spanX, viewH / spanY)),
    );
}

defineExpose({ focusNode, focusPos, fitToContent });

watch(
    () => [
        props.nodes,
        props.edges,
        props.selectedNodeId,
        props.hoveredNodeId,
        props.filteredNodeIds,
        props.settings,
    ],
    () => {
        needsRedraw = true;
    },
    { deep: true },
);

onMounted(() => {
    fitCanvas();
    window.addEventListener('resize', fitCanvas);
    tick();
    reportCamera();
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', fitCanvas);
    cancelAnimationFrame(animationFrameId);
});
</script>

<template>
    <div ref="containerRef" class="relative h-full w-full overflow-hidden">
        <canvas
            ref="canvasRef"
            class="block touch-none select-none"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @wheel="onWheel"
        />
    </div>
</template>
