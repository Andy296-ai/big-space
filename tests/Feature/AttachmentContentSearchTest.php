<?php

use App\Models\Node;
use App\Models\NodeAttachment;
use App\Models\Space;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/** Собирает валидный минимальный PDF с одной строкой текста — без внешних инструментов, парсер smalot терпим к минимализму. */
function buildTestPdf(string $text): string
{
    $content = "BT /F1 12 Tf 10 50 Td ({$text}) Tj ET";
    $streamLen = strlen($content);

    $objects = [
        1 => '<</Type/Catalog/Pages 2 0 R>>',
        2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        3 => '<</Type/Page/Parent 2 0 R/Resources<</Font<</F1 4 0 R>>>>/MediaBox[0 0 200 100]/Contents 5 0 R>>',
        4 => '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        5 => "<</Length {$streamLen}>>\nstream\n{$content}\nendstream",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
    }

    $xrefStart = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n{$xrefStart}\n%%EOF";

    return $pdf;
}

function buildTestDocx(string $text): string
{
    $phpWord = new PhpWord;
    $section = $phpWord->addSection();
    $section->addText($text);

    $tmpPath = tempnam(sys_get_temp_dir(), 'docx');
    IOFactory::createWriter($phpWord, 'Word2007')->save($tmpPath);
    $bytes = file_get_contents($tmpPath);
    unlink($tmpPath);

    return $bytes;
}

beforeEach(function () {
    $this->actingAs(User::where('name', config('auth.root.username'))->firstOrFail());
    Storage::fake(NodeAttachment::DISK);

    $this->space = Space::create(['name' => 'ContentSearch', 'slug' => 'content-search']);
    $this->node = Node::create(['space_id' => $this->space->id, 'title' => 'Node with a PDF']);
});

test('uploading a pdf extracts its text once, and search finds it by that text', function () {
    $pdf = UploadedFile::fake()->createWithContent('report.pdf', buildTestPdf('unique needle keyword'));

    $response = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => $pdf],
    );
    $response->assertStatus(201);

    $attachment = NodeAttachment::find($response->json('id'));
    expect($attachment->extracted_text)->toContain('unique needle keyword');
    // extracted_text — внутреннее поле, наружу в JSON не отдаётся.
    expect($response->json())->not->toHaveKey('extracted_text');

    $found = $this->getJson("/api/spaces/{$this->space->id}/attachments/search?q=needle+keyword")
        ->assertStatus(200)
        ->json('node_ids');
    expect($found)->toContain($this->node->id);
});

test('uploading a docx extracts its text once, and search finds it by that text', function () {
    $docx = UploadedFile::fake()->createWithContent('notes.docx', buildTestDocx('a distinctive phrase in the document'));

    $response = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => $docx],
    );
    $response->assertStatus(201);

    $attachment = NodeAttachment::find($response->json('id'));
    expect($attachment->extracted_text)->toContain('a distinctive phrase');

    $found = $this->getJson("/api/spaces/{$this->space->id}/attachments/search?q=distinctive+phrase")
        ->assertStatus(200)
        ->json('node_ids');
    expect($found)->toContain($this->node->id);
});

test('search does not match a pdf that does not contain the query', function () {
    $pdf = UploadedFile::fake()->createWithContent('irrelevant.pdf', buildTestPdf('completely unrelated content'));

    $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => $pdf],
    )->assertStatus(201);

    $found = $this->getJson("/api/spaces/{$this->space->id}/attachments/search?q=needle+keyword")
        ->assertStatus(200)
        ->json('node_ids');
    expect($found)->not->toContain($this->node->id);
});

test('a corrupt pdf upload still succeeds, just without a search index', function () {
    $garbage = UploadedFile::fake()->createWithContent('broken.pdf', '%PDF-1.4 this is not a real pdf structure at all');

    $response = $this->post(
        "/api/spaces/{$this->space->id}/nodes/{$this->node->id}/attachments",
        ['kind' => 'file', 'file' => $garbage],
    );

    $response->assertStatus(201);
    $attachment = NodeAttachment::find($response->json('id'));
    expect($attachment->extracted_text)->toBeNull();
});
