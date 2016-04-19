<?php

namespace Casebox\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class MinJsCommand
 */
class MinJsCommand extends ContainerAwareCommand
{
    /**
     * Configure
     */
    protected function configure()
    {
        $this
            ->setName('casebox:min:js')
            ->setDescription('Minify JS files.');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getcontainer();

        $minService = $container->get('casebox_core.service.minify');

        $minService->execute('js', $output);

        $output->writeln('<info>[x] JS minify done</info>');
    }
}
