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

        // Run Composer
        $returnStatus = $this->runComposer($path, $input, $output);
        if ($returnStatus > 0) {
            return $returnStatus;
        }

        // Run Grunt
        $returnStatus = $this->runGrunt($path, $input, $output);
        if ($returnStatus > 0) {
            return $returnStatus;
        }

        // Clear Memcached
        // temporary fix
        $returnStatus = $this->clearMemcached($input, $output);
        if ($returnStatus > 0) {
            return $returnStatus;
        }
        // Clear cache
        $returnStatus = $this->clearCache($path, $input, $output);
        if ($returnStatus > 0) {
            return $returnStatus;
        }

        // Update DB
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
            $isOnBranch = strpos($lastLine, '(no branch)') === false && strpos($lastLine, '(detached from ') === false;
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
            $output->writeln("Installing project dependencies with Composer");
        }

        $command = "cd '$path' && composer install --no-dev --optimize-autoloader 2>&1";
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
     * JS & CSS dependencies installation
     *
     * @param string          $path
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    private function runGrunt($path, InputInterface $input, OutputInterface $output)
    {
        if (! file_exists($path . '/Gruntfile.js')) {
            return 0;
        }

        $dryRun = $input->getOption('dry-run');

        // NPM
        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Installing project dependencies with npm");
        }

        $command = "cd '$path' && npm install 2>&1";
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

            $output->writeln("<error>Error while running npm install</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        // Bower
        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Installing project dependencies with Bower");
        }

        $command = "cd '$path' && bower install --allow-root 2>&1";
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

            $output->writeln("<error>Error while running Bower install</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        // Grunt
        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Running Grunt");
        }

        $command = "cd '$path' && grunt 2>&1";
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

            $output->writeln("<error>Error while running Grunt</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        return 0;
    }

    /**
     * Clear cache
     *
     * @param string          $path
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    private function clearCache($path, InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Clearing the caches");
        }

        $command = "$path/bin/inventory cache:clear 2>&1";
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

            $output->writeln("<error>Error while clearing the cache</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        return 0;
    }

    /**
     * Clear Memcached
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    private function clearMemcached(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Clearing Memcached content");
        }

        // Sends "flush_all" command to memcached
        $command = "echo 'flush_all' | netcat localhost 11211";
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

            $output->writeln("<error>Error while clearing Memcached</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        return 0;
    }

    /**
     * Database update
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
                '<question>Update the database?</question>',
                false
            );

            if (!$confirmation) {
                return 0;
            }
        }

        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $output->writeln("Updating the database");
        }

        $command = "$path/bin/inventory db:update 2>&1";
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

            $output->writeln("<error>Error while updating the database</error>");
            $output->writeln("Command used: $command");
            $output->writeln($formatter->formatBlock($outputArray, 'error'));
            return 1;
        }

        return 0;
    }

    /**
     * Restart the worker
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
