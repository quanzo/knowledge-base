<?php

declare(strict_types=1);

namespace app\kb\classes\command;

use app\kb\classes\FileStruct;
use app\kb\classes\exceptions\FileStructException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Команда для сохранения структуры файлов (дерева проекта) в TSV-файл.
 *
 * Сохраняет для каждого файла:
 * - относительный путь от корня структуры
 * - depth (уровень вложенности)
 * - mtime (Unix timestamp, секунды)
 * - sha256
 *
 * Поддерживает include/exclude маски (glob) по `relativePath`.
 *
 * Пример:
 *
 * ```bash
 * ./bin/console kb:filestruct:dump /repo /repo/structure.tsv --include="src/*.php" --include="docs/*.md" --exclude="vendor/*"
 *
 * ```
 */
final class DumpCommand extends Command
{
    protected static $defaultName = 'kb:filestruct:dump';

    protected function configure(): void
    {
        $this
            ->setDescription('Сохраняет дерево файлов в TSV-файл структуры')
            ->addArgument('dir', InputArgument::REQUIRED, 'Путь к директории (корень структуры)')
            ->addArgument('out', InputArgument::REQUIRED, 'Путь к TSV-файлу, куда сохранить структуру')
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
     * Выполняет сохранение структуры в файл.
     *
     * @param InputInterface $input Аргументы и опции команды.
     * @param OutputInterface $output Вывод команды.
     *
     * @return int Код завершения.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = (string) $input->getArgument('dir');
        $out = (string) $input->getArgument('out');

        /** @var string[] $include */
        $include = $input->getOption('include');
        /** @var string[] $exclude */
        $exclude = $input->getOption('exclude');

        $include = $include !== [] ? $include : null;
        $exclude = $exclude !== [] ? $exclude : null;

        try {
            $fs = (new FileStruct($dir))
                ->setInclude($include)
                ->setExclude($exclude);

            $fs->writeFile('.', $out);
        } catch (FileStructException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Structure saved: %s</info>', $out));
        return Command::SUCCESS;
    }
}
