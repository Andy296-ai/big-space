<?php

use App\Models\Edge;
use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Space;
use App\Models\User;
use Smalot\PdfParser\Parser;

beforeEach(function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());
});

test('markdown export renders the space name and nested node headings', function () {
    $space = Space::create(['name' => 'My Knowledge Base', 'slug' => 'md-export', 'description' => 'Top-level notes']);
    $root = Node::create(['space_id' => $space->id, 'title' => 'Root Topic', 'description' => 'A root description', 'tags' => 'alpha, beta']);
    $child = Node::create(['space_id' => $space->id, 'title' => 'Child Topic']);
    Edge::create(['space_id' => $space->id, 'parent_id' => $root->id, 'child_id' => $child->id]);

    $response = $this->get("/api/spaces/{$space->id}/export/markdown");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');

    $body = $response->getContent();
    expect($body)->toContain('# My Knowledge Base');
    expect($body)->toContain('Top-level notes');
    expect($body)->toContain('## Root Topic');
    expect($body)->toContain('A root description');
    expect($body)->toContain('*Tags: alpha, beta*');
    // Ребёнок на один уровень глубже родителя.
    expect($body)->toContain('### Child Topic');
});

test('markdown export lists both link and stored-file attachments', function () {
    $space = Space::create(['name' => 'Attach Export', 'slug' => 'attach-export']);
    $node = Node::create(['space_id' => $space->id, 'title' => 'Node with files']);
    NodeAttachment::create([
        'node_id' => $node->id,
        'kind' => NodeAttachment::KIND_LINK,
        'label' => 'Reference',
        'url' => 'https://example.com/doc',
    ]);
    NodeAttachment::create([
        'node_id' => $node->id,
        'kind' => NodeAttachment::KIND_FILE,
        'label' => 'report.pdf',
        'path' => 'attachments/x/report.pdf',
        'size' => 10,
        'format' => 'pdf',
    ]);

    $body = $this->get("/api/spaces/{$space->id}/export/markdown")->getContent();

    expect($body)->toContain('[Reference](https://example.com/doc)');
    expect($body)->toContain('report.pdf (PDF)');
});

test(
    'exporting a network space with an actual cycle does not hang and visits every node exactly once',
    function () {
        $space = Space::create(['name' => 'Cyclic', 'slug' => 'cyclic-export', 'structure' => Space::STRUCTURE_NETWORK]);
        $root = Node::create(['space_id' => $space->id, 'title' => 'Root']);
        $a = Node::create(['space_id' => $space->id, 'title' => 'Node A']);
        $b = Node::create(['space_id' => $space->id, 'title' => 'Node B']);

        // root -> A -> B -> A: настоящий цикл, допустимый только в network.
        Edge::create(['space_id' => $space->id, 'parent_id' => $root->id, 'child_id' => $a->id]);
        Edge::create(['space_id' => $space->id, 'parent_id' => $a->id, 'child_id' => $b->id]);
        Edge::create(['space_id' => $space->id, 'parent_id' => $b->id, 'child_id' => $a->id]);

        $body = $this->get("/api/spaces/{$space->id}/export/markdown")->getContent();

        // Каждый узел встречается ровно один раз — без этого guard'а обход
        // A -> B -> A зациклился бы и либо завис, либо задублировал строки.
        expect(substr_count($body, '## Root'))->toBe(1);
        expect(substr_count($body, 'Node A'))->toBe(1);
        expect(substr_count($body, 'Node B'))->toBe(1);
    },
);

test('pdf export returns a genuine pdf document', function () {
    $space = Space::create(['name' => 'PDF Export', 'slug' => 'pdf-export']);
    Node::create(['space_id' => $space->id, 'title' => 'Some node']);

    $response = $this->get("/api/spaces/{$space->id}/export/pdf");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

test('raw html in a node title is escaped in the pdf export, not rendered live', function () {
    // html_input у CommonMarkConverter по умолчанию ALLOW: сырой HTML в
    // пользовательском тексте (заголовок/описание узла — любой editor
    // пространства может его задать) прошёл бы прямо в {!! !!} в
    // exports/space.blade.php без экранирования — инъекция в
    // "официальный" экспортированный PDF (спуфинг контента, скрытые
    // блоки, удалённые <img> как трекер/SSRF-зонд через dompdf). Фикс —
    // html_input => HtmlFilter::ESCAPE в SpaceController::exportPdf().
    $space = Space::create(['name' => 'XSS Export', 'slug' => 'xss-export']);
    Node::create([
        'space_id' => $space->id,
        'title' => '<img src=x onerror=alert(1)>',
        'description' => '<a href="https://evil.example/verify">Click to verify payment</a>',
    ]);

    $response = $this->get("/api/spaces/{$space->id}/export/pdf");
    $response->assertStatus(200);

    $text = (new Parser)->parseContent($response->getContent())->getText();

    // Экранированный HTML остаётся ВИДИМЫМ ТЕКСТОМ в PDF — не превращается
    // в живой <img>/<a>, но и не молча пропадает.
    expect($text)->toContain('<img src=x onerror=alert(1)>');
    expect($text)->toContain('<a href="https://evil.example/verify">Click to verify payment</a>');
});

test('a user without access to the space cannot export it in any format', function () {
    $owner = User::factory()->create();
    $space = Space::create(['name' => 'Private', 'slug' => 'private-export', 'user_id' => $owner->id]);

    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $this->get("/api/spaces/{$space->id}/export/markdown")->assertStatus(403);
    $this->get("/api/spaces/{$space->id}/export/pdf")->assertStatus(403);
});
