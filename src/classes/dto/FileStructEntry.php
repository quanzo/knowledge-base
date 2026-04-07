<?php

declare(strict_types=1);

namespace app\kb\classes\dto;

use DateTimeImmutable;
use DateTimeZone;

/**
 * DTO записи о файле из файла структуры.
 *
 * Запись описывает один файл, найденный при обходе корня структуры.
 * Используется как единый тип для чтения, поиска и валидации.
 *
 * Пример:
 *
 * ```php
 * use app\kb\classes\dto\FileStructEntry;
 *
 * $entry = new FileStructEntry(
 *     'src/classes/console/TimedConsoleApplication.php',
 *     2,
 *     1234,
 *     1712480343,
 *     '71f3e86c42376ed1c9583f0117fad889f5139926594992b3f573e17939cb038f'
 * );
 * ```
 */
final class FileStructEntry
{
    /**
     * @param string $relativePath Путь относительно корня структуры (всегда с `/`).
     * @param int $depth Уровень вложенности относительно корня структуры (0 — файл в корне).
     * @param int $sizeBytes Размер файла в байтах.
     * @param int $mtimeUnix Время модификации в Unix timestamp (секунды).
     * @param string $sha256 SHA-256 хэш содержимого файла (64 hex).
     */
    public function __construct(
        private string $relativePath,
        private int $depth,
        private int $sizeBytes,
        private int $mtimeUnix,
        private string $sha256,
    ) {
    }

    /**
     * Возвращает относительный путь файла.
     *
     * @return string
     */
    public function getRelativePath(): string
    {
        return $this->relativePath;
    }

    /**
     * Возвращает глубину файла относительно корня структуры.
     *
     * @return int
     */
    public function getDepth(): int
    {
        return $this->depth;
    }

    /**
     * Возвращает размер файла в байтах.
     *
     * @return int
     */
    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    /**
     * Возвращает время модификации файла в секундах (Unix timestamp).
     *
     * @return int
     */
    public function getMtimeUnix(): int
    {
        return $this->mtimeUnix;
    }

    /**
     * Возвращает ожидаемый SHA-256 хэш файла.
     *
     * @return string
     */
    public function getSha256(): string
    {
        return $this->sha256;
    }

    /**
     * Возвращает время модификации в виде читаемой строки в таймзоне UTC.
     *
     * Формат соответствует {@see DATE_ATOM}.
     *
     * @return string
     */
    public function getMtimeUtcString(): string
    {
        $dt = (new DateTimeImmutable('@' . $this->mtimeUnix))->setTimezone(new DateTimeZone('UTC'));
        return $dt->format(DATE_ATOM);
    }

    /**
     * Возвращает строковое представление записи, как в файле индекса (TSV).
     *
     * Структура строки:
     * `indexLineNo<TAB>relativePath<TAB>depth<TAB>sizeBytes<TAB>mtimeUnix<TAB>sha256`
     *
     * @param int $indexLineNo Номер строки в файле индекса (начиная с 1).
     *
     * @return string
     */
    public function toIndexLine(int $indexLineNo): string
    {
        return $indexLineNo
            . "\t" . $this->relativePath
            . "\t" . $this->depth
            . "\t" . $this->sizeBytes
            . "\t" . $this->mtimeUnix
            . "\t" . strtolower($this->sha256);
    }
}
