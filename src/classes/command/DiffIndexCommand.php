<?php

declare(strict_types=1);

namespace app\kb\classes\command;

use app\kb\classes\FileStructIndex;
use app\kb\classes\dto\FileStructDiffItem;
use app\kb\classes\exceptions\FileStructException;
use app\kb\enums\FileStructDiffStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Команда сравнения двух TSV-индексов для одной директории.
 *
 * Загружает два индекса (обычно старый и новый), сравнивает их по `relativePath`
 * и выводит отличия: ADDED/REMOVED/MODIFIED.
 *
 * Поддерживает include/exclude фильтры по `relativePath`.
 *
 * Пример:
 *
 * ```bash
 * ./bin/console kb:filestruct:diff /repo /repo/index-old.tsv /repo/index-new.tsv --include="src/*" --exclude="vendor/*"
 * ```
 */
final class DiffIndexCommand extends Command
{
    protected static $defaultName = 'kb:filestruct:diff';

    protected function configure(): void
    {
        $this
            ->setDescription('Сравнивает два индекса структуры файлов для одной директории')
            ->addArgument('dir', InputArgument::REQUIRED, 'Путь к директории (корень структуры)')
            ->addArgument('left', InputArgument::REQUIRED, 'Путь к TSV индексу (левый/старый)')
            ->addArgument('right', InputArgument::REQUIRED, 'Путь к TSV индексу (правый/новый)')
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
     * Выполняет сравнение индексов.
     *
     * @param InputInterface $input Аргументы и опции.
     * @param OutputInterface $output Вывод.
     *
     * @return int Код завершения.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = (string) $input->getArgument('dir');
        $leftFile = (string) $input->getArgument('left');
        $rightFile = (string) $input->getArgument('right');

        /** @var string[] $include */
        $include = $input->getOption('include');
        /** @var string[] $exclude */
        $exclude = $input->getOption('exclude');

        $include = $include !== [] ? $include : null;
        $exclude = $exclude !== [] ? $exclude : null;

        try {
            $left = FileStructIndex::fromStructureFile($dir, $leftFile);
            $right = FileStructIndex::fromStructureFile($dir, $rightFile);
        } catch (FileStructException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $added = 0;
        $removed = 0;
        $modified = 0;

        $output->writeln('STATUS' . "\t" . 'PATH' . "\t" . 'DETAILS');

        $left->diffWith($right, function (FileStructDiffItem $item) use (
            $output,
            $include,
            $exclude,
            &$added,
            &$removed,
            &$modified
        ): void {
            $path = $item->getRelativePath();

            if ($include !== null && !$this->matchesAnyMask($path, $include)) {
                return;
            }

            if ($exclude !== null && $this->matchesAnyMask($path, $exclude)) {
                return;
            }

            $status = $item->getStatus();
            $details = $this->formatDetails($item);

            if ($status === FileStructDiffStatus::Added) {
                $added++;
            } elseif ($status === FileStructDiffStatus::Removed) {
                $removed++;
            } else {
                $modified++;
            }

            $output->writeln($status->value . "\t" . $path . "\t" . $details);
        });

        $output->writeln('');
        $output->writeln(sprintf('Added: %d; Removed: %d; Modified: %d', $added, $removed, $modified));

        return ($added + $removed + $modified) > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Форматирует описание отличий для вывода.
     *
     * @param FileStructDiffItem $item
     *
     * @return string
     */
    private function formatDetails(FileStructDiffItem $item): string
    {
        $status = $item->getStatus();

        if ($status === FileStructDiffStatus::Added) {
            $right = $item->getRight();
            if ($right === null) {
                return '-';
            }
            return sprintf('size=%d mtime=%d sha256=%s', $right->getSizeBytes(), $right->getMtimeUnix(), $right->getSha256());
        }

        if ($status === FileStructDiffStatus::Removed) {
            $left = $item->getLeft();
            if ($left === null) {
                return '-';
            }
            return sprintf('size=%d mtime=%d sha256=%s', $left->getSizeBytes(), $left->getMtimeUnix(), $left->getSha256());
        }

        $left = $item->getLeft();
        $right = $item->getRight();
        if ($left === null || $right === null) {
            return '-';
        }

        $parts = [];
        if ($item->isSizeChanged()) {
            $parts[] = sprintf('size:%d->%d', $left->getSizeBytes(), $right->getSizeBytes());
        }
        if ($item->isMtimeChanged()) {
            $parts[] = sprintf('mtime:%d->%d', $left->getMtimeUnix(), $right->getMtimeUnix());
        }
        if ($item->isSha256Changed()) {
            $parts[] = sprintf('sha256:%s->%s', $left->getSha256(), $right->getSha256());
        }

        return $parts !== [] ? implode(' ', $parts) : '-';
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
