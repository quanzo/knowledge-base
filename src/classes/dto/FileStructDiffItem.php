<?php

declare(strict_types=1);

namespace app\kb\classes\dto;

use app\kb\enums\FileStructDiffStatus;

/**
 * DTO описания отличия одного файла между двумя индексами.
 *
 * Содержит ссылку на запись "до" (left) и "после" (right) и флаги изменений.
 * Используется для универсального сравнения индексов через callback.
 *
 * Пример:
 *
 * ```php
 * $item = new FileStructDiffItem($before, $after, true, false, true);
 * if ($item->getStatus() === FileStructDiffStatus::Modified) {
 *     // обработка
 * }
 * ```
 */
final class FileStructDiffItem
{
    /**
     * @param FileStructEntry|null $left Запись из левого индекса (старый).
     * @param FileStructEntry|null $right Запись из правого индекса (новый).
     * @param bool $sizeChanged Изменился размер.
     * @param bool $mtimeChanged Изменилось время модификации (секунды).
     * @param bool $sha256Changed Изменился sha256.
     */
    public function __construct(
        private ?FileStructEntry $left,
        private ?FileStructEntry $right,
        private bool $sizeChanged,
        private bool $mtimeChanged,
        private bool $sha256Changed,
    ) {
    }

    /**
     * Возвращает запись из левого индекса.
     *
     * @return FileStructEntry|null
     */
    public function getLeft(): ?FileStructEntry
    {
        return $this->left;
    }

    /**
     * Возвращает запись из правого индекса.
     *
     * @return FileStructEntry|null
     */
    public function getRight(): ?FileStructEntry
    {
        return $this->right;
    }

    /**
     * Возвращает относительный путь файла.
     *
     * @return string
     */
    public function getRelativePath(): string
    {
        if ($this->left !== null) {
            return $this->left->getRelativePath();
        }

        if ($this->right !== null) {
            return $this->right->getRelativePath();
        }

        return '';
    }

    /**
     * Возвращает статус отличия.
     *
     * @return FileStructDiffStatus
     */
    public function getStatus(): FileStructDiffStatus
    {
        if ($this->left === null && $this->right !== null) {
            return FileStructDiffStatus::Added;
        }

        if ($this->left !== null && $this->right === null) {
            return FileStructDiffStatus::Removed;
        }

        return FileStructDiffStatus::Modified;
    }

    /**
     * Проверяет, были ли изменения полей для существующего в обоих индексах файла.
     *
     * @return bool
     */
    public function isModified(): bool
    {
        return $this->sizeChanged || $this->mtimeChanged || $this->sha256Changed;
    }

    /**
     * Возвращает признак изменения размера.
     *
     * @return bool
     */
    public function isSizeChanged(): bool
    {
        return $this->sizeChanged;
    }

    /**
     * Возвращает признак изменения времени модификации.
     *
     * @return bool
     */
    public function isMtimeChanged(): bool
    {
        return $this->mtimeChanged;
    }

    /**
     * Возвращает признак изменения sha256.
     *
     * @return bool
     */
    public function isSha256Changed(): bool
    {
        return $this->sha256Changed;
    }
}
