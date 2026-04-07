<?php

declare(strict_types=1);

namespace app\kb\classes\command;

use app\kb\classes\FileStructIndex;
use app\kb\classes\dto\FileStructEntry;
use app\kb\classes\exceptions\FileStructException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Команда поиска дубликатов файлов по SHA-256 в TSV-индексе.
 *
 * Группирует файлы по sha256 из индекса и выводит группы, в которых один и тот же хэш
 * встречается у нескольких файлов.
 *
 * Пример:
 *
 * ```bash
 * ./bin/console kb:filestruct:dups /repo /repo/structure.tsv --include="*.php" --include="*.js"
 * ```
 */
final class FindDuplicatesCommand extends Command
{
    protected static $defaultName = 'kb:filestruct:dups';

    protected function configure(): void
    {
        $this
            ->setDescription('Ищет дубликаты файлов по sha256 в файле индекса')
            ->addArgument('dir', InputArgument::REQUIRED, 'Путь к директории (корень структуры)')
            ->addArgument('index', InputArgument::REQUIRED, 'Путь к TSV-файлу индекса')
            ->addOption(
                'include',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Маски (glob) для включения файлов по relativePath. Можно указывать несколько раз.'
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Маски (glob) для исключения файлов по relativePath. Можно указывать несколько раз.'
            );
    }

    /**
     * Выполняет поиск дубликатов по sha256.
     *
     * @param InputInterface $input Аргументы команды.
     * @param OutputInterface $output Вывод.
     *
     * @return int Код завершения.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = (string) $input->getArgument('dir');
        $indexFile = (string) $input->getArgument('index');

        /** @var string[] $include */
        $include = $input->getOption('include');
        /** @var string[] $exclude */
        $exclude = $input->getOption('exclude');

        $include = $include !== [] ? $include : null;
        $exclude = $exclude !== [] ? $exclude : null;

        try {
            $index = FileStructIndex::fromStructureFile($dir, $indexFile);
        } catch (FileStructException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        /** @var array<string, string[]> $byHash */
        $byHash = [];

        $index->forEachEntry(function (FileStructEntry $entry) use (&$byHash, $include, $exclude): void {
            if ($include !== null && !$this->matchesAnyMask($entry->getRelativePath(), $include)) {
                return;
            }

            if ($exclude !== null && $this->matchesAnyMask($entry->getRelativePath(), $exclude)) {
                return;
            }

            $byHash[$entry->getSha256()][] = $entry->getRelativePath();
        });

        $duplicates = 0;
        foreach ($byHash as $sha256 => $paths) {
            if (count($paths) < 2) {
                continue;
            }

            $duplicates++;
            sort($paths);

            $output->writeln('');
            $output->writeln(sprintf('%s (%d files)', $sha256, count($paths)));
            foreach ($paths as $path) {
                $output->writeln(' - ' . $path);
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('Duplicate groups: %d', $duplicates));

        if ($duplicates === 0) {
            $output->writeln('<comment>No duplicates found.</comment>');
        }

        return Command::SUCCESS;
    }

    /**
     * Проверяет, совпадает ли путь хотя бы с одной glob-маской.
     *
     * Маски сопоставляются с `relativePath` (с `/`) через {@see fnmatch()}.
     * Для совместимости поддерживается `**`, которое трактуется как `*`.
     *
     * @param string $relativePath Относительный путь (с `/`).
     * @param string[] $masks Маски.
     *
     * @return bool
     */
    private function matchesAnyMask(string $relativePath, array $masks): bool
    {
        foreach ($masks as $mask) {
            $mask = str_replace('\\', '/', (string) $mask);
            $mask = str_replace('**', '*', $mask);
            if ($mask === '') {
                continue;
            }

            if (fnmatch($mask, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
