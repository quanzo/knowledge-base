<?php

declare(strict_types=1);

namespace Tests;

use app\kb\classes\command\DumpCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Тесты консольной команды {@see DumpCommand}.
 */
final class DumpCommandTest extends TestCase
{
    /**
     * Тест: команда должна создавать TSV-файл структуры и завершаться успешно.
     */
    public function testCommandCreatesStructureFile(): void
    {
        $root = $this->makeTempDir('dumpcmd-basic');
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/a.php', 'x');

        $out = $root . '/structure.tsv';

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'out' => $out,
        ]);

        self::assertSame(0, $code);
        self::assertFileExists($out);
        self::assertStringContainsString("src/a.php\t", (string) file_get_contents($out));
    }

    /**
     * Тест: include/exclude маски должны влиять на результат.
     */
    public function testCommandHonorsIncludeExcludeMasks(): void
    {
        $root = $this->makeTempDir('dumpcmd-filters');
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/a.php', 'x');
        file_put_contents($root . '/src/b.log', 'y');

        $out = $root . '/structure.tsv';

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => $root,
            'out' => $out,
            '--include' => ['src/*'],
            '--exclude' => ['*.log'],
        ]);

        self::assertSame(0, $code);
        $content = (string) file_get_contents($out);
        self::assertStringContainsString('src/a.php', $content);
        self::assertStringNotContainsString('src/b.log', $content);
    }

    /**
     * Тест: несуществующий корень структуры должен приводить к ошибке выполнения.
     */
    public function testCommandFailsOnMissingDir(): void
    {
        $out = $this->makeTempDir('dumpcmd-missing') . '/structure.tsv';

        $tester = $this->makeTester();
        $code = $tester->execute([
            'dir' => __DIR__ . '/__no_such_dir__',
            'out' => $out,
        ]);

        self::assertSame(1, $code);
        self::assertStringContainsString('Root directory not found', $tester->getDisplay());
    }

    /**
     * Создаёт тестер команды.
     *
     * @return CommandTester
     */
    private function makeTester(): CommandTester
    {
        $app = new Application('test', '0.0.0');
        $app->add(new DumpCommand());

        $command = $app->find('kb:filestruct:dump');
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
