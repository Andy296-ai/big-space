<?php

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
        // Spaces
        Route::get('/spaces', [SpaceController::class, 'index']);
        Route::post('/spaces', [SpaceController::class, 'store']);
        Route::post('/spaces/import', [SpaceController::class, 'import']);
        Route::get('/spaces/{space}/export', [SpaceController::class, 'export']);
        Route::put('/spaces/{space}/structure', [SpaceController::class, 'updateStructure']);
        Route::delete('/spaces/{space}', [SpaceController::class, 'destroy']);

        // Graph data & operations
        Route::get('/spaces/{space}/graph', [GraphController::class, 'fetchGraph']);
        Route::post('/spaces/{space}/nodes/root', [GraphController::class, 'createRoot']);
        Route::post('/spaces/{space}/nodes/{parent}/child', [GraphController::class, 'addChild']);
        Route::put('/spaces/{space}/nodes/bulk-move', [GraphController::class, 'bulkMove']);
        Route::put('/spaces/{space}/nodes/{node}/move', [GraphController::class, 'move']);
        Route::put('/spaces/{space}/nodes/{node}', [GraphController::class, 'update']);

        // Attachments: uploaded files and external links
        Route::get('/spaces/{space}/attachments/search', [AttachmentController::class, 'search']);
        Route::post('/spaces/{space}/nodes/{node}/attachments', [AttachmentController::class, 'store']);
        Route::get('/spaces/{space}/nodes/{node}/attachments/{attachment}/download', [AttachmentController::class, 'download']);
        Route::get('/spaces/{space}/nodes/{node}/attachments/{attachment}/preview', [AttachmentController::class, 'preview']);
        Route::get('/spaces/{space}/nodes/{node}/attachments/{attachment}/content', [AttachmentController::class, 'content']);
        Route::put('/spaces/{space}/nodes/{node}/attachments/{attachment}/content', [AttachmentController::class, 'updateContent']);
        Route::delete('/spaces/{space}/nodes/{node}/attachments/{attachment}', [AttachmentController::class, 'destroy']);

        // Links & cycle checks
        Route::post('/spaces/{space}/links', [GraphController::class, 'link']);
        Route::delete('/spaces/{space}/links', [GraphController::class, 'unlink']);

        // Deletion & Undo
        Route::get('/spaces/{space}/nodes/{node}/deletion-preview', [GraphController::class, 'computeDeletion']);
        Route::post('/spaces/{space}/nodes/delete-many', [GraphController::class, 'deleteNodes']);
        Route::post('/spaces/{space}/nodes/restore', [GraphController::class, 'restoreNodes']);
    });
});
