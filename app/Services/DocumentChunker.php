<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

class DocumentChunker
{
    public function __construct(
        private int $chunkSize = 1000,
        private int $overlap = 150,
    ) {}

    /**
     * @return array{pages: int, chunks: array<int, array{content: string, page: int}>}
     */
    public function extract(string $path): array
    {
        $parser = new Parser;
        $pdf = $parser->parseFile($path);
        $pages = $pdf->getPages();
        $chunks = [];

        foreach ($pages as $index => $page) {
            $text = preg_replace('/\s+/', ' ', trim($page->getText()));

            if ($text === '' || $text === null) {
                continue;
            }

            $chunks = array_merge($chunks, $this->splitPage($text, $index + 1));
        }

        return [
            'pages' => count($pages),
            'chunks' => $chunks,
        ];
    }

    /**
     * @return array<int, array{content: string, page: int}>
     */
    private function splitPage(string $text, int $pageNumber): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;

        while ($start < $length) {
            $end = min($start + $this->chunkSize, $length);
            $content = trim(mb_substr($text, $start, $end - $start));

            if ($content !== '') {
                $chunks[] = ['content' => $content, 'page' => $pageNumber];
            }

            if ($end === $length) {
                break;
            }

            $start = $end - $this->overlap;
        }

        return $chunks;
    }
}
