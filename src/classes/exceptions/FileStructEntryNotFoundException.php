<?php

declare(strict_types=1);

namespace app\kb\classes\exceptions;

/**
 * Исключение: запись с указанным путём отсутствует в файле структуры.
 *
 * Пример:
 *
 * ```php
 * use app\kb\classes\FileStructIndex;
 * use app\kb\classes\exceptions\FileStructEntryNotFoundException;
 *
 * try {
 *     $index = FileStructIndex::fromStructureFile('/repo', '/repo/structure.tsv');
 *     $index->getByPath('missing/file.txt');
 * } catch (FileStructEntryNotFoundException $e) {
 *     // путь отсутствует в структуре
 * }
 * ```
 */
final class FileStructEntryNotFoundException extends FileStructException
{
    /**
     * @param string $relativePath Относительный путь, которого нет в структуре.
     */
    public function __construct(string $relativePath)
    {
        parent::__construct(sprintf('Path not found in structure: %s', $relativePath));
    }
}
