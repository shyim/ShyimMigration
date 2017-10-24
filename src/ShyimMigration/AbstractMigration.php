<?php

namespace ShyimMigration;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

abstract class AbstractMigration extends \Shopware\Components\Migrations\AbstractMigration implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    const MODUS_UNINSTALL = 'uninstall';

    /**
     * Reverse migration
     */
    abstract public function down();
}