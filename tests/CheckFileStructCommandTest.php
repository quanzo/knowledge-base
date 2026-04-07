<?php

declare(strict_types=1);

namespace Tests;

use app\kb\classes\FileStruct;
use app\kb\classes\command\CheckFileStructCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Тесты консольной команды {@see CheckFileStructCommand}.
 */
final class CheckFileStructCommandTest extends TestCase
{
    /**
     * Тест: команда должна находить файлы по маске и возвращать SUCCESS при валидных файлах.
     */
    public function testCheckOk(): void
    {
        $root = $this->makeTempDir('checkcmd-ok');
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/a.php', 'x');

        $index = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $index);

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'index' => $index,
            '--mask' => ['src/*'],
        ]);

        self::assertSame(0, $code);
        self::assertStringContainsString("OK\tsrc/a.php", $tester->getDisplay());
        self::assertStringContainsString('Found: 1', $tester->getDisplay());
    }

    /**
     * Тест: команда должна возвращать FAILURE, если файл изменён после записи индекса.
     */
    public function testCheckChanged(): void
    {
        $root = $this->makeTempDir('checkcmd-changed');
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/a.php', 'x');

        $index = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $index);

        $this->waitForNextSecond();
        file_put_contents($root . '/src/a.php', 'y');

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'index' => $index,
            '--mask' => ['src/*'],
        ]);

        self::assertSame(1, $code);
        self::assertStringContainsString("CHANGED\tsrc/a.php", $tester->getDisplay());
        self::assertStringContainsString('Changed: 1', $tester->getDisplay());
    }

    /**
     * Тест: команда должна возвращать FAILURE, если по маске ничего не найдено.
     */
    public function testCheckNoMatches(): void
    {
        $root = $this->makeTempDir('checkcmd-nomatch');
        file_put_contents($root . '/a.txt', 'x');

        $index = $root . '/structure.tsv';
        (new FileStruct($root))->writeFile('.', $index);

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'index' => $index,
            '--mask' => ['src/*'],
        ]);

        self::assertSame(1, $code);
        self::assertStringContainsString('No files matched', $tester->getDisplay());
    }

    /**
     * Тест: отсутствие --mask должно приводить к ошибке выполнения.
     */
    public function testMaskIsRequired(): void
    {
        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => __DIR__,
            'index' => __DIR__ . '/index.tsv',
        ]);

        self::assertSame(1, $code);
        self::assertStringContainsString('At least one --mask is required', $tester->getDisplay());
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
        $app->add(new CheckFileStructCommand());

        $command = $app->find('kb:filestruct:check');
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
