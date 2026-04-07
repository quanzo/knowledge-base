<?php

declare(strict_types=1);

namespace app\kb\classes;

use app\kb\classes\dto\FileStructEntry;
use app\kb\classes\exceptions\FileStructException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Класс для обхода и записи структуры файлов.
 *
 * Обходит файловую систему относительно корня структуры, собирает информацию о файлах
 * (относительный путь, глубина, `mtime` в секундах и `md5`) и позволяет записать результат
 * в простой TSV-файл (одна строка — один файл).
 *
 * Формат TSV (разделитель `\\t`):
 * - indexLineNo (int, номер строки, начиная с 1)
 * - relativePath (всегда с `/`, относительно корня структуры)
 * - depth (0 — файл в корне структуры)
 * - sizeBytes (int, байты)
 * - mtimeUnix (int, секунды)
 * - sha256 (64 hex)
 *
 * Пример:
 *
 * ```php
 * use app\kb\classes\FileStruct;
 *
 * $fs = new FileStruct('/repo');
 * $fs->writeFile('.', '/repo/structure.tsv');
 * ```
 */
final class FileStruct
{
    private string $rootDir;
    /** @var string[]|null */
    private ?array $include = null;
    /** @var string[]|null */
    private ?array $exclude = null;

    /**
     * @param string $startDir Корень структуры файлов (директория).
     *
     * @throws FileStructException Если директория не существует или недоступна.
     */
    public function __construct(string $startDir)
    {
        $real = realpath($startDir);
        if ($real === false || !is_dir($real)) {
            throw new FileStructException(sprintf('Root directory not found: %s', $startDir));
        }

        if (!is_readable($real)) {
            throw new FileStructException(sprintf('Root directory is not readable: %s', $real));
        }

        $this->rootDir = rtrim($real, DIRECTORY_SEPARATOR);
    }

    /**
     * Задаёт список масок (glob), которые включают файлы в структуру.
     *
     * Если не задано (null) — фильтрация по include не применяется.
     *
     * Маски сравниваются с `relativePath` (путь относительно корня структуры, с `/`).
     *
     * Пример:
     *
     * ```php
     * $fs->setInclude(['src/**.php', 'docs/*.md']);
     * ```
     *
     * @param string[]|null $include Маски включения или null.
     *
     * @return $this
     */
    public function setInclude(?array $include): self
    {
        $this->include = $include;
        return $this;
    }

    /**
     * Задаёт список масок (glob), которые исключают файлы из структуры.
     *
     * Если не задано (null) — фильтрация по exclude не применяется.
     *
     * Маски сравниваются с `relativePath` (путь относительно корня структуры, с `/`).
     *
     * Пример:
     *
     * ```php
     * $fs->setExclude(['vendor/**', '*.log']);
     * ```
     *
     * @param string[]|null $exclude Маски исключения или null.
     *
     * @return $this
     */
    public function setExclude(?array $exclude): self
    {
        $this->exclude = $exclude;
        return $this;
    }

    /**
     * Обходит структуру файлов в указанном подкаталоге корня и вызывает коллбек для каждого файла.
     *
     * В коллбек передаётся:
     * - абсолютный путь к файлу
     * - глубина относительно корня структуры
     * - относительный путь относительно корня структуры (с `/`)
     *
     * @param string $startDir Подкаталог для обхода: абсолютный путь внутри корня или путь относительно корня.
     * @param callable(string,int,string):void $call Коллбек для каждого найденного файла.
     *
     * @return void
     *
     * @throws FileStructException Если $startDir вне корня структуры или недоступен.
     */
    public function collectStructure(string $startDir, callable $call): void
    {
        $dir = $this->resolveDirectoryInsideRoot($startDir);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if (!$info->isFile()) {
                continue;
            }

            $absolutePath = $info->getPathname();
            $relativePath = $this->makeRelativePath($absolutePath);
            $depth = $this->calculateDepth($relativePath);

            if (!$this->shouldIncludeRelativePath($relativePath)) {
                continue;
            }

            $call($absolutePath, $depth, $relativePath);
        }
    }

    /**
     * Записывает структуру файлов в TSV-файл.
     *
     * Запись выполняется потоково по мере обхода файлов, без накопления данных в памяти.
     * Порядок строк соответствует порядку обхода файловой системы и может отличаться
     * между разными окружениями/файловыми системами.
     *
     * @param string $startDir Подкаталог для обхода: абсолютный путь внутри корня или путь относительно корня.
     * @param string $outFileName Путь к файлу вывода (абсолютный или относительно текущего процесса).
     *
     * @return void
     *
     * @throws FileStructException При ошибках обхода/чтения метаданных/записи в файл.
     */
    public function writeFile(string $startDir, string $outFileName): void
    {
        $fh = @fopen($outFileName, 'wb');
        if ($fh === false) {
            $error = error_get_last();
            $msg = $error !== null ? (string) $error['message'] : 'Unknown error';
            throw new FileStructException(sprintf('Failed to open output file %s: %s', $outFileName, $msg));
        }

        $meta        = stream_get_meta_data($fh);
        $outPath     = isset($meta['uri']) ? (string) $meta['uri'] : $outFileName;
        $outRealPath = realpath($outPath);

        $indexLineNo = 0;
        try {
            $this->collectStructure($startDir, function (string $absolutePath, int $depth, string $relativePath) use ($fh, $outRealPath, &$indexLineNo): void {
                $absoluteRealPath = realpath($absolutePath);
                if ($outRealPath !== false && $absoluteRealPath !== false && $absoluteRealPath === $outRealPath) {
                    return;
                }

                $mtime = filemtime($absolutePath);
                if ($mtime === false) {
                    throw new FileStructException(sprintf('Failed to read mtime: %s', $absolutePath));
                }

                $size = filesize($absolutePath);
                if ($size === false) {
                    throw new FileStructException(sprintf('Failed to read size: %s', $absolutePath));
                }

                $sha256 = hash_file('sha256', $absolutePath);
                if ($sha256 === false) {
                    throw new FileStructException(sprintf('Failed to read sha256: %s', $absolutePath));
                }

                $entry = new FileStructEntry($relativePath, $depth, (int) $size, (int) $mtime, strtolower($sha256));
                $indexLineNo++;
                $line = $entry->toIndexLine($indexLineNo) . "\n";
                $written = @fwrite($fh, $line);
                if ($written === false || $written !== strlen($line)) {
                    $error = error_get_last();
                    $msg = $error !== null ? (string) $error['message'] : 'Unknown error';
                    throw new FileStructException(sprintf('Failed to write output file: %s', $msg));
                }
            });
        } finally {
            fclose($fh);
        }
    }

    /**
     * Возвращает корень структуры (абсолютный путь).
     *
     * @return string
     */
    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * Разрешает директорию внутри корня структуры.
     *
     * @param string $path Абсолютный путь или путь относительно корня.
     *
     * @return string Абсолютный путь к директории.
     *
     * @throws FileStructException Если путь вне корня структуры или не директория.
     */
    private function resolveDirectoryInsideRoot(string $path): string
    {
        $candidate = $path;
        if (!$this->isAbsolutePath($candidate)) {
            $candidate = $this->rootDir . DIRECTORY_SEPARATOR . $candidate;
        }

        $real = realpath($candidate);
        if ($real === false || !is_dir($real)) {
            throw new FileStructException(sprintf('Start directory not found: %s', $path));
        }

        $real = rtrim($real, DIRECTORY_SEPARATOR);
        if (!$this->isPathInsideRoot($real)) {
            throw new FileStructException(sprintf('Start directory is outside of root: %s', $real));
        }

        if (!is_readable($real)) {
            throw new FileStructException(sprintf('Start directory is not readable: %s', $real));
        }

        return $real;
    }

    /**
     * Строит относительный путь (с `/`) относительно корня структуры.
     *
     * @param string $absolutePath Абсолютный путь к файлу.
     *
     * @return string
     *
     * @throws FileStructException Если путь вне корня структуры.
     */
    private function makeRelativePath(string $absolutePath): string
    {
        $absolutePath = rtrim($absolutePath, DIRECTORY_SEPARATOR);
        if (!$this->isPathInsideRoot($absolutePath)) {
            throw new FileStructException(sprintf('File is outside of root: %s', $absolutePath));
        }

        $prefix = $this->rootDir . DIRECTORY_SEPARATOR;
        $rel = substr($absolutePath, strlen($prefix));
        if ($rel === false) {
            throw new RuntimeException('Unexpected substring failure.');
        }

        return str_replace('\\', '/', $rel);
    }

    /**
     * Вычисляет глубину по относительному пути.
     *
     * @param string $relativePath Путь относительно корня структуры (с `/`).
     *
     * @return int
     */
    private function calculateDepth(string $relativePath): int
    {
        $relativePath = ltrim($relativePath, '/');
        $parts = $relativePath === '' ? [] : explode('/', $relativePath);
        return max(0, count($parts) - 1);
    }

    /**
     * Проверяет, что путь находится внутри корня структуры.
     *
     * @param string $realPath Абсолютный путь (желательно realpath()).
     *
     * @return bool
     */
    private function isPathInsideRoot(string $realPath): bool
    {
        $root = $this->rootDir;

        if ($realPath === $root) {
            return true;
        }

        return str_starts_with($realPath, $root . DIRECTORY_SEPARATOR);
    }

    /**
     * Проверяет, является ли путь абсолютным.
     *
     * @param string $path Путь.
     *
     * @return bool
     */
    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        return $path[0] === DIRECTORY_SEPARATOR;
    }

    /**
     * Определяет, должен ли файл попасть в структуру по include/exclude маскам.
     *
     * @param string $relativePath Относительный путь (с `/`) относительно корня структуры.
     *
     * @return bool
     */
    private function shouldIncludeRelativePath(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', $relativePath);

        if (is_array($this->include) && $this->include !== []) {
            if (!$this->matchesAnyMask($relativePath, $this->include)) {
                return false;
            }
        }

        if (is_array($this->exclude) && $this->exclude !== []) {
            if ($this->matchesAnyMask($relativePath, $this->exclude)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверяет, совпадает ли путь хотя бы с одной маской.
     *
     * Маски задаются в стиле glob и сравниваются с `relativePath` (с `/`).
     * Для совместимости поддерживается `**`, которое трактуется как `*`.
     *
     * @param string $relativePath Относительный путь (с `/`).
     * @param string[] $masks Список масок.
     *
     * @return bool
     */
    private function matchesAnyMask(string $relativePath, array $masks): bool
    {
        foreach ($masks as $mask) {
            $mask = str_replace('\\', '/', (string) $mask);
            $mask = str_replace('**', '*', $mask);

            if (fnmatch($mask, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
