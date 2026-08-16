<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\NodeAttachment;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;

/**
 * Разовая ручная команда, не scheduled — фича добавлена поверх уже
 * существующих данных, у которых embedding'а ещё нет. Дальше он
 * поддерживается сам, на каждом create/update узла и вложения (см.
 * GraphController::embedNode(), AttachmentController::embedAttachment()).
 */
class BackfillEmbeddings extends Command
{
    protected $signature = 'nodus:backfill-embeddings';

    protected $description = 'Generate embeddings for existing nodes and attachments that do not have one yet';

    public function handle(EmbeddingService $embeddings): int
    {
        $nodes = Node::whereNull('embedding')->get(['id', 'title', 'description', 'tags']);
        $this->info("Nodes without an embedding: {$nodes->count()}");

        $bar = $this->output->createProgressBar($nodes->count());

        foreach ($nodes as $node) {
            $text = trim($node->title.' '.$node->description.' '.$node->tags);
            $embeddings->store('nodes', $node->id, $embeddings->embed($text));
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $attachments = NodeAttachment::whereNull('embedding')->get();
        $this->info("Attachments without an embedding: {$attachments->count()}");

        $bar = $this->output->createProgressBar($attachments->count());

        foreach ($attachments as $attachment) {
            $text = $attachment->searchableTextForEmbedding();

            if ($text !== null && trim($text) !== '') {
                $embeddings->store(
                    'node_attachments',
                    $attachment->id,
                    $embeddings->embed($attachment->label.' '.$text),
                );
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
