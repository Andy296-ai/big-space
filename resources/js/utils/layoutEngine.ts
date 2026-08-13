import type { LayoutDirection, LayoutMode } from '../types/settings';

export interface NodePosition {
    x: number;
    y: number;
    z: number;
}

export interface LayoutNode {
    id: number;
    depth: number;
    tree_root_id: number | null;
    tags?: string;
    pos_x?: number;
    pos_y?: number;
}

export interface LayoutEdge {
    parent_id: number;
    child_id: number;
}

const GOLDEN_ANGLE = 2.399963229728653;

export function computeLayout(
    mode: LayoutMode,
    nodes: LayoutNode[],
    edges: LayoutEdge[],
    direction: LayoutDirection = 'down',
): Map<number, NodePosition> {
    switch (mode) {
        case 'hierarchy':
            return layoutHierarchy(nodes, edges, direction);
        case 'spiral':
            return layoutSpiral(nodes);
        case 'rings':
            return layoutRings(nodes);
        case 'grid':
            return layoutGrid(nodes);
        case 'radial':
            return layoutRadial(nodes, edges);
        case 'layered':
            return layoutLayered(nodes, direction);
        case 'cluster':
            return layoutCluster(nodes);
        case 'force':
            return layoutForce(nodes, edges);
        default:
            return layoutSpiral(nodes);
    }
}

const LEVEL_GAP = 150;
const ACROSS_GAP = 110;

/**
 * Переводит (уровень, позиция поперёк) в координаты сцены. Ось Y в Three.js
 * смотрит вверх, поэтому «сверху вниз» — это движение в минус по Y.
 */
function toScenePosition(
    level: number,
    across: number,
    direction: LayoutDirection,
): { x: number; y: number } {
    const along = level * LEVEL_GAP;
    const side = across * ACROSS_GAP;

    switch (direction) {
        case 'up':
            return { x: side, y: along };
        case 'right':
            return { x: along, y: -side };
        case 'left':
            return { x: -along, y: -side };
        default:
            return { x: side, y: -along };
    }
}

/**
 * Корни, у которых сейчас совпадают текущие координаты (типично — только что
 * созданные, ещё ни разу не перетащенные, все на (0,0)), расставляются рядом
 * друг с другом, как раньше. Корни с уже различающимися координатами не трогаем —
 * именно они не обязаны лежать на одной оси X/Y.
 */
function resolveRootAnchors(
    roots: LayoutNode[],
    direction: LayoutDirection,
): Map<number, { x: number; y: number }> {
    const anchors = new Map<number, { x: number; y: number }>();
    const groups = new Map<string, LayoutNode[]>();

    roots.forEach((root) => {
        const x = root.pos_x ?? 0;
        const y = root.pos_y ?? 0;
        const key = `${Math.round(x)}:${Math.round(y)}`;

        if (!groups.has(key)) {
            groups.set(key, []);
        }

        groups.get(key)!.push(root);
    });

    groups.forEach((group) => {
        if (group.length === 1) {
            const r = group[0];
            anchors.set(r.id, { x: r.pos_x ?? 0, y: r.pos_y ?? 0 });

            return;
        }

        const base = { x: group[0].pos_x ?? 0, y: group[0].pos_y ?? 0 };
        let cursor = 0;
        group.forEach((r) => {
            const { x, y } = toScenePosition(0, cursor, direction);
            anchors.set(r.id, { x: base.x + x, y: base.y + y });
            cursor += 1;
        });
    });

    return anchors;
}

/**
 * Аккуратное дерево: дети выкладываются подряд, родитель центрируется над
 * своей группой, поддеревья не наезжают друг на друга. В отличие от «слоёв»,
 * где узлы одного уровня просто выстраивались в ряд по порядку id.
 *
 * У узла с несколькими родителями рисуется только первая связь — иначе один
 * узел пришлось бы поставить в два места сразу.
 *
 * Каждое дерево раскладывается независимо и привязывается к ТЕКУЩИМ
 * координатам своего корня — так ручная расстановка деревьев (например,
 * перетаскиванием правой кнопкой) не сбрасывается кнопкой «Авто-организация».
 */
function layoutHierarchy(
    nodes: LayoutNode[],
    edges: LayoutEdge[],
    direction: LayoutDirection,
): Map<number, NodePosition> {
    const positions = new Map<number, NodePosition>();

    const primaryParent = new Map<number, number>();
    edges.forEach((e) => {
        if (!primaryParent.has(e.child_id)) {
            primaryParent.set(e.child_id, e.parent_id);
        }
    });

    const treeChildren = new Map<number, number[]>();
    edges.forEach((e) => {
        if (primaryParent.get(e.child_id) !== e.parent_id) {
            return;
        }

        if (!treeChildren.has(e.parent_id)) {
            treeChildren.set(e.parent_id, []);
        }

        treeChildren.get(e.parent_id)!.push(e.child_id);
    });

    const roots = nodes.filter((n) => !primaryParent.has(n.id));
    const rootAnchors = resolveRootAnchors(roots, direction);
    const visited = new Set<number>();

    roots.forEach((root) => {
        const levelOf = new Map<number, number>();
        const acrossOf = new Map<number, number>();
        let nextSlot = 0;

        function place(id: number, level: number): number {
            if (acrossOf.has(id)) {
                return acrossOf.get(id)!;
            }

            visited.add(id);
            levelOf.set(id, level);

            const kids = treeChildren.get(id) ?? [];

            if (kids.length === 0) {
                const slot = nextSlot++;
                acrossOf.set(id, slot);

                return slot;
            }

            const kidSlots = kids.map((kid) => place(kid, level + 1));
            const center = (kidSlots[0] + kidSlots[kidSlots.length - 1]) / 2;
            acrossOf.set(id, center);

            return center;
        }

        place(root.id, 0);

        // acrossOf текущего корня — всегда локальный центр его собственного
        // поддерева (доказывается индукцией по place()), отдельный проход за
        // min/max не нужен.
        const rootMiddle = acrossOf.get(root.id) ?? 0;
        const anchor = rootAnchors.get(root.id)!;

        acrossOf.forEach((across, id) => {
            const { x, y } = toScenePosition(
                levelOf.get(id) ?? 0,
                across - rootMiddle,
                direction,
            );
            positions.set(id, {
                x: Math.round(x + anchor.x),
                y: Math.round(y + anchor.y),
                z: 0,
            });
        });
    });

    // Узлы, до которых не добрались (например, замкнутые в цикл) — оставляем
    // на месте, а не выдумываем им позицию на уже несуществующей общей полосе.
    nodes.forEach((n) => {
        if (!visited.has(n.id)) {
            positions.set(n.id, {
                x: Math.round(n.pos_x ?? 0),
                y: Math.round(n.pos_y ?? 0),
                z: 0,
            });
        }
    });

    return positions;
}

function layoutSpiral(nodes: LayoutNode[]): Map<number, NodePosition> {
    const positions = new Map<number, NodePosition>();
    const sorted = [...nodes].sort((a, b) => a.depth - b.depth || a.id - b.id);

    sorted.forEach((node, i) => {
        const angle = i * GOLDEN_ANGLE;
        const radius = 90 * Math.sqrt(i + 1);
        positions.set(node.id, {
            x: Math.round(radius * Math.cos(angle)),
            y: Math.round(radius * Math.sin(angle)),
            z: 0,
        });
    });

    return positions;
}

function layoutRings(nodes: LayoutNode[]): Map<number, NodePosition> {
    const positions = new Map<number, NodePosition>();
    const byDepth = groupBy(nodes, (n) => n.depth);

    byDepth.forEach((group, depth) => {
        // Нулевой уровень — в центре, остальные расходятся кольцами.
        if (depth === 0 && group.length === 1) {
            positions.set(group[0].id, { x: 0, y: 0, z: 0 });

            return;
        }

        const radius = 120 + depth * 130;

        group.forEach((node, i) => {
            const angle = (i / group.length) * Math.PI * 2 - Math.PI / 2;
            positions.set(node.id, {
                x: Math.round(radius * Math.cos(angle)),
                y: Math.round(radius * Math.sin(angle)),
                z: 0,
            });
        });
    });

    return positions;
}

function layoutGrid(nodes: LayoutNode[]): Map<number, NodePosition> {
    const positions = new Map<number, NodePosition>();
    const cols = Math.max(1, Math.ceil(Math.sqrt(nodes.length)));
    const gapX = 170;
    const gapY = 90;

    nodes.forEach((node, i) => {
        const col = i % cols;
        const row = Math.floor(i / cols);
        positions.set(node.id, {
            x: Math.round((col - (cols - 1) / 2) * gapX),
            y: Math.round(-row * gapY),
            z: 0,
        });
    });

    return positions;
}

function layoutRadial(
    nodes: LayoutNode[],
    edges: LayoutEdge[],
): Map<number, NodePosition> {
    const positions = new Map<number, NodePosition>();
    const childrenMap = buildChildrenMap(edges);
    const roots = findRoots(nodes, edges);

    roots.forEach((root, rootIdx) => {
        const offsetX = (rootIdx - (roots.length - 1) / 2) * 350;
        positions.set(root.id, { x: Math.round(offsetX), y: 0, z: 0 });
        placeRadialSubtree(
            root.id,
            0,
            offsetX,
            0,
            childrenMap,
            nodes,
            positions,
        );
    });

    nodes.forEach((n) => {
        if (!positions.has(n.id)) {
            positions.set(n.id, { x: 0, y: n.depth * 80, z: 0 });
        }
    });

    return positions;
}

function placeRadialSubtree(
    nodeId: number,
    depth: number,
    cx: number,
    cy: number,
    childrenMap: Map<number, number[]>,
    nodes: LayoutNode[],
    positions: Map<number, NodePosition>,
): void {
    const children = childrenMap.get(nodeId) ?? [];
    const radius = 70 + depth * 55;
    const angleSpan = Math.min(
        Math.PI * 1.6,
        Math.PI / 3 + children.length * 0.45,
    );
    const startAngle = -angleSpan / 2;

    children.forEach((childId, i) => {
        const angle =
            children.length === 1
                ? 0
                : startAngle + (angleSpan * i) / (children.length - 1);
        const x = cx + radius * Math.cos(angle - Math.PI / 2);
        const y = cy + radius * Math.sin(angle - Math.PI / 2);
        positions.set(childId, {
            x: Math.round(x),
            y: Math.round(y),
            z: 0,
        });
        placeRadialSubtree(
            childId,
            depth + 1,
            x,
            y,
            childrenMap,
            nodes,
            positions,
        );
    });
}

function layoutLayered(
    nodes: LayoutNode[],
    direction: LayoutDirection,
): Map<number, NodePosition> {
    const positions = new Map<number, NodePosition>();
    const byDepth = groupBy(nodes, (n) => n.depth);

    byDepth.forEach((group, depth) => {
        const offset = (group.length - 1) / 2;
        group.forEach((node, i) => {
            const { x, y } = toScenePosition(depth, i - offset, direction);
            positions.set(node.id, {
                x: Math.round(x),
                y: Math.round(y),
                z: 0,
            });
        });
    });

    return positions;
}

function layoutCluster(nodes: LayoutNode[]): Map<number, NodePosition> {
    const positions = new Map<number, NodePosition>();
    const byRoot = groupBy(nodes, (n) => n.tree_root_id ?? n.id);
    const clusterKeys = Array.from(byRoot.keys());
    const clusterRadius = 280;

    clusterKeys.forEach((rootId, clusterIdx) => {
        const group = byRoot.get(rootId)!;
        const angle = (clusterIdx / clusterKeys.length) * Math.PI * 2;
        const cx = clusterRadius * Math.cos(angle);
        const cy = clusterRadius * Math.sin(angle);

        group.forEach((node, i) => {
            const localAngle = i * GOLDEN_ANGLE;
            const localR = 40 + Math.sqrt(i + 1) * 35;
            positions.set(node.id, {
                x: Math.round(cx + localR * Math.cos(localAngle)),
                y: Math.round(cy + localR * Math.sin(localAngle)),
                z: 0,
            });
        });
    });

    return positions;
}

function layoutForce(
    nodes: LayoutNode[],
    edges: LayoutEdge[],
): Map<number, NodePosition> {
    const positions = new Map<number, NodePosition>();
    const nodePositions = new Map<number, { x: number; y: number }>();

    nodes.forEach((n, i) => {
        const angle = i * GOLDEN_ANGLE;
        nodePositions.set(n.id, {
            x: 80 * Math.cos(angle),
            y: 80 * Math.sin(angle),
        });
    });

    const k = 140;
    const iterations = 80;

    for (let iter = 0; iter < iterations; iter++) {
        const disp = new Map(nodes.map((n) => [n.id, { x: 0, y: 0 }]));

        for (let i = 0; i < nodes.length; i++) {
            for (let j = i + 1; j < nodes.length; j++) {
                const u = nodes[i].id;
                const v = nodes[j].id;
                const posU = nodePositions.get(u)!;
                const posV = nodePositions.get(v)!;

                const dx = posU.x - posV.x;
                const dy = posU.y - posV.y;
                const dist = Math.sqrt(dx * dx + dy * dy) || 1;

                if (dist < 500) {
                    const force = (k * k) / dist;
                    const fx = (dx / dist) * force;
                    const fy = (dy / dist) * force;
                    disp.get(u)!.x += fx;
                    disp.get(u)!.y += fy;
                    disp.get(v)!.x -= fx;
                    disp.get(v)!.y -= fy;
                }
            }
        }

        edges.forEach((e) => {
            const posU = nodePositions.get(e.parent_id);
            const posV = nodePositions.get(e.child_id);

            if (!posU || !posV) {
                return;
            }

            const dx = posU.x - posV.x;
            const dy = posU.y - posV.y;
            const dist = Math.sqrt(dx * dx + dy * dy) || 1;
            const force = (dist * dist) / k;
            const fx = (dx / dist) * force;
            const fy = (dy / dist) * force;

            disp.get(e.parent_id)!.x -= fx;
            disp.get(e.parent_id)!.y -= fy;
            disp.get(e.child_id)!.x += fx;
            disp.get(e.child_id)!.y += fy;
        });

        nodes.forEach((n) => {
            const pos = nodePositions.get(n.id)!;
            const d = disp.get(n.id)!;
            const dist = Math.sqrt(d.x * d.x + d.y * d.y) || 1;
            const step = Math.min(dist, 25);
            pos.x += (d.x / dist) * step;
            pos.y += (d.y / dist) * step;
        });
    }

    nodes.forEach((n) => {
        const pos = nodePositions.get(n.id)!;
        positions.set(n.id, {
            x: Math.round(pos.x),
            y: Math.round(pos.y),
            z: 0,
        });
    });

    return positions;
}

function groupBy<T, K>(items: T[], keyFn: (item: T) => K): Map<K, T[]> {
    const map = new Map<K, T[]>();
    items.forEach((item) => {
        const key = keyFn(item);

        if (!map.has(key)) {
            map.set(key, []);
        }

        map.get(key)!.push(item);
    });

    return map;
}

function buildChildrenMap(edges: LayoutEdge[]): Map<number, number[]> {
    const map = new Map<number, number[]>();
    edges.forEach((e) => {
        if (!map.has(e.parent_id)) {
            map.set(e.parent_id, []);
        }

        map.get(e.parent_id)!.push(e.child_id);
    });

    return map;
}

function findRoots(nodes: LayoutNode[], edges: LayoutEdge[]): LayoutNode[] {
    const childIds = new Set(edges.map((e) => e.child_id));
    const roots = nodes.filter((n) => !childIds.has(n.id));

    return roots.length > 0 ? roots : nodes.filter((n) => n.depth === 0);
}
