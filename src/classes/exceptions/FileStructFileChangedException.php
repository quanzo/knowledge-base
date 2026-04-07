<?php

declare(strict_types=1);

namespace app\kb\classes\exceptions;

/**
 * Исключение: файл существует, но его метаданные/содержимое не совпали со структурой.
 *
 * Пример:
 *
 * ```php
 * use app\kb\classes\FileStructIndex;
 * use app\kb\classes\exceptions\FileStructFileChangedException;
 *
 * try {
 *     $index = FileStructIndex::fromStructureFile('/repo', '/repo/structure.tsv');
 *     $index->validatePath('src/file.txt');
 * } catch (FileStructFileChangedException $e) {
 *     // файл изменён
 * }
 * ```
 */
final class FileStructFileChangedException extends FileStructException
{
    /**
     * @param string $relativePath Относительный путь из структуры.
     * @param string $absolutePath Абсолютный путь к ожидаемому файлу.
     * @param string $reason Короткое описание расхождения.
     */
    public function __construct(string $relativePath, string $absolutePath, string $reason)
    {
        parent::__construct(sprintf('File changed for %s (%s): %s', $relativePath, $absolutePath, $reason));
    }
}
