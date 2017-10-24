# Easy to use Migrations for Shopware Plugins using Shopware Default Migrations

## How to use this?

* Require migration package in our plugin folder
```bash
compooser require shyim/shopware-migration
```
* Include autoload.php on top our Plugin Bootstrap
```php
require __DIR__ . '/vendor/autoload.php';

use ShyimMigration\AbstractMigration;
use ShyimMigration\MigrationManager;
```
* Create a new migration folder in Pluginname/Resources/migrations
* Call migrations in our install, update, uninstall method

````php
    public function install(InstallContext $context)
    {
        MigrationManager::doMigrations($this, $this->container, AbstractMigration::MODUS_INSTALL);
    }

    public function update(Plugin\Context\UpdateContext $context)
    {
        MigrationManager::doMigrations($this, $this->container, AbstractMigration::MODUS_UPDATE);
    }

    public function uninstall(UninstallContext $context)
    {
        if (!$context->keepUserData()) {
            MigrationManager::doMigrations($this, $this->container, AbstractMigration::MODUS_UNINSTALL);
        }
    }
````

# Example Plugin
[ShyimMigrationTest](https://github.com/shyim/ShyimMigrationTest)
