<?php

declare(strict_types=1);

namespace Tests;

use app\kb\classes\FileStruct;
use app\kb\classes\FileStructIndex;
use app\kb\classes\exceptions\FileStructEntryNotFoundException;
use app\kb\classes\exceptions\FileStructException;
use app\kb\classes\exceptions\FileStructFileChangedException;
use app\kb\classes\exceptions\FileStructFileMissingException;
use PHPUnit\Framework\TestCase;

/**
 * Тесты индекса структуры файлов {@see FileStructIndex}.
 */
final class FileStructIndexTest extends TestCase
{
    /**
     * Тест: индекс должен уметь читать структуру и находить запись по точному пути.
     */
    public function testReadAndGetByPath(): void
    {
        $root = $this->makeTempDir('filestructindex-basic');
        mkdir($root . '/dir', 0777, true);
        file_put_contents($root . '/dir/a.txt', 'x');

        $structure = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $structure);

        $index = FileStructIndex::fromStructureFile($root, $structure);

        self::assertTrue($index->hasPath('dir/a.txt'));
        $entry = $index->getByPath('dir/a.txt');
        self::assertSame('dir/a.txt', $entry->getRelativePath());
    }

    /**
     * Тест: числовые имена файлов не должны ломать индекс (ключи массива не должны становиться int).
     */
    public function testNumericFileNameIsHandled(): void
    {
        $root = $this->makeTempDir('filestructindex-numeric');
        file_put_contents($root . '/123', 'x');

        $structure = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $structure);

        $index = FileStructIndex::fromStructureFile($root, $structure);
        self::assertTrue($index->hasPath('123'));
        self::assertSame('123', $index->getByPath('123')->getRelativePath());
    }

    /**
     * Тест: запрос отсутствующего пути должен приводить к исключению.
     */
    public function testGetByPathMissingThrows(): void
    {
        $root = $this->makeTempDir('filestructindex-missing');
        file_put_contents($root . '/a.txt', 'x');

        $structure = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $structure);

        $index = FileStructIndex::fromStructureFile($root, $structure);

        $this->expectException(FileStructEntryNotFoundException::class);
        $index->getByPath('nope.txt');
    }

    /**
     * Тест: поиск по префиксу должен отдавать все записи в подкаталоге.
     */
    public function testFindByPrefix(): void
    {
        $root = $this->makeTempDir('filestructindex-prefix');
        mkdir($root . '/src/classes', 0777, true);
        file_put_contents($root . '/src/a.php', '1');
        file_put_contents($root . '/src/classes/b.php', '2');
        file_put_contents($root . '/other.txt', '3');

        $structure = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $structure);

        $index = FileStructIndex::fromStructureFile($root, $structure);

        $found = [];
        $index->findByPrefix('src/', function ($entry) use (&$found): void {
            $found[] = $entry->getRelativePath();
        });

        sort($found);
        self::assertSame(['src/a.php', 'src/classes/b.php'], $found);
    }

    /**
     * Тест: validatePath для корректного файла должен проходить без исключений.
     */
    public function testValidatePathOk(): void
    {
        $root = $this->makeTempDir('filestructindex-validate-ok');
        file_put_contents($root . '/a.txt', 'x');

        $structure = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $structure);

        $index = FileStructIndex::fromStructureFile($root, $structure);

        $index->validatePath('a.txt');
        self::assertTrue(true);
    }

    /**
     * Тест: если файл удалён после записи структуры, validatePath должен бросить FileStructFileMissingException.
     */
    public function testValidatePathMissingFileThrows(): void
    {
        $root = $this->makeTempDir('filestructindex-validate-missing');
        file_put_contents($root . '/a.txt', 'x');

        $structure = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $structure);

        unlink($root . '/a.txt');

        $index = FileStructIndex::fromStructureFile($root, $structure);

        $this->expectException(FileStructFileMissingException::class);
        $index->validatePath('a.txt');
    }

    /**
     * Тест: если файл изменён, validatePath должен бросить FileStructFileChangedException.
     */
    public function testValidatePathChangedFileThrows(): void
    {
        $root = $this->makeTempDir('filestructindex-validate-changed');
        file_put_contents($root . '/a.txt', 'x');

        $structure = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $structure);

        // Меняем содержимое. Для надёжного отличия mtime (секунды) ждём смены секунды.
        $this->waitForNextSecond();
        file_put_contents($root . '/a.txt', 'y');

        $index = FileStructIndex::fromStructureFile($root, $structure);

        $this->expectException(FileStructFileChangedException::class);
        $index->validatePath('a.txt');
    }

    /**
     * Тест: «плохой» TSV (неверное количество колонок) должен приводить к FileStructException.
     */
    public function testBadTsvWrongColumnCountThrows(): void
    {
        $root = $this->makeTempDir('filestructindex-badtsv-cols');
        $structure = $root . '/structure.tsv';
        file_put_contents($structure, "a.txt\t0\t123\t456\n");

        $this->expectException(FileStructException::class);
        FileStructIndex::fromStructureFile($root, $structure);
    }

    /**
     * Тест: «плохой» TSV (невалидный sha256) должен приводить к FileStructException.
     */
    public function testBadTsvInvalidMd5Throws(): void
    {
        $root = $this->makeTempDir('filestructindex-badtsv-md5');
        $structure = $root . '/structure.tsv';
        file_put_contents($structure, "1\ta.txt\t0\t1\t123\tNOT_MD5\n");

        $this->expectException(FileStructException::class);
        FileStructIndex::fromStructureFile($root, $structure);
    }

    /**
     * Тест: «плохой» TSV (path traversal) должен приводить к FileStructException.
     */
    public function testBadTsvTraversalPathThrows(): void
    {
        $root = $this->makeTempDir('filestructindex-badtsv-traversal');
        $structure = $root . '/structure.tsv';
        file_put_contents($structure, "1\t../a.txt\t0\t1\t123\t" . hash('sha256', '') . "\n");

        $this->expectException(FileStructException::class);
        FileStructIndex::fromStructureFile($root, $structure);
    }

    /**
     * Тест: поиск строки по номеру должен возвращать полную строку из TSV.
     */
    public function testFindLineByNumberReturnsLine(): void
    {
        $root = $this->makeTempDir('filestructindex-findline');
        mkdir($root . '/dir space', 0777, true);
        // Делаем много файлов, чтобы поиск работал не только по первой строке.
        for ($i = 0; $i < 40; $i++) {
            file_put_contents($root . '/dir space/файл-' . $i . '.txt', str_repeat('x', $i));
        }

        $structure = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $structure);

        $index = FileStructIndex::fromStructureFile($root, $structure);

        $line = $index->findLineByNumber(1);
        self::assertNotNull($line);
        self::assertStringStartsWith("1\t", (string) $line);

        $line20 = $index->findLineByNumber(20);
        self::assertNotNull($line20);
        self::assertStringStartsWith("20\t", (string) $line20);

        $missing = $index->findLineByNumber(99999);
        self::assertNull($missing);
    }

    /**
     * Ожидает наступления следующей секунды, чтобы `filemtime()` гарантированно изменился.
     *
     * @return void
     */
    private function waitForNextSecond(): void
    {
        $start = time();
        while (time() === $start) {
            usleep(10_000);
        }
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
