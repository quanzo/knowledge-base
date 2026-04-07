<?php

declare(strict_types=1);

namespace app\kb\classes\exceptions;

use RuntimeException;

/**
 * Базовое исключение для работы со структурой файлов.
 *
 * Используется как общий тип для всех исключений подсистемы FileStruct,
 * чтобы можно было перехватывать их единым catch-блоком.
 *
 * Пример:
 *
 * ```php
 * try {
 *     // работа с FileStruct / FileStructIndex
 * } catch (FileStructException $e) {
 *     // обработка ошибок структуры файлов
 * }
 * ```
 */
class FileStructException extends RuntimeException
{
}
