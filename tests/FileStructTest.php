<?php

declare(strict_types=1);

namespace Tests;

use app\kb\classes\FileStruct;
use app\kb\classes\exceptions\FileStructException;
use PHPUnit\Framework\TestCase;

/**
 * Тесты генератора структуры файлов {@see FileStruct}.
 */
final class FileStructTest extends TestCase
{
    /**
     * Тест: конструктор должен падать на несуществующей директории.
     */
    public function testConstructorThrowsOnMissingDir(): void
    {
        $this->expectException(FileStructException::class);
        new FileStruct(__DIR__ . '/__no_such_dir__');
    }

    /**
     * Тест: обход пустой директории не вызывает коллбек.
     */
    public function testCollectStructureEmptyDir(): void
    {
        $root = $this->makeTempDir('filestruct-empty');
        $fs = new FileStruct($root);

        $count = 0;
        $fs->collectStructure('.', function () use (&$count): void {
            $count++;
        });

        self::assertSame(0, $count);
    }

    /**
     * Тест: файл в корне структуры получает depth=0 и корректный relativePath.
     */
    public function testCollectStructureFileInRoot(): void
    {
        $root = $this->makeTempDir('filestruct-rootfile');
        file_put_contents($root . '/a.txt', 'x');

        $fs = new FileStruct($root);

        $seen = [];
        $fs->collectStructure('.', function (string $abs, int $depth, string $rel) use (&$seen): void {
            $seen[] = [$abs, $depth, $rel];
        });

        self::assertCount(1, $seen);
        self::assertSame(0, $seen[0][1]);
        self::assertSame('a.txt', $seen[0][2]);
    }

    /**
     * Тест: вложенность 3 уровней должна корректно отражаться в depth.
     */
    public function testCollectStructureDepthNested(): void
    {
        $root = $this->makeTempDir('filestruct-depth');
        mkdir($root . '/d1/d2', 0777, true);
        file_put_contents($root . '/d1/d2/f.txt', 'x');

        $fs = new FileStruct($root);

        $depth = null;
        $rel = null;
        $fs->collectStructure('.', function (string $_abs, int $d, string $r) use (&$depth, &$rel): void {
            $depth = $d;
            $rel = $r;
        });

        self::assertSame(2, $depth);
        self::assertSame('d1/d2/f.txt', $rel);
    }

    /**
     * Тест: файлы с пробелами и unicode в именах должны корректно попадать в структуру.
     */
    public function testCollectStructureUnicodeAndSpaces(): void
    {
        $root = $this->makeTempDir('filestruct-unicode');
        mkdir($root . '/dir space', 0777, true);
        file_put_contents($root . '/dir space/файл.txt', 'x');

        $fs = new FileStruct($root);

        $paths = [];
        $fs->collectStructure('.', function (string $_abs, int $_depth, string $rel) use (&$paths): void {
            $paths[] = $rel;
        });

        self::assertSame(['dir space/файл.txt'], $paths);
    }

    /**
     * Тест: include маски должны отфильтровать файлы (оставить только подходящие).
     */
    public function testCollectStructureIncludeMasks(): void
    {
        $root = $this->makeTempDir('filestruct-include');
        file_put_contents($root . '/a.txt', 'x');
        file_put_contents($root . '/b.log', 'x');

        $fs = (new FileStruct($root))->setInclude(['*.txt']);

        $paths = [];
        $fs->collectStructure('.', function (string $_abs, int $_depth, string $rel) use (&$paths): void {
            $paths[] = $rel;
        });

        self::assertSame(['a.txt'], $paths);
    }

    /**
     * Тест: exclude маски должны исключить файлы из структуры.
     */
    public function testCollectStructureExcludeMasks(): void
    {
        $root = $this->makeTempDir('filestruct-exclude');
        file_put_contents($root . '/a.txt', 'x');
        file_put_contents($root . '/b.log', 'x');

        $fs = (new FileStruct($root))->setExclude(['*.log']);

        $paths = [];
        $fs->collectStructure('.', function (string $_abs, int $_depth, string $rel) use (&$paths): void {
            $paths[] = $rel;
        });

        sort($paths);
        self::assertSame(['a.txt'], $paths);
    }

    /**
     * Тест: writeFile должен создавать TSV и включать md5/mtime для файла 0 байт.
     */
    public function testWriteFileZeroByteFile(): void
    {
        $root = $this->makeTempDir('filestruct-write');
        file_put_contents($root . '/zero.bin', '');

        $out = $root . '/out.tsv';

        $fs = new FileStruct($root);
        $fs->writeFile('.', $out);

        $content = file_get_contents($out);
        self::assertNotFalse($content);
        self::assertNotSame('', $content);

        $lines = array_values(array_filter(explode("\n", (string) $content), static fn (string $l): bool => $l !== ''));
        self::assertCount(1, $lines);

        $parts = explode("\t", $lines[0]);
        self::assertCount(6, $parts);
        self::assertSame('1', $parts[0]);
        self::assertSame('zero.bin', $parts[1]);
        self::assertSame('0', $parts[2]);
        self::assertSame((string) filesize($root . '/zero.bin'), $parts[3]);
        self::assertSame((string) filemtime($root . '/zero.bin'), $parts[4]);
        self::assertSame(hash('sha256', ''), $parts[5]);
    }

    /**
     * Тест: попытка обойти директорию вне корня должна приводить к исключению.
     */
    public function testCollectStructureOutsideRootThrows(): void
    {
        $root = $this->makeTempDir('filestruct-outside');
        $outside = $this->makeTempDir('filestruct-outside-target');

        $fs = new FileStruct($root);

        $this->expectException(FileStructException::class);
        $fs->collectStructure($outside, static function (): void {
        });
    }

    /**
     * Создаёт временную директорию для тестов.
     *
     * @param string $prefix Префикс имени директории.
     *
     * @return string Абсолютный путь.
     */
    private function makeTempDir(string $prefix): string
    {
        $base = sys_get_temp_dir() . '/kb-tests';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }

        $dir = $base . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        return $dir;
    }
}
