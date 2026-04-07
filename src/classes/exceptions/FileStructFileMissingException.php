<?php

declare(strict_types=1);

namespace app\kb\classes\exceptions;

/**
 * Исключение: файл отсутствует или недоступен для чтения.
 *
 * По правилам проекта любая ошибка доступа/чтения (включая невозможность получить mtime/md5)
 * трактуется как «файл удалён/недоступен».
 *
 * Пример:
 *
 * ```php
 * use app\kb\classes\FileStructIndex;
 * use app\kb\classes\exceptions\FileStructFileMissingException;
 *
 * try {
 *     $index = FileStructIndex::fromStructureFile('/repo', '/repo/structure.tsv');
 *     $index->validatePath('src/file.txt');
 * } catch (FileStructFileMissingException $e) {
 *     // файл удалён или нет доступа
 * }
 * ```
 */
final class FileStructFileMissingException extends FileStructException
{
    /**
     * @param string $relativePath Относительный путь из структуры.
     * @param string $absolutePath Абсолютный путь к ожидаемому файлу.
     * @param string $reason Короткое описание причины.
     */
    public function __construct(string $relativePath, string $absolutePath, string $reason)
    {
        parent::__construct(sprintf('File missing for %s (%s): %s', $relativePath, $absolutePath, $reason));
    }
}
