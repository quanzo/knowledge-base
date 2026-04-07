<?php

declare(strict_types=1);

namespace Tests;

use app\kb\classes\FileStruct;
use app\kb\classes\command\DiffIndexCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Тесты консольной команды {@see DiffIndexCommand}.
 */
final class DiffIndexCommandTest extends TestCase
{
    /**
     * Тест: команда должна показывать добавленные/удалённые/изменённые файлы.
     */
    public function testShowsAddedRemovedModified(): void
    {
        $root = $this->makeTempDir('diffcmd-basic');
        mkdir($root . '/src', 0777, true);

        file_put_contents($root . '/src/a.txt', 'one');
        file_put_contents($root . '/src/b.txt', 'two');

        $left = $root . '/left.tsv';
        (new FileStruct($root))->writeFile('.', $left);

        $this->waitForNextSecond();
        file_put_contents($root . '/src/a.txt', 'ONE'); // modified
        unlink($root . '/src/b.txt'); // removed
        file_put_contents($root . '/src/c.txt', 'three'); // added

        $right = $root . '/right.tsv';
        (new FileStruct($root))->writeFile('.', $right);

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'left' => $left,
            'right' => $right,
        ]);

        self::assertSame(0, $code);
        $out = $tester->getDisplay();
        self::assertStringContainsString("MODIFIED\tsrc/a.txt\t", $out);
        self::assertStringContainsString("REMOVED\tsrc/b.txt\t", $out);
        self::assertStringContainsString("ADDED\tsrc/c.txt\t", $out);
    }

    /**
     * Тест: include/exclude фильтры должны ограничивать вывод.
     */
    public function testHonorsIncludeExclude(): void
    {
        $root = $this->makeTempDir('diffcmd-filters');
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/docs', 0777, true);

        file_put_contents($root . '/src/a.txt', 'one');
        file_put_contents($root . '/docs/a.md', 'one');

        $left = $root . '/left.tsv';
        (new FileStruct($root))->writeFile('.', $left);

        $this->waitForNextSecond();
        file_put_contents($root . '/src/a.txt', 'ONE');
        file_put_contents($root . '/docs/a.md', 'ONE');

        $right = $root . '/right.tsv';
        (new FileStruct($root))->writeFile('.', $right);

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'left' => $left,
            'right' => $right,
            '--include' => ['src/*'],
            '--exclude' => ['docs/*'],
        ]);

        self::assertSame(0, $code);
        $out = $tester->getDisplay();
        self::assertStringContainsString("MODIFIED\tsrc/a.txt\t", $out);
        self::assertStringNotContainsString("docs/a.md", $out);
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
     * Создаёт тестер команды.
     *
     * @return CommandTester
     */
    private function makeTester(): CommandTester
    {
        $app = new Application('test', '0.0.0');
        $app->add(new DiffIndexCommand());

        $command = $app->find('kb:filestruct:diff');
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
