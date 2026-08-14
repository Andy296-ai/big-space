<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\NodeCommentController;
use App\Http\Controllers\NodeTreeSettingsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SpaceActivityLogController;
use App\Http\Controllers\SpaceCollaboratorController;
use App\Http\Controllers\SpaceController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
    Route::post('/login/verify-code', [AuthController::class, 'verifyCode']);
    Route::post('/login/resend-code', [AuthController::class, 'resendCode']);
    Route::post('/login/cancel', [AuthController::class, 'cancel']);
});

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [GraphController::class, 'index'])->name('home');

    Route::prefix('api')->group(function () {
        // Spaces: список и создание видят всех пространств пользователя, а не чужих.
        Route::get('/spaces', [SpaceController::class, 'index']);
        Route::post('/spaces', [SpaceController::class, 'store']);
        Route::post('/spaces/import', [SpaceController::class, 'import']);
        Route::get('/search', [SearchController::class, 'index'])->middleware('throttle:search');

        // Уведомления пользователя — доступ открыт в пространстве, кто-то
        // ответил на комментарий и т.п. Не привязаны к конкретному пространству.
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        // Пользователи — узлы в Admin-пространстве; управляет только root.
        Route::middleware('throttle:admin-users')->group(function () {
            Route::post('/admin/users', [UserController::class, 'store']);
            Route::put('/admin/users/{user}/password', [UserController::class, 'updatePassword']);
            Route::delete('/admin/users/{user}', [UserController::class, 'destroy']);
        });
        Route::get('/admin/activity-log', [ActivityLogController::class, 'index']);
        Route::get('/admin/activity-log/verify', [ActivityLogController::class, 'verify']);

        // Всё, что привязано к конкретному пространству: владелец, root, или участник шеринга.
        Route::middleware('can:access,space')->prefix('spaces/{space}')->group(function () {
            // Только чтение — доступно viewer'ам наравне с editor'ами.
            Route::get('/export', [SpaceController::class, 'export'])->middleware('throttle:export');
            Route::get('/graph', [GraphController::class, 'fetchGraph']);
            Route::get('/nodes/{node}/logo', [GraphController::class, 'logo']);
            Route::get('/attachments/search', [AttachmentController::class, 'search'])->middleware('throttle:search');
            Route::get('/nodes/{node}/attachments/{attachment}/download', [AttachmentController::class, 'download']);
            Route::get('/nodes/{node}/attachments/{attachment}/preview', [AttachmentController::class, 'preview']);
            Route::get('/nodes/{node}/attachments/{attachment}/content', [AttachmentController::class, 'content']);
            Route::get('/nodes/{node}/deletion-preview', [GraphController::class, 'computeDeletion']);
            Route::get('/nodes/{node}/revisions', [GraphController::class, 'nodeRevisions']);
            Route::post('/nodes/{node}/viewed', [GraphController::class, 'nodeViewed']);

            // Комментарии — не привязаны к роли editor, viewer тоже может обсуждать.
            Route::get('/nodes/{node}/comments', [NodeCommentController::class, 'index']);
            Route::post('/nodes/{node}/comments', [NodeCommentController::class, 'store']);
            Route::delete('/nodes/{node}/comments/{comment}', [NodeCommentController::class, 'destroy']);

            // Меняет граф — нужна роль editor (или владелец/root).
            Route::middleware('can:edit,space')->group(function () {
                Route::put('/structure', [SpaceController::class, 'updateStructure']);

                Route::post('/nodes/root', [GraphController::class, 'createRoot']);
                Route::post('/nodes/{parent}/child', [GraphController::class, 'addChild']);
                Route::post('/nodes/{node}/copy', [GraphController::class, 'copy']);
                Route::put('/nodes/bulk-move', [GraphController::class, 'bulkMove']);
                Route::put('/nodes/{node}/move', [GraphController::class, 'move']);
                Route::put('/nodes/{node}', [GraphController::class, 'update']);
                Route::put('/nodes/{node}/tree-settings', [NodeTreeSettingsController::class, 'update']);
                Route::post('/nodes/{node}/logo', [GraphController::class, 'uploadLogo']);

                Route::post('/nodes/{node}/attachments', [AttachmentController::class, 'store'])->middleware('throttle:uploads');
                Route::put('/nodes/{node}/attachments/{attachment}/content', [AttachmentController::class, 'updateContent']);
                Route::delete('/nodes/{node}/attachments/{attachment}', [AttachmentController::class, 'destroy']);

                Route::post('/links', [GraphController::class, 'link']);
                Route::delete('/links', [GraphController::class, 'unlink']);

                Route::post('/nodes/delete-many', [GraphController::class, 'deleteNodes']);
                Route::post('/nodes/restore', [GraphController::class, 'restoreNodes']);
                Route::post('/nodes/{node}/revisions/{revision}/restore', [GraphController::class, 'restoreNodeRevision']);
            });

            // Удаление пространства и управление шерингом — только владелец/root.
            Route::middleware('can:manage,space')->group(function () {
                Route::delete('/', [SpaceController::class, 'destroy']);

                Route::get('/activity-log', [SpaceActivityLogController::class, 'index']);

                Route::get('/collaborators', [SpaceCollaboratorController::class, 'index']);
                Route::post('/collaborators', [SpaceCollaboratorController::class, 'store']);
                Route::put('/collaborators/{collaborator}', [SpaceCollaboratorController::class, 'update']);
                Route::delete('/collaborators/{collaborator}', [SpaceCollaboratorController::class, 'destroy']);
            });
        });
    });
});
