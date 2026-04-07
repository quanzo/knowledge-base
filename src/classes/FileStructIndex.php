<?php

declare(strict_types=1);

namespace app\kb\classes;

use app\kb\classes\dto\FileStructDiffItem;
use app\kb\classes\dto\FileStructEntry;
use app\kb\classes\exceptions\FileStructEntryNotFoundException;
use app\kb\classes\exceptions\FileStructException;
use app\kb\classes\exceptions\FileStructFileChangedException;
use app\kb\classes\exceptions\FileStructFileMissingException;

/**
 * Индекс файла структуры (TSV) с поиском и валидацией реальной файловой системы.
 *
 * Читает файл структуры, где каждая строка соответствует одному файлу и содержит
 * поля: `indexLineNo`, `relativePath`, `depth`, `sizeBytes`, `mtimeUnix`, `sha256` (разделитель `\\t`).
 *
 * Валидация выполняется относительно корня структуры:
 * - файл должен существовать и быть читаемым
 * - `filemtime()` должен совпасть с `mtimeUnix` (точность до секунды)
 * - `hash_file('sha256', ...)` должен совпасть с `sha256`
 *
 * Любая ошибка доступа/чтения трактуется как «файл удалён/недоступен» и приводит к исключению.
 *
 * Пример:
 *
 * ```php
 * use app\kb\classes\FileStructIndex;
 *
 * $index = FileStructIndex::fromStructureFile('/repo', '/repo/structure.tsv');
 * $index->validatePath('src/classes/console/TimedConsoleApplication.php');
 * ```
 */
final class FileStructIndex
{
    private string $rootDir;
    private string $structureFilePath;

    /** @var array<string, FileStructEntry> */
    private array $entries = [];

    /**
     * @param string $rootDir Абсолютный путь к корню структуры.
     * @param array<string, FileStructEntry> $entries Индекс `relativePath -> entry`.
     */
    private function __construct(string $rootDir, string $structureFilePath, array $entries)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->structureFilePath = $structureFilePath;
        $this->entries = $entries;
    }

    /**
     * Создаёт индекс, прочитав TSV-файл структуры.
     *
     * @param string $rootDir Корень структуры, относительно которого резолвятся `relativePath`.
     * @param string $structureFile Путь к TSV-файлу структуры.
     *
     * @return self
     *
     * @throws FileStructException Если корень/файл недоступны или файл структуры имеет неверный формат.
     */
    public static function fromStructureFile(string $rootDir, string $structureFile): self
    {
        $realRoot = realpath($rootDir);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new FileStructException(sprintf('Root directory not found: %s', $rootDir));
        }

        if (!is_readable($realRoot)) {
            throw new FileStructException(sprintf('Root directory is not readable: %s', $realRoot));
        }

        $realStructureFile = realpath($structureFile);
        if ($realStructureFile === false || !is_file($realStructureFile)) {
            throw new FileStructException(sprintf('Structure file not found: %s', $structureFile));
        }

        if (!is_readable($realStructureFile)) {
            throw new FileStructException(sprintf('Structure file is not readable: %s', $realStructureFile));
        }

        $fh = @fopen($realStructureFile, 'rb');
        if ($fh === false) {
            $error = error_get_last();
            $msg = $error !== null ? (string) $error['message'] : 'Unknown error';
            throw new FileStructException(sprintf('Failed to open structure file %s: %s', $realStructureFile, $msg));
        }

        $entries = [];
        try {
            $lineNo = 0;
            while (($line = fgets($fh)) !== false) {
                $lineNo++;
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }

                $parts = explode("\t", $line);
                if (count($parts) !== 6) {
                    throw new FileStructException(sprintf('Invalid TSV format at line %d', $lineNo));
                }

                [$indexLineNoStr, $relativePath, $depthStr, $sizeStr, $mtimeStr, $sha256] = $parts;
                self::parseInt($indexLineNoStr, 'indexLineNo', $lineNo);

                $relativePath = self::normalizeRelativePath($relativePath, $lineNo);
                $depth = self::parseInt($depthStr, 'depth', $lineNo);
                $sizeBytes = self::parseInt($sizeStr, 'sizeBytes', $lineNo);
                $mtimeUnix = self::parseInt($mtimeStr, 'mtimeUnix', $lineNo);

                $sha256 = strtolower($sha256);
                if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                    throw new FileStructException(sprintf('Invalid sha256 at line %d', $lineNo));
                }

                $entries[self::makeEntryKey($relativePath)] = new FileStructEntry($relativePath, $depth, $sizeBytes, $mtimeUnix, $sha256);
            }
        } finally {
            fclose($fh);
        }

        return new self($realRoot, $realStructureFile, $entries);
    }

    /**
     * Ищет строку в TSV-файле индекса по номеру строки из первой колонки (indexLineNo).
     *
     * Метод выполняет приближённый дихотомический поиск по байтовым оффсетам в файле:
     * - оценивает среднюю длину строк по первой строке
     * - прикидывает стартовый оффсет для искомого номера
     * - читает окно данных и извлекает примерно 10 строк до и 10 строк после позиции
     * - уточняет диапазон и повторяет до нахождения строки или исчерпания итераций
     *
     * Важно: файл индекса считается UTF-8. Поиск работает в байтах и никогда не возвращает
     * обрезанные строки — только строки между символами `\\n`.
     *
     * @param int $indexLineNo Искомый номер строки (начиная с 1).
     *
     * @return string|null Полная строка TSV без символов перевода строки или null, если не найдена.
     *
     * @throws FileStructException Если индекс недоступен для чтения.
     */
    public function findLineByNumber(int $indexLineNo): ?string
    {
        if ($indexLineNo < 1) {
            return null;
        }

        $file = $this->structureFilePath;
        if (!is_file($file) || !is_readable($file)) {
            throw new FileStructException(sprintf('Structure file is not readable: %s', $file));
        }

        $size = filesize($file);
        if ($size === false || $size === 0) {
            return null;
        }

        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            $error = error_get_last();
            $msg = $error !== null ? (string) $error['message'] : 'Unknown error';
            throw new FileStructException(sprintf('Failed to open structure file %s: %s', $file, $msg));
        }

        try {
            $firstLine = fgets($fh);
            if ($firstLine === false) {
                return null;
            }

            $avgLineBytes = max(1, strlen($firstLine));
            $low = 0;
            $high = (int) $size;

            $guess = (int) min(max(0, ($indexLineNo - 1) * $avgLineBytes), max(0, $high - 1));

            for ($i = 0; $i < 25; $i++) {
                [$windowStart, $windowEnd, $lines] = $this->readWindowAroundOffset($fh, $guess, 10, 10);
                if ($lines === []) {
                    return null;
                }

                $minNo = $lines[0][0];
                $maxNo = $lines[count($lines) - 1][0];

                foreach ($lines as [$no, $line]) {
                    if ($no === $indexLineNo) {
                        return $line;
                    }
                }

                if ($indexLineNo < $minNo) {
                    $high = $windowStart;
                } elseif ($indexLineNo > $maxNo) {
                    $low = $windowEnd;
                } else {
                    // Номер должен быть внутри окна, но не найден: делаем небольшой сдвиг по оценке.
                    $mid = $lines[(int) floor(count($lines) / 2)][0];
                    $delta = $indexLineNo - $mid;
                    $guess = (int) min(max(0, $guess + ($delta * $avgLineBytes)), max(0, $high - 1));
                    continue;
                }

                if ($low >= $high) {
                    break;
                }

                $guess = (int) floor(($low + $high) / 2);
            }

            return null;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Проверяет, есть ли запись для указанного пути в структуре.
     *
     * @param string $relativePath Относительный путь (как в TSV).
     *
     * @return bool
     */
    public function hasPath(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        return isset($this->entries[self::makeEntryKey($relativePath)]);
    }

    /**
     * Возвращает запись для указанного пути.
     *
     * @param string $relativePath Относительный путь (как в TSV).
     *
     * @return FileStructEntry
     *
     * @throws FileStructEntryNotFoundException Если запись отсутствует.
     */
    public function getByPath(string $relativePath): FileStructEntry
    {
        $relativePath = str_replace('\\', '/', $relativePath);

        $key = self::makeEntryKey($relativePath);
        if (!isset($this->entries[$key])) {
            throw new FileStructEntryNotFoundException($relativePath);
        }

        return $this->entries[$key];
    }

    /**
     * Ищет записи по префиксу относительного пути.
     *
     * @param string $prefix Префикс (например, `src/classes/`).
     * @param callable(FileStructEntry):void $onFound Коллбек для каждой найденной записи.
     *
     * @return void
     */
    public function findByPrefix(string $prefix, callable $onFound): void
    {
        $prefix = str_replace('\\', '/', $prefix);

        foreach ($this->entries as $entry) {
            if (str_starts_with($entry->getRelativePath(), $prefix)) {
                $onFound($entry);
            }
        }
    }

    /**
     * Ищет записи по glob-маскам относительного пути.
     *
     * Маски сопоставляются с `relativePath` (с `/`), через {@see fnmatch()}.
     * Если масок несколько — запись считается найденной, если совпала хотя бы с одной маской.
     *
     * @param string[] $masks Список glob-масок.
     * @param callable(FileStructEntry):void $onFound Коллбек для каждой найденной записи.
     *
     * @return void
     */
    public function findByMasks(array $masks, callable $onFound): void
    {
        $masks = $this->normalizeMasks($masks);

        foreach ($this->entries as $entry) {
            if ($this->matchesAnyMask($entry->getRelativePath(), $masks)) {
                $onFound($entry);
            }
        }
    }

    /**
     * Валидирует файлы, найденные по glob-маскам, и сообщает результат через callable.
     *
     * Коллбек вызывается для каждого найденного файла:
     * - `$error === null` означает, что файл валиден (exists + mtime + md5 совпали)
     * - `$error` содержит исключение, если файл отсутствует/недоступен или изменён
     *
     * @param string[] $masks Список glob-масок.
     * @param callable(FileStructEntry, FileStructException|null):void $onResult Коллбек результата.
     *
     * @return void
     */
    public function validateByMasks(array $masks, callable $onResult): void
    {
        $this->findByMasks($masks, function (FileStructEntry $entry) use ($onResult): void {
            try {
                $this->validateEntry($entry);
                $onResult($entry, null);
            } catch (FileStructException $e) {
                $onResult($entry, $e);
            }
        });
    }

    /**
     * Валидация файла по относительному пути из структуры.
     *
     * @param string $relativePath Относительный путь (как в TSV).
     *
     * @return void
     *
     * @throws FileStructEntryNotFoundException Если записи нет в структуре.
     * @throws FileStructFileMissingException Если файл отсутствует/недоступен/ошибка чтения.
     * @throws FileStructFileChangedException Если файл изменён (mtime/sha256 не совпали).
     */
    public function validatePath(string $relativePath): void
    {
        $this->validateEntry($this->getByPath($relativePath));
    }

    /**
     * Валидация реального файла относительно записи структуры.
     *
     * @param FileStructEntry $entry Запись структуры.
     *
     * @return void
     *
     * @throws FileStructFileMissingException Если файл отсутствует/недоступен/ошибка чтения.
     * @throws FileStructFileChangedException Если файл изменён (mtime/sha256 не совпали).
     */
    public function validateEntry(FileStructEntry $entry): void
    {
        $absolutePath = $this->rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry->getRelativePath());

        if (!is_file($absolutePath)) {
            throw new FileStructFileMissingException($entry->getRelativePath(), $absolutePath, 'not a file');
        }

        if (!is_readable($absolutePath)) {
            throw new FileStructFileMissingException($entry->getRelativePath(), $absolutePath, 'not readable');
        }

        $mtime = filemtime($absolutePath);
        if ($mtime === false) {
            throw new FileStructFileMissingException($entry->getRelativePath(), $absolutePath, 'failed to read mtime');
        }

        if ((int) $mtime !== $entry->getMtimeUnix()) {
            throw new FileStructFileChangedException(
                $entry->getRelativePath(),
                $absolutePath,
                sprintf('mtime mismatch (expected %d, got %d)', $entry->getMtimeUnix(), (int) $mtime)
            );
        }

        $sha256 = hash_file('sha256', $absolutePath);
        if ($sha256 === false) {
            throw new FileStructFileMissingException($entry->getRelativePath(), $absolutePath, 'failed to read sha256');
        }

        $sha256 = strtolower($sha256);
        if ($sha256 !== strtolower($entry->getSha256())) {
            throw new FileStructFileChangedException(
                $entry->getRelativePath(),
                $absolutePath,
                sprintf('sha256 mismatch (expected %s, got %s)', strtolower($entry->getSha256()), $sha256)
            );
        }
    }

    /**
     * Сравнивает текущий индекс с другим индексом и вызывает callback для каждого отличия.
     *
     * Отличия определяются по `relativePath`:
     * - если путь есть только в правом индексе — ADDED
     * - если путь есть только в левом индексе — REMOVED
     * - если путь есть в обоих — MODIFIED (только если изменились sizeBytes/mtimeUnix/sha256)
     *
     * @param FileStructIndex $other Правый индекс (обычно более новый).
     * @param callable(FileStructDiffItem):void $onDiff Коллбек, вызываемый для каждого отличия.
     *
     * @return void
     */
    public function diffWith(FileStructIndex $other, callable $onDiff): void
    {
        /** @var array<string, bool> $seen */
        $seen = [];

        foreach ($this->entries as $key => $leftEntry) {
            $seen[$key] = true;

            $rightEntry = $other->entries[$key] ?? null;
            if ($rightEntry === null) {
                $onDiff(new FileStructDiffItem($leftEntry, null, false, false, false));
                continue;
            }

            $sizeChanged   = $leftEntry->getSizeBytes()          !== $rightEntry->getSizeBytes();
            $mtimeChanged  = $leftEntry->getMtimeUnix()          !== $rightEntry->getMtimeUnix();
            $sha256Changed = strtolower($leftEntry->getSha256()) !== strtolower($rightEntry->getSha256());

            if ($sizeChanged || $mtimeChanged || $sha256Changed) {
                $onDiff(new FileStructDiffItem($leftEntry, $rightEntry, $sizeChanged, $mtimeChanged, $sha256Changed));
            }
        }

        foreach ($other->entries as $key => $rightEntry) {
            if (isset($seen[$key])) {
                continue;
            }

            $onDiff(new FileStructDiffItem(null, $rightEntry, false, false, false));
        }
    }

    /**
     * Возвращает корень структуры, относительно которого резолвятся пути.
     *
     * @return string
     */
    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * Возвращает путь к TSV-файлу индекса.
     *
     * @return string
     */
    public function getStructureFilePath(): string
    {
        return $this->structureFilePath;
    }

    /**
     * Обходит все записи индекса и вызывает коллбек для каждой.
     *
     * Используйте этот метод, когда нужно выполнить однотипную обработку всех файлов,
     * не раскрывая внутреннюю структуру хранения индекса.
     *
     * @param callable(FileStructEntry):void $call Коллбек для каждой записи.
     *
     * @return void
     */
    public function forEachEntry(callable $call): void
    {
        foreach ($this->entries as $entry) {
            $call($entry);
        }
    }

    /**
     * Нормализует и валидирует относительный путь.
     *
     * @param string $relativePath Путь из TSV.
     * @param int $lineNo Номер строки (для ошибок).
     *
     * @return string Нормализованный путь (с `/`).
     *
     * @throws FileStructException При некорректном пути.
     */
    private static function normalizeRelativePath(string $relativePath, int $lineNo): string
    {
        $relativePath = str_replace('\\', '/', $relativePath);

        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            throw new FileStructException(sprintf('Invalid relativePath at line %d', $lineNo));
        }

        if (str_starts_with($relativePath, '/') || preg_match('/^[a-zA-Z]:\\//', $relativePath) === 1) {
            throw new FileStructException(sprintf('Relative path must not be absolute at line %d', $lineNo));
        }

        if (str_starts_with($relativePath, '../') || str_contains($relativePath, '/../')) {
            throw new FileStructException(sprintf('Relative path traversal is not allowed at line %d', $lineNo));
        }

        return $relativePath;
    }

    /**
     * Парсит целое число из строки.
     *
     * @param string $value Строковое значение.
     * @param string $fieldName Имя поля для сообщения об ошибке.
     * @param int $lineNo Номер строки (для ошибок).
     *
     * @return int
     *
     * @throws FileStructException Если значение не является int.
     */
    private static function parseInt(string $value, string $fieldName, int $lineNo): int
    {
        if ($value === '' || preg_match('/^-?[0-9]+$/', $value) !== 1) {
            throw new FileStructException(sprintf('Invalid %s at line %d', $fieldName, $lineNo));
        }

        return (int) $value;
    }

    /**
     * Нормализует маски (glob) для сопоставления с `relativePath`.
     *
     * @param string[] $masks Маски.
     *
     * @return string[]
     */
    private function normalizeMasks(array $masks): array
    {
        $normalized = [];
        foreach ($masks as $mask) {
            $mask = str_replace('\\', '/', (string) $mask);
            $mask = str_replace('**', '*', $mask);
            if ($mask === '') {
                continue;
            }
            $normalized[] = $mask;
        }

        return $normalized;
    }

    /**
     * Проверяет, совпадает ли путь хотя бы с одной маской.
     *
     * @param string $relativePath Относительный путь (с `/`).
     * @param string[] $masks Нормализованные маски.
     *
     * @return bool
     */
    private function matchesAnyMask(string $relativePath, array $masks): bool
    {
        if ($masks === []) {
            return false;
        }

        foreach ($masks as $mask) {
            if (fnmatch($mask, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Формирует безопасный ключ для индексирования записей по относительному пути.
     *
     * В PHP строковые ключи, выглядящие как число (например, "123"), автоматически
     * преобразуются в int-ключи. Это ломает код, который ожидает строковый путь.
     * Префикс гарантирует строковый ключ для любого пути.
     *
     * @param string $relativePath Относительный путь (с `/`).
     *
     * @return string
     */
    private static function makeEntryKey(string $relativePath): string
    {
        return 'p:' . $relativePath;
    }

    /**
     * Читает окно вокруг байтового оффсета и возвращает строки с их indexLineNo.
     *
     * @param resource $fh Открытый файловый дескриптор (rb).
     * @param int $offset Байтовый оффсет, вокруг которого читаем.
     * @param int $before Количество строк до позиции (приблизительно).
     * @param int $after Количество строк после позиции (приблизительно).
     *
     * @return array{0:int,1:int,2:array<int,array{0:int,1:string}>} [windowStart, windowEnd, lines]
     */
    private function readWindowAroundOffset($fh, int $offset, int $before, int $after): array
    {
        $chunkSize = 256 * 1024;
        $half = (int) floor($chunkSize / 2);
        $start = max(0, $offset - $half);

        fseek($fh, $start);
        $buf = fread($fh, $chunkSize);
        if ($buf === false || $buf === '') {
            return [$start, $start, []];
        }

        $end = $start + strlen($buf);

        // Если окно не начинается с начала файла, отбрасываем обрывок первой строки.
        if ($start > 0) {
            $pos = strpos($buf, "\n");
            if ($pos === false) {
                return [$start, $end, []];
            }
            $buf = substr($buf, $pos + 1);
            $start = $start + $pos + 1;
        }

        $rawLines = explode("\n", $buf);
        // Последняя часть может быть обрывком, убираем её.
        array_pop($rawLines);

        $parsed = [];
        foreach ($rawLines as $raw) {
            $raw = rtrim($raw, "\r");
            if ($raw === '') {
                continue;
            }

            $no = $this->parseIndexLineNoFromLine($raw);
            if ($no === null) {
                continue;
            }

            $parsed[] = [$no, $raw];
        }

        if ($parsed === []) {
            return [$start, $end, []];
        }

        usort($parsed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        return [$start, $end, $parsed];
    }

    /**
     * Достаёт indexLineNo из строки TSV.
     *
     * @param string $line Полная строка без `\\n`.
     *
     * @return int|null
     */
    private function parseIndexLineNoFromLine(string $line): ?int
    {
        $pos = strpos($line, "\t");
        if ($pos === false) {
            return null;
        }

        $noStr = substr($line, 0, $pos);
        if ($noStr === '' || preg_match('/^[0-9]+$/', $noStr) !== 1) {
            return null;
        }

        $no = (int) $noStr;
        return $no > 0 ? $no : null;
    }
}
