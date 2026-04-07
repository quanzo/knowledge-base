<?php

namespace app\kb\classes\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Простая команда, которая выводит приветствие.
 */
class HelloCommand extends Command
{
    protected static $defaultName = 'hello';

    protected function configure(): void
    {
        $this
            ->setDescription('Выводит приветствие')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Ваше имя', 'World');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('name');
        $output->writeln("Hello, {$name}!");
        return Command::SUCCESS;
    }
}
