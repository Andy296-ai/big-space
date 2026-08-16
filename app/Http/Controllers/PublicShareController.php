<?php

namespace App\Http\Controllers;

use App\Models\Edge;
use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Share;
use App\Services\GraphRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Анонимный read-only доступ по публичной ссылке — единственная группа
 * роутов вне и auth, и guest (см. routes/web.php): $request->user() тут
 * всегда null, и HandleInertiaRequests уже это переживает (auth.user
 * просто уходит как null). Комментарии и мессенджер принципиально
 * недостижимы отсюда — ни один метод этого контроллера их не отдаёт.
 */
class PublicShareController extends Controller
{
    public function show(Share $share): Response
    {
        return Inertia::render('SharedSpace', [
            'token' => $share->token,
            'spaceName' => $share->space->name,
            'isSubtree' => $share->node_id !== null,
        ]);
    }

    public function graph(GraphRepository $repository, Share $share): JsonResponse
    {
        $nodeIds = $this->visibleNodeIds($repository, $share);

        $nodes = Node::whereIn('id', $nodeIds)->with('attachments')->get();
        $edges = Edge::where('space_id', $share->space_id)
            ->whereIn('parent_id', $nodeIds)
            ->whereIn('child_id', $nodeIds)
            ->get();

        return response()->json(['nodes' => $nodes, 'edges' => $edges]);
    }

    public function downloadAttachment(
        GraphRepository $repository,
        Share $share,
        Node $node,
        NodeAttachment $attachment,
    ): BinaryFileResponse|StreamedResponse {
        $this->guardAttachment($repository, $share, $node, $attachment);

        $disk = Storage::disk(NodeAttachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->label ?: basename($attachment->path));
    }

    public function previewAttachment(
        GraphRepository $repository,
        Share $share,
        Node $node,
        NodeAttachment $attachment,
    ): BinaryFileResponse {
        $this->guardAttachment($repository, $share, $node, $attachment);
        abort_unless($attachment->previewable, 404);

        $disk = Storage::disk(NodeAttachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        $mime = AttachmentController::PREVIEW_MIME_TYPES[strtolower($attachment->format)] ?? 'application/octet-stream';

        return response()->file($disk->path($attachment->path), [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
        ]);
    }

    private function guardAttachment(GraphRepository $repository, Share $share, Node $node, NodeAttachment $attachment): void
    {
        abort_unless($attachment->node_id === $node->id, 404);
        abort_unless($node->space_id === $share->space_id, 404);
        abort_unless(in_array($node->id, $this->visibleNodeIds($repository, $share), true), 404);
    }

    /** @return array<int, int> */
    private function visibleNodeIds(GraphRepository $repository, Share $share): array
    {
        if ($share->node_id === null) {
            return Node::where('space_id', $share->space_id)->pluck('id')->all();
        }

        return $repository->collectSubtreeIds($share->space_id, $share->node_id);
    }
}
