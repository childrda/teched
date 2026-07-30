<?php

namespace App\Services\Authoring;

use Illuminate\Validation\ValidationException;

/**
 * Turns validateConfig() attribute keys and JSON Schema pointers into
 * teacher-facing "Page / Block / field" messages.
 */
class AuthoringErrorFormatter
{
    /**
     * @param  array{page_title: string, page_index: int, block_type: string, block_index: int, block_id?: ?string}  $context
     * @return list<string>
     */
    public function fromValidationException(ValidationException $e, array $context): array
    {
        $prefix = $this->prefix($context);
        $messages = [];

        foreach ($e->errors() as $field => $fieldMessages) {
            foreach ($fieldMessages as $message) {
                $messages[] = "{$prefix} / {$field}: {$message}";
            }
        }

        return $messages;
    }

    /**
     * @param  list<string>  $pointers  JSON pointers like /pages/2/blocks/5/config/questions/1/answer_id
     * @param  list<array{title: string, blocks: list<array{type: string, block_id?: string}}}>  $pages
     * @return list<string>
     */
    public function fromSchemaPointers(array $pointers, array $pages): array
    {
        $messages = [];

        foreach ($pointers as $pointer) {
            $messages[] = $this->formatPointer($pointer, $pages);
        }

        return $messages;
    }

    /**
     * @param  list<array{title: string, blocks: list<array{type: string, block_id?: string}}}>  $pages
     */
    private function formatPointer(string $pointer, array $pages): string
    {
        if (preg_match('#^/pages/(\d+)/blocks/(\d+)(/.*)?$#', $pointer, $m)) {
            $pageIndex = (int) $m[1];
            $blockIndex = (int) $m[2];
            $rest = $m[3] ?? '';
            $page = $pages[$pageIndex] ?? null;
            $block = $page['blocks'][$blockIndex] ?? null;
            $pageTitle = $page['title'] ?? ("Page ".($pageIndex + 1));
            $blockType = $block['type'] ?? 'block';
            $field = ltrim(str_replace('/', '.', $rest), '.');
            $field = $field !== '' ? $field : '(manifest)';

            return "{$pageTitle} / {$blockType} #".($blockIndex + 1)." / {$field}: invalid per manifest schema ({$pointer})";
        }

        return "Manifest / {$pointer}: invalid per manifest schema";
    }

    /**
     * @param  array{page_title: string, page_index: int, block_type: string, block_index: int}  $context
     */
    private function prefix(array $context): string
    {
        return sprintf(
            '%s / %s #%d',
            $context['page_title'],
            $context['block_type'],
            $context['block_index'] + 1,
        );
    }
}
