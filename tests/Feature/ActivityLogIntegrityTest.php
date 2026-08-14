<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('an intact chain verifies clean', function () {
    $user = User::factory()->create();

    ActivityLog::record($user, ActivityLog::ACTION_NODE_CREATED, 'node', 1, ['title' => 'A']);
    ActivityLog::record($user, ActivityLog::ACTION_NODE_CREATED, 'node', 2, ['title' => 'B']);
    ActivityLog::record(null, ActivityLog::ACTION_NODE_DELETED, 'node', 1, ['title' => 'A']);

    expect(ActivityLog::verifyChain())->toBeNull();
});

test('a row edited directly in the database is caught by verifyChain', function () {
    $user = User::factory()->create();

    ActivityLog::record($user, ActivityLog::ACTION_NODE_CREATED, 'node', 1, ['title' => 'A']);
    $tampered = ActivityLog::record($user, ActivityLog::ACTION_NODE_CREATED, 'node', 2, ['title' => 'B']);
    ActivityLog::record(null, ActivityLog::ACTION_NODE_DELETED, 'node', 1, ['title' => 'A']);

    // Правка мимо модели — единственный реалистичный вектор подмены задним
    // числом, ровно то, от чего должна защищать цепочка.
    DB::table('activity_logs')->where('id', $tampered->id)->update(['subject_id' => 999]);

    $broken = ActivityLog::verifyChain();

    expect($broken)->not->toBeNull();
    expect($broken->id)->toBe($tampered->id);
});

test('the model refuses to update or delete a row through Eloquent', function () {
    $entry = ActivityLog::record(null, ActivityLog::ACTION_NODE_CREATED, 'node', 1, []);

    expect(fn () => $entry->update(['action' => 'node.deleted']))
        ->toThrow(RuntimeException::class);

    expect(fn () => $entry->delete())
        ->toThrow(RuntimeException::class);
});

test('pruning old rows does not break verification of what remains', function () {
    $old = ActivityLog::record(null, ActivityLog::ACTION_NODE_CREATED, 'node', 1, []);
    ActivityLog::record(null, ActivityLog::ACTION_NODE_CREATED, 'node', 2, []);
    ActivityLog::record(null, ActivityLog::ACTION_NODE_CREATED, 'node', 3, []);

    // Ровно то, что делает activity-log:prune: сырое удаление старой записи
    // через query builder, а не через модель.
    DB::table('activity_logs')->where('id', $old->id)->delete();

    expect(ActivityLog::verifyChain())->toBeNull();
});
