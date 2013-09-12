<?php

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
 *
 * @author matthieu.napoli
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
                "Path to deploy the application into. Default is the current directory.",
                getcwd()
            )
        ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                "If set, do not run any command. This is appropriate for testing."
            )
        ->addOption(
                'update-db',
                null,
                InputOption::VALUE_NONE,
                "If set, 'build update' will be run and the DB will be updated. If not, the user will be asked."
            )
        ->addOption(
                'no-update-db',
                null,
                InputOption::VALUE_NONE,
                "If set, 'build update' will *not* be run. If not, the user will be asked."
            )
        ->addOption(
                'restart-worker',
                null,
                InputOption::VALUE_REQUIRED,
                "If set, the given Gearman worker will be restarted. If not, the user will be asked."
            )
        ->addOption(
                'no-restart-worker',
                null,
                InputOption::VALUE_NONE,
                "If set, the given Gearman worker will *not* be restarted. If not, the user will be asked."
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $version = $input->getArgument('version');
        $path = $input->getArgument('path');
        $forceUpdateDB = $input->getOption('update-db');
        $forceNoUpdateDB = $input->getOption('no-update-db');
        $forceRestartWorker = $input->getOption('restart-worker');
        $forceNoRestartWorker = $input->getOption('no-restart-worker');

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("<info>Deploying version $version to $path</info>");
        }

        // Run git update
        $returnStatus = $this->runGitUpdate($path, $version, $input, $output);
        if ($returnStatus > 0) {
            return $returnStatus;
        }

        // Restart apache
        $returnStatus = $this->restartApache($input, $output);
        if ($returnStatus > 0) {
            return $returnStatus;
        }

        // Clear translation cache
        $returnStatus = $this->clearTranslationCache($path, $input, $output);
        if ($returnStatus > 0) {
            return $returnStatus;
        }

        // Run Composer
        $returnStatus = $this->runComposer($path, $input, $output);
        if ($returnStatus > 0) {
            return $returnStatus;
        }

        // Run build update
        if (!$forceNoUpdateDB) {
            $returnStatus = $this->runUpdateDB($path, $forceUpdateDB, $input, $output);
            if ($returnStatus > 0) {
                return $returnStatus;
            }
        }

        // Restarting workers
        if (!$forceNoRestartWorker) {
            $returnStatus = $this->restartWorker($forceRestartWorker, $input, $output);
            if ($returnStatus > 0) {
                return $returnStatus;
            }
        }

        // Everything went fine
        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("<info>Deployment success</info>");
        }
        return 0;
    }

    /**
     * Update the git repository
     *
     * @param string          $path
     * @param string          $version
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    private function runGitUpdate($path, $version, InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');

        // Detecting git
        $command = "cd '$path' && git rev-parse";
        $outputArray = [];
        $returnStatus = null;
        if (! $dryRun) {
            exec($command, $outputArray, $returnStatus);
        }
        if ($returnStatus != 0) {
            $output->writeln("<error>The directory $path is not a git repository</error>");
            return 1;
        }

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Checking out the $version branch or tag");
        }

        // Switch to the branch/tag
        $command = "cd '$path' && git fetch origin 2>&1 && git checkout $version 2>&1";
        $outputArray = [];
        $returnStatus = null;

        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Running: $command");
        }

        if (! $dryRun) {
            exec($command, $outputArray, $returnStatus);
        }

        // Error
        if ($returnStatus != 0) {
            /** @var FormatterHelper $formatter */
            $formatter = $this->getHelperSet()->get('formatter');

            $output->writeln("<error>Error while checking out the git version</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        // If we are on a branch, merge the origin branch to update
        if (! $dryRun) {
            $command = "cd '$path' && git branch | grep '^*'";
            $lastLine = exec($command);
            $isOnBranch = strpos($lastLine, '(no branch)') === false;
            if ($isOnBranch) {
                // We are on a branch, we need to merge
                $command = "cd '$path' && git merge origin/$version 2>&1";
                $outputArray = [];
                $returnStatus = null;

                if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
                    $output->writeln("Running: $command");
                }

                if (! $dryRun) {
                    exec($command, $outputArray, $returnStatus);
                }

                // Error
                if ($returnStatus != 0) {
                    /** @var FormatterHelper $formatter */
                    $formatter = $this->getHelperSet()->get('formatter');
                    $output->writeln("<error>Error while updating  out the git branch $version</error>");
                    $output->writeln("Command used: $command");
                    $output->writeln($formatter->formatBlock($outputArray, 'error'));
                    return 1;
                }
            }
        }


        return 0;
    }

    /**
     * Restart Apache to restart the APC cache
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    private function restartApache(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Restarting Apache to empty APC cache");
        }

        // Graceful restart
        $command = "/usr/sbin/apachectl graceful";
        $outputArray = [];
        $returnStatus = null;

        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Running: $command");
        }

        if (! $dryRun) {
            exec($command, $outputArray, $returnStatus);
        }

        // Error
        if ($returnStatus != 0) {
            /** @var FormatterHelper $formatter */
            $formatter = $this->getHelperSet()->get('formatter');

            $output->writeln("<error>Error while restarting Apache</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        return 0;
    }

    /**
     * Clear the translation cache
     *
     * @param string          $path
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    private function clearTranslationCache($path, InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');

        // If the cache directory doesn't exists, skip
        if (!is_dir($path . '/public/cache/translate')) {
            if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
                $output->writeln("No translation cache to clear, skipping");
            }
            return 0;
        }

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Clearing translation cache");
        }

        // Graceful restart
        $command = "cd '$path' && rm public/cache/translate/*";
        $outputArray = [];
        $returnStatus = null;

        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Running: $command");
        }

        if (! $dryRun) {
            exec($command, $outputArray, $returnStatus);
        }

        // Error
        if ($returnStatus != 0) {
            /** @var FormatterHelper $formatter */
            $formatter = $this->getHelperSet()->get('formatter');

            $output->writeln("<error>Error while clearing translation cache</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        return 0;
    }

    /**
     * Composer dependencies installation
     *
     * @param string          $path
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    private function runComposer($path, InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Updating project dependencies with Composer");
        }

        $command = "cd '$path' && composer install --no-dev 2>&1";
        $outputArray = [];
        $returnStatus = null;

        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Running: $command");
        }

        if (! $dryRun) {
            exec($command, $outputArray, $returnStatus);
        }

        // Error
        if ($returnStatus != 0) {
            /** @var FormatterHelper $formatter */
            $formatter = $this->getHelperSet()->get('formatter');

            $output->writeln("<error>Error while running Composer install</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        return 0;
    }

    /**
     * Database update (Doctrine)
     *
     * @param string          $path
     * @param boolean         $forceUpdateDB
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
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
                return 0;
            }
        }

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Updating the database through Doctrine");
        }

        $command = "php '$path/scripts/build/build.php' update 2>&1";
        $outputArray = [];
        $returnStatus = null;

        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Running: $command");
        }

        if (! $dryRun) {
            exec($command, $outputArray, $returnStatus);
        }

        // Error
        if ($returnStatus != 0) {
            /** @var FormatterHelper $formatter */
            $formatter = $this->getHelperSet()->get('formatter');

            $output->writeln("<error>Error while running DB update</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        return 0;
    }
    /**
     * Database update (Doctrine)
     *
     * @param string          $worker
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    private function restartWorker($worker, InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');

        // If the user didn't ask to restart a worker, we ask him
        if (!$worker) {
            /** @var DialogHelper $dialog */
            $dialog = $this->getHelperSet()->get('dialog');

            $worker = $dialog->ask(
                $output,
                '<question>Name of the Gearman worker to restart (leave empty to skip step):</question>',
                null
            );

            if (!$worker) {
                return 0;
            }
        }

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Restarting the Gearman worker '$worker'");
        }

        $command = "supervisorctl restart $worker 2>&1";
        $outputArray = [];
        $returnStatus = null;

        if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->writeln("Running: $command");
        }

        if (! $dryRun) {
            exec($command, $outputArray, $returnStatus);
        }

        // Error
        if ($returnStatus != 0) {
            /** @var FormatterHelper $formatter */
            $formatter = $this->getHelperSet()->get('formatter');

            $output->writeln("<error>Error while restarting the worker</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        return 0;
    }
}
