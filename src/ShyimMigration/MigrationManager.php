<?php

namespace ShyimMigration;

use Shopware\Components\Migrations\Manager;
use Shopware\Components\Migrations\AbstractMigration;
use Shopware\Components\Plugin;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MigrationManager
 * @package ShyimMigration
 */
class MigrationManager extends Manager
{
    /**
     * @var string
     */
    private $pluginName;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * Migration Table
     */
    public function createSchemaTable()
    {
        $sql = '
            CREATE TABLE IF NOT EXISTS `s_plugin_migrations` (
            `plugin_name` VARCHAR (255) NOT NULL,
            `version` int(11) NOT NULL,
            `start_date` datetime NOT NULL,
            `complete_date` datetime DEFAULT NULL,
            `name` VARCHAR( 255 ) NOT NULL,
            `error_msg` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
        ';
        $this->connection->exec($sql);
    }

    /**
     * Returns current schma version found in database
     *
     * @return int
     */
    public function getCurrentVersion()
    {
        $sql = 'SELECT version FROM s_plugin_migrations WHERE plugin_name = ? AND complete_date IS NOT NULL ORDER BY version DESC';
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$this->pluginName]);
        $currentVersion = (int) $stmt->fetchColumn();

        return $currentVersion;
    }

    /**
     * Applies given $migration to database
     *
     * @param AbstractMigration $migration
     * @param string            $modus
     *
     * @throws \Exception
     */
    public function apply(AbstractMigration $migration, $modus = AbstractMigration::MODUS_INSTALL)
    {
        $sql = 'REPLACE s_plugin_migrations (plugin_name, version, start_date, name) VALUES (:plugin_name, :version, :date, :name)';
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            ':plugin_name' => $this->pluginName,
            ':version' => $migration->getVersion(),
            ':date' => date('Y-m-d H:i:s'),
            ':name' => $migration->getLabel(),
        ]);

        if ($migration instanceof \ShyimMigration\AbstractMigration) {
            $migration->setContainer($this->container);
        }

        try {
            if ($modus !== \ShyimMigration\AbstractMigration::MODUS_UNINSTALL) {
                $migration->up($modus);
            } else {
                $migration->down();
            }
            $sqls = $migration->getSql();

            foreach ($sqls as $sql) {
                $this->connection->exec($sql);
            }
        } catch (\Exception $e) {
            $updateVersionSql = 'UPDATE s_plugin_migrations SET error_msg = :msg WHERE version = :version and plugin_name = :plugin_name';
            $stmt = $this->connection->prepare($updateVersionSql);
            $stmt->execute([
                ':version' => $migration->getVersion(),
                ':msg' => $e->getMessage(),
                ':plugin_name' => $this->pluginName
            ]);

            $message = sprintf(
                'Could not apply migration %s: (%s). Error: %s ', $this->pluginName, get_class($migration), $e->getMessage()
            );

            $this->log($message);

            throw new \Exception($message);
        }

        if ($modus !== \ShyimMigration\AbstractMigration::MODUS_UNINSTALL) {
            $sql = 'UPDATE s_plugin_migrations SET complete_date = :date WHERE version = :version and plugin_name = :plugin_name';
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([
                ':plugin_name' => $this->pluginName,
                ':version' => $migration->getVersion(),
                ':date' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $stmt = $this->connection->prepare('DELETE FROM s_plugin_migrations WHERE version = :version and plugin_name = :plugin_name');
            $stmt->execute([
                ':plugin_name' => $this->pluginName,
                ':version' => $migration->getVersion()
            ]);
        }
    }

    /**
     * @param $currentVersion
     * @param null $limit
     * @return array
     * @throws \Exception
     */
    public function getMigrationsForDowngrade($currentVersion, $limit = null)
    {
        $regexPattern = '/^([0-9]*)-.+\.php$/i';

        $migrationPath = $this->getMigrationPath();

        $directoryIterator = new \DirectoryIterator($migrationPath);
        $regex = new \RegexIterator($directoryIterator, $regexPattern, \RecursiveRegexIterator::GET_MATCH);

        $migrations = [];

        foreach ($regex as $result) {
            $migrationVersion = $result['1'];
            if ($migrationVersion > $currentVersion) {
                continue;
            }

            $migrationClassName = 'Migrations_Migration' . $result['1'];
            if (!class_exists($migrationClassName, false)) {
                $file = $migrationPath . '/' . $result['0'];
                require $file;
            }

            try {
                /** @var $migrationClass AbstractMigration */
                $migrationClass = new $migrationClassName($this->getConnection());
            } catch (\Exception $e) {
                throw new \Exception('Could not instantiate Object');
            }

            if (!($migrationClass instanceof AbstractMigration)) {
                throw new \Exception("$migrationClassName is not instanceof AbstractMigration");
            }

            if ($migrationClass->getVersion() != $result['0']) {
                throw new \Exception(
                    sprintf('Version mismatch. Version in filename: %s, Version in Class: %s', $result['1'], $migrationClass->getVersion())
                );
            }

            $migrations[$migrationClass->getVersion()] = $migrationClass;
        }

        ksort($migrations);
        $migrations = array_reverse($migrations);

        if ($limit !== null) {
            return array_slice($migrations, 0, $limit, true);
        }

        return $migrations;
    }

    /**
     * @param string $modus
     */
    public function run($modus = AbstractMigration::MODUS_INSTALL)
    {
        $this->createSchemaTable();

        $currentVersion = $this->getCurrentVersion();
        $this->log(sprintf('Current MigrationNumber %s: %s',$this->pluginName,  $currentVersion));

        if ($modus !== \ShyimMigration\AbstractMigration::MODUS_UNINSTALL) {
            $migrations = $this->getMigrationsForVersion($currentVersion);
        } else {
            $migrations = $this->getMigrationsForDowngrade($currentVersion);
        }

        $this->log(sprintf('Found %s migrations to apply', count($migrations)));

        /** @var AbstractMigration $migration */
        foreach ($migrations as $migration) {
            $this->log(sprintf('Apply MigrationNumber %s: %s - %s (Mode: %s)', $this->pluginName, $migration->getVersion(), $migration->getLabel(), $modus));
            $this->apply($migration, $modus);
        }
    }

    /**
     * @param $str
     */
    public function log($str)
    {
        $this->container->get('pluginlogger')->info($str);
    }
    
    /**
     * @param string $pluginName
     */
    public function setPluginName($pluginName)
    {
        $this->pluginName = $pluginName;
    }

    /**
     * @param ContainerInterface $container
     */
    public function setContainer($container)
    {
        $this->container = $container;
    }


    /**
     * @param Plugin $plugin
     * @param ContainerInterface $container
     * @param string $modus
     */
    public static function doMigrations(Plugin $plugin, ContainerInterface $container, $modus = \ShyimMigration\AbstractMigration::MODUS_INSTALL)
    {
        $migration = new self($container->get('db_connection'), $plugin->getPath() . '/Resources/migrations');
        $migration->container = $container;
        $migration->pluginName = $plugin->getName();
        $migration->run($modus);
    }
}
