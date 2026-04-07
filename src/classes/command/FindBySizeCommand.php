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
 * Команда поиска файлов в индексе по размеру.
 *
 * Ищет записи в TSV-индексе, у которых размер файла попадает в диапазон
 * [`from`, `to`] (в байтах). Поддерживает include/exclude маски по relativePath.
 *
 * Пример:
 *
 * ```bash
 * ./bin/console kb:filestruct:size /repo /repo/structure.tsv --from=0 --to=1024 --include="src/*" --exclude="*.log"
 * ```
 */
final class FindBySizeCommand extends Command
{
    protected static $defaultName = 'kb:filestruct:size';

    protected function configure(): void
    {
        $this
            ->setDescription('Ищет файлы в индексе по диапазону размера')
            ->addArgument('dir', InputArgument::REQUIRED, 'Путь к директории (корень структуры)')
            ->addArgument('index', InputArgument::REQUIRED, 'Путь к TSV-файлу индекса')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Минимальный размер в байтах (включительно)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Максимальный размер в байтах (включительно)')
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
     * Выполняет поиск по размеру.
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

        $from = $this->parseNullableInt($input->getOption('from'));
        $to = $this->parseNullableInt($input->getOption('to'));

        if ($from !== null && $from < 0) {
            $output->writeln('<error>--from must be >= 0</error>');
            return Command::FAILURE;
        }

        if ($to !== null && $to < 0) {
            $output->writeln('<error>--to must be >= 0</error>');
            return Command::FAILURE;
        }

        if ($from !== null && $to !== null && $from > $to) {
            $output->writeln('<error>--from must be <= --to</error>');
            return Command::FAILURE;
        }

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

        $min = $from ?? 0;
        $max = $to ?? PHP_INT_MAX;

        $matched = 0;
        $output->writeln('SIZE' . "\t" . 'PATH' . "\t" . 'SHA256' . "\t" . 'MTIME_UTC');

        $index->forEachEntry(function (FileStructEntry $entry) use ($output, $min, $max, $include, $exclude, &$matched): void {
            if ($entry->getSizeBytes() < $min || $entry->getSizeBytes() > $max) {
                return;
            }

            if ($include !== null && !$this->matchesAnyMask($entry->getRelativePath(), $include)) {
                return;
            }

            if ($exclude !== null && $this->matchesAnyMask($entry->getRelativePath(), $exclude)) {
                return;
            }

            $matched++;
            $output->writeln(
                $entry->getSizeBytes()
                . "\t" . $entry->getRelativePath()
                . "\t" . $entry->getSha256()
                . "\t" . $entry->getMtimeUtcString()
            );
        });

        $output->writeln('');
        $output->writeln(sprintf('Matched: %d', $matched));

        return $matched > 0 ? Command::SUCCESS : Command::FAILURE;
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

    /**
     * Парсит опцию в int или null.
     *
     * @param mixed $value Значение опции.
     *
     * @return int|null
     */
    private function parseNullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;
        if ($value === '') {
            return null;
        }

        if (preg_match('/^-?[0-9]+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
