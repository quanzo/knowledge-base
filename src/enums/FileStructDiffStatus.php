<?php

declare(strict_types=1);

namespace app\kb\enums;

/**
 * Статус отличия между двумя индексами структуры файлов.
 *
 * Пример:
 *
 * ```php
 * use app\kb\enums\FileStructDiffStatus;
 *
 * if ($status === FileStructDiffStatus::Added) {
 *     // файл появился
 * }
 * ```
 */
enum FileStructDiffStatus: string
{
    case Added = 'ADDED';
    case Removed = 'REMOVED';
    case Modified = 'MODIFIED';
}
