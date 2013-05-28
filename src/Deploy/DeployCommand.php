<?php
/**
 * @author matthieu.napoli
 */

namespace Deploy;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deploy command
 */
class DeployCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
        ->setName('deploy')
        ->setDescription('Deploy an application')
        ->addArgument(
                'application',
                InputArgument::REQUIRED,
                'Which application to deploy'
            )
        ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Which version to deploy'
            )
        ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to deploy the application into'
            )
        ->addOption(
                'verbose',
                'v',
                InputOption::VALUE_NONE,
                'If set, prints additional information'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('application');
        if ($name) {
            $text = 'Hello ' . $name;
        } else {
            $text = 'Hello';
        }

        if ($input->getOption('verbose')) {
            $text = strtoupper($text);
        }

        $output->writeln($text);
    }
}
