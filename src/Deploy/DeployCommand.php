<?php
/**
 * @author matthieu.napoli
 */

namespace Deploy;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
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
                'version',
                InputArgument::REQUIRED,
                "Which version to deploy (tag or branch name)."
            )
        ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                "Path to deploy the application into.",
                getcwd()
            )
        ->addOption(
                'update-db',
                null,
                InputOption::VALUE_NONE,
                "If set, 'build update' will be run and the DB will be updated. If not, the user will be asked."
            )
        ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                "If set, do not run any command. This is appropriate for testing."
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');
        $version = $input->getArgument('version');
        $path = $input->getArgument('path');
        $forceUpdateDB = $input->getOption('update-db');

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Deploying version $version to $path");
        }

        // Detecting git
        $repository = new \PHPGit_Repository($path);

        // Switch to the branch/tag
        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Running: git checkout $version");
        }
        if (! $dryRun) {
            $repository->git("checkout $version");
        }

        // Update to head
        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Running: git pull");
        }
        if (! $dryRun) {
            $repository->git("pull");
        }

        // Run Composer
        $this->runComposer($path, $input, $output);

        // Run build update
        $this->runUpdateDB($path, $forceUpdateDB, $input, $output);

        // Restarting workers
    }

    /**
     * Composer dependencies installation
     *
     * @param string          $path
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @throws \RuntimeException
     */
    private function runComposer($path, InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Updating project dependencies with Composer");
        }

        $command = "composer install --no-dev $path";
        $outputArray = [];
        $returnStatus = null;

        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Running: $command");
        }

        if (! $dryRun) {
            exec($command, $outputArray, $returnStatus);
        }

        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Return status: $returnStatus");
        }

        // Error
        if ($returnStatus != 0) {
            /** @var FormatterHelper $formatter */
            $formatter = $this->getHelperSet()->get('formatter');

            $output->writeln("<error>Error while running Composer install</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            throw new \RuntimeException();
        }
    }

    /**
     * Database update (Doctrine)
     *
     * @param string          $path
     * @param boolean         $forceUpdateDB
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @throws \RuntimeException
     */
    private function runUpdateDB($path, $forceUpdateDB, InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');

        // If the user didn't ask to update the db, we ask him
        if (!$forceUpdateDB) {
            /** @var DialogHelper $dialog */
            $dialog = $this->getHelperSet()->get('dialog');

            $confirmation = $dialog->askConfirmation(
                $output,
                '<question>Run build update to update Database?</question>',
                false
            );

            if (!$confirmation) {
                return;
            }
        }

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Running build update (Doctrine DB update)");
        }

        $command = "php $path/scripts/build/build.php update";
        $outputArray = [];
        $returnStatus = null;

        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Running: $command");
        }

        if (! $dryRun) {
            exec($command, $outputArray, $returnStatus);
        }

        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Return status: $returnStatus");
        }

        // Error
        if ($returnStatus != 0) {
            /** @var FormatterHelper $formatter */
            $formatter = $this->getHelperSet()->get('formatter');

            $output->writeln("<error>Error while running DB update</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            throw new \RuntimeException();
        }
    }
}
