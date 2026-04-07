<?php

declare(strict_types=1);

namespace app\kb\classes\command;

use app\kb\classes\FileStructIndex;
use app\kb\classes\dto\FileStructEntry;
use app\kb\classes\exceptions\FileStructException;
use app\kb\classes\exceptions\FileStructFileChangedException;
use app\kb\classes\exceptions\FileStructFileMissingException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Команда поиска файлов в индексе и проверки их статуса в реальной директории.
 *
 * Ищет в TSV-индексе записи по glob-маскам `relativePath` и для каждой найденной записи
 * проверяет наличие и целостность файла в реальном дереве (mtime в секундах и sha256).
 *
 * Отчёт выводится в stdout, итоговый код завершения:
 * - 0: все найденные файлы валидны
 * - 1: есть отсутствующие/изменённые файлы или произошла ошибка чтения индекса
 *
 * Пример:
 *
 * ```bash
 * ./bin/console kb:filestruct:check /repo /repo/structure.tsv --mask="src/*.php" --mask="docs/*.md"
 * ```
 */
final class CheckFileStructCommand extends Command
{
    protected static $defaultName = 'kb:filestruct:check';

    protected function configure(): void
    {
        $this
            ->setDescription('Ищет файлы в индексе и проверяет их статус в файловой системе')
            ->addArgument('dir', InputArgument::REQUIRED, 'Путь к директории (корень структуры)')
            ->addArgument('index', InputArgument::REQUIRED, 'Путь к TSV-файлу индекса')
            ->addOption(
                'mask',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Маски (glob) для поиска relativePath. Можно указывать несколько раз.'
            );
    }

    /**
     * Выполняет поиск и проверку файлов.
     *
     * @param InputInterface $input Аргументы и опции.
     * @param OutputInterface $output Вывод.
     *
     * @return int Код завершения.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = (string) $input->getArgument('dir');
        $indexFile = (string) $input->getArgument('index');

        /** @var string[] $masks */
        $masks = $input->getOption('mask');
        if ($masks === []) {
            $output->writeln('<error>At least one --mask is required.</error>');
            return Command::FAILURE;
        }

        try {
            $index = FileStructIndex::fromStructureFile($dir, $indexFile);
        } catch (FileStructException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $found = 0;
        $ok = 0;
        $missing = 0;
        $changed = 0;

        $output->writeln('STATUS' . "\t" . 'PATH' . "\t" . 'DETAILS');

        $index->validateByMasks(
            $masks,
            function (
                FileStructEntry $entry,
                ?FileStructException $error
            ) use (
                $output,
                &$found,
                &$ok,
                &$missing,
                &$changed
            ): void {
                $found++;

                if ($error === null) {
                    $ok++;
                    $output->writeln('OK' . "\t" . $entry->getRelativePath() . "\t" . '-');
                    return;
                }

                if ($error instanceof FileStructFileMissingException) {
                    $missing++;
                    $output->writeln('MISSING' . "\t" . $entry->getRelativePath() . "\t" . $error->getMessage());
                    return;
                }

                if ($error instanceof FileStructFileChangedException) {
                    $changed++;
                    $output->writeln('CHANGED' . "\t" . $entry->getRelativePath() . "\t" . $error->getMessage());
                    return;
                }

                $changed++;
                $output->writeln('ERROR' . "\t" . $entry->getRelativePath() . "\t" . $error->getMessage());
            }
        );

        $output->writeln('');
        $output->writeln(sprintf('Found: %d; OK: %d; Missing: %d; Changed: %d', $found, $ok, $missing, $changed));

        if ($found === 0) {
            $output->writeln('<comment>No files matched provided masks.</comment>');
            return Command::FAILURE;
        }

        return ($missing === 0 && $changed === 0) ? Command::SUCCESS : Command::FAILURE;
    }
}
