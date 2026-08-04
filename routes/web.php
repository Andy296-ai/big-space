<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\SpaceController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
});

Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [GraphController::class, 'index'])->name('home');

    Route::prefix('api')->group(function () {
        // Spaces: список и создание видят всех пространств пользователя, а не чужих.
        Route::get('/spaces', [SpaceController::class, 'index']);
        Route::post('/spaces', [SpaceController::class, 'store']);
        Route::post('/spaces/import', [SpaceController::class, 'import']);

        // Пользователи — узлы в Admin-пространстве; управляет только root.
        Route::post('/admin/users', [UserController::class, 'store']);
        Route::delete('/admin/users/{user}', [UserController::class, 'destroy']);

        // Всё, что привязано к конкретному пространству: владелец или root.
        Route::middleware('can:access,space')->prefix('spaces/{space}')->group(function () {
            Route::get('/export', [SpaceController::class, 'export']);
            Route::put('/structure', [SpaceController::class, 'updateStructure']);
            Route::delete('/', [SpaceController::class, 'destroy']);

            // Graph data & operations
            Route::get('/graph', [GraphController::class, 'fetchGraph']);
            Route::post('/nodes/root', [GraphController::class, 'createRoot']);
            Route::post('/nodes/{parent}/child', [GraphController::class, 'addChild']);
            Route::post('/nodes/{node}/copy', [GraphController::class, 'copy']);
            Route::put('/nodes/bulk-move', [GraphController::class, 'bulkMove']);
            Route::put('/nodes/{node}/move', [GraphController::class, 'move']);
            Route::put('/nodes/{node}', [GraphController::class, 'update']);
            Route::get('/nodes/{node}/logo', [GraphController::class, 'logo']);
            Route::post('/nodes/{node}/logo', [GraphController::class, 'uploadLogo']);

            // Attachments: uploaded files and external links
            Route::get('/attachments/search', [AttachmentController::class, 'search']);
            Route::post('/nodes/{node}/attachments', [AttachmentController::class, 'store']);
            Route::get('/nodes/{node}/attachments/{attachment}/download', [AttachmentController::class, 'download']);
            Route::get('/nodes/{node}/attachments/{attachment}/preview', [AttachmentController::class, 'preview']);
            Route::get('/nodes/{node}/attachments/{attachment}/content', [AttachmentController::class, 'content']);
            Route::put('/nodes/{node}/attachments/{attachment}/content', [AttachmentController::class, 'updateContent']);
            Route::delete('/nodes/{node}/attachments/{attachment}', [AttachmentController::class, 'destroy']);

            // Links & cycle checks
            Route::post('/links', [GraphController::class, 'link']);
            Route::delete('/links', [GraphController::class, 'unlink']);

            // Deletion & Undo
            Route::get('/nodes/{node}/deletion-preview', [GraphController::class, 'computeDeletion']);
            Route::post('/nodes/delete-many', [GraphController::class, 'deleteNodes']);
            Route::post('/nodes/restore', [GraphController::class, 'restoreNodes']);
        });
    });
});
