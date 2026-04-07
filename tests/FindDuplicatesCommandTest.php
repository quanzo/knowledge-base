<?php

declare(strict_types=1);

namespace Tests;

use app\kb\classes\FileStruct;
use app\kb\classes\command\FindDuplicatesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Тесты консольной команды {@see FindDuplicatesCommand}.
 */
final class FindDuplicatesCommandTest extends TestCase
{
    /**
     * Тест: команда должна находить дубликаты по sha256 и выводить группу.
     */
    public function testFindsDuplicates(): void
    {
        $root = $this->makeTempDir('dupscmd-basic');
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/a.txt', 'same');
        file_put_contents($root . '/src/b.txt', 'same');
        file_put_contents($root . '/src/c.txt', 'diff');

        $index = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $index);

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'index' => $index,
        ]);

        self::assertSame(0, $code);
        self::assertStringContainsString(hash('sha256', 'same'), $tester->getDisplay());
        self::assertStringContainsString('src/a.txt', $tester->getDisplay());
        self::assertStringContainsString('src/b.txt', $tester->getDisplay());
        self::assertStringNotContainsString('src/c.txt (2 files)', $tester->getDisplay());
    }

    /**
     * Тест: если дубликатов нет, команда должна завершаться успешно и сообщать об этом.
     */
    public function testNoDuplicates(): void
    {
        $root = $this->makeTempDir('dupscmd-none');
        file_put_contents($root . '/a.txt', 'a');
        file_put_contents($root . '/b.txt', 'b');

        $index = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $index);

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'index' => $index,
        ]);

        self::assertSame(0, $code);
        self::assertStringContainsString('No duplicates found', $tester->getDisplay());
    }

    /**
     * Тест: include/exclude маски должны фильтровать файлы, участвующие в поиске дубликатов.
     */
    public function testHonorsIncludeExcludeMasks(): void
    {
        $root = $this->makeTempDir('dupscmd-filters');
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/docs', 0777, true);
        file_put_contents($root . '/src/a.txt', 'same');
        file_put_contents($root . '/src/b.txt', 'same');
        file_put_contents($root . '/docs/a.md', 'same');

        $index = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $index);

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'index' => $index,
            '--include' => ['src/*'],
            '--exclude' => ['*.md'],
        ]);

        self::assertSame(0, $code);
        self::assertStringContainsString(hash('sha256', 'same'), $tester->getDisplay());
        self::assertStringContainsString('src/a.txt', $tester->getDisplay());
        self::assertStringContainsString('src/b.txt', $tester->getDisplay());
        self::assertStringNotContainsString('docs/a.md', $tester->getDisplay());
    }

    /**
     * Создаёт тестер команды.
     *
     * @return CommandTester
     */
    private function makeTester(): CommandTester
    {
        $app = new Application('test', '0.0.0');
        $app->add(new FindDuplicatesCommand());

        $command = $app->find('kb:filestruct:dups');
        return new CommandTester($command);
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
