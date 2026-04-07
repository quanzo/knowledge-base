<?php

declare(strict_types=1);

namespace Tests;

use app\kb\classes\FileStruct;
use app\kb\classes\command\FindBySizeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Тесты консольной команды {@see FindBySizeCommand}.
 */
final class FindBySizeCommandTest extends TestCase
{
    /**
     * Тест: команда должна находить файлы в диапазоне размера.
     */
    public function testFindsFilesBySizeRange(): void
    {
        $root = $this->makeTempDir('sizecmd-basic');
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/a.txt', '12345'); // 5 bytes
        file_put_contents($root . '/src/b.txt', 'x'); // 1 byte

        $index = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $index);

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'index' => $index,
            '--from' => '2',
            '--to' => '10',
        ]);

        self::assertSame(0, $code);
        self::assertStringContainsString("\tsrc/a.txt\t", $tester->getDisplay());
        self::assertStringNotContainsString("\tsrc/b.txt\t", $tester->getDisplay());
    }

    /**
     * Тест: include/exclude должны фильтровать результаты.
     */
    public function testHonorsIncludeExclude(): void
    {
        $root = $this->makeTempDir('sizecmd-filters');
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/docs', 0777, true);
        file_put_contents($root . '/src/a.txt', '123'); // 3 bytes
        file_put_contents($root . '/docs/a.txt', '123'); // 3 bytes

        $index = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $index);

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'index' => $index,
            '--from' => '0',
            '--to' => '10',
            '--include' => ['src/*'],
            '--exclude' => ['docs/*'],
        ]);

        self::assertSame(0, $code);
        self::assertStringContainsString("\tsrc/a.txt\t", $tester->getDisplay());
        self::assertStringNotContainsString("\tdocs/a.txt\t", $tester->getDisplay());
    }

    /**
     * Тест: если совпадений нет, команда должна возвращать FAILURE.
     */
    public function testNoMatchesReturnsFailure(): void
    {
        $root = $this->makeTempDir('sizecmd-nomatch');
        file_put_contents($root . '/a.txt', 'x');

        $index = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $index);

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'index' => $index,
            '--from' => '10',
            '--to' => '20',
        ]);

        self::assertSame(1, $code);
        self::assertStringContainsString('Matched: 0', $tester->getDisplay());
    }

    /**
     * Создаёт тестер команды.
     *
     * @return CommandTester
     */
    private function makeTester(): CommandTester
    {
        $app = new Application('test', '0.0.0');
        $app->add(new FindBySizeCommand());

        $command = $app->find('kb:filestruct:size');
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
