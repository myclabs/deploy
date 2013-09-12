<?php

namespace Deploy;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Extending the Symfony Console Application to allow having one command only
 *
 * @see http://symfony.com/doc/current/components/console/single_command_tool.html
 *
 * @author matthieu.napoli
 */
class Application extends \Symfony\Component\Console\Application
{
    /**
     * {@inheritdoc}
     */
    protected function getCommandName(InputInterface $input)
    {
        return 'deploy';
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultCommands()
    {
        // Keep the core default commands to have the HelpCommand
        // which is used when using the --help option
        $defaultCommands = parent::getDefaultCommands();

        $defaultCommands[] = new DeployCommand();

        return $defaultCommands;
    }

    /**
     * Overridden so that the application doesn't expect the command
     * name to be the first argument
     */
    public function getDefinition()
    {
        $inputDefinition = parent::getDefinition();
        // clear out the normal first argument, which is the command name
        $inputDefinition->setArguments();

        return $inputDefinition;
    }
}
