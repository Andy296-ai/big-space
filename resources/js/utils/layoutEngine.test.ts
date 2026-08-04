import { describe, expect, it } from 'vitest';
import type { LayoutMode } from '../types/settings';
import { computeLayout } from './layoutEngine';
import type { LayoutEdge, LayoutNode } from './layoutEngine';

// Root (1) -> children (2, 3) -> grandchild (4) under node 2.
const nodes: LayoutNode[] = [
    { id: 1, depth: 0, tree_root_id: 1, tags: 'origin' },
    { id: 2, depth: 1, tree_root_id: 1 },
    { id: 3, depth: 1, tree_root_id: 1 },
    { id: 4, depth: 2, tree_root_id: 1 },
];

const edges: LayoutEdge[] = [
    { parent_id: 1, child_id: 2 },
    { parent_id: 1, child_id: 3 },
    { parent_id: 2, child_id: 4 },
];

const ALL_MODES: LayoutMode[] = [
    'hierarchy',
    'spiral',
    'rings',
    'grid',
    'radial',
    'layered',
    'cluster',
    'force',
];

describe('computeLayout', () => {
    it.each(ALL_MODES)(
        'places every node with finite coordinates (%s)',
        (mode) => {
            const positions = computeLayout(mode, nodes, edges);

            expect(positions.size).toBe(nodes.length);

            for (const node of nodes) {
                const pos = positions.get(node.id);

                expect(pos).toBeDefined();
                expect(Number.isFinite(pos!.x)).toBe(true);
                expect(Number.isFinite(pos!.y)).toBe(true);
                expect(Number.isFinite(pos!.z)).toBe(true);
            }
        },
    );

    it('returns an empty map for an empty graph without throwing', () => {
        for (const mode of ALL_MODES) {
            expect(computeLayout(mode, [], []).size).toBe(0);
        }
    });

    it('falls back to a spiral layout for an unknown mode', () => {
        const fallback = computeLayout(
            'not-a-real-mode' as LayoutMode,
            nodes,
            edges,
        );
        const spiral = computeLayout('spiral', nodes, edges);

        expect(fallback).toEqual(spiral);
    });

    it('hierarchy layout responds to the requested direction', () => {
        const down = computeLayout('hierarchy', nodes, edges, 'down');
        const up = computeLayout('hierarchy', nodes, edges, 'up');

        // Every non-root node should move: "down" grows -y, "up" grows +y.
        for (const node of nodes.slice(1)) {
            expect(down.get(node.id)!.y).not.toBe(up.get(node.id)!.y);
        }
    });

    it('hierarchy layout keeps the root at the origin', () => {
        const positions = computeLayout('hierarchy', nodes, edges, 'down');
        const root = positions.get(1)!;

        // toBeCloseTo (not toBe): -0 is a valid "at the origin" result here.
        expect(root.x).toBeCloseTo(0);
        expect(root.y).toBeCloseTo(0);
    });
});
