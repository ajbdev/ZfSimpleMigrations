<?php

namespace ZfSimpleMigrations\Library;

use Zend\Db\Adapter\Adapter;
use Zend\Db\Adapter\Driver\Pdo\Pdo;
use Zend\Db\Adapter\Exception\InvalidQueryException;
use Zend\Db\Metadata\Metadata;
use Zend\Db\Sql\Ddl;
use Zend\Db\Sql\Sql;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use ZfSimpleMigrations\Library\OutputWriter;
use ZfSimpleMigrations\Model\MigrationVersion;
use ZfSimpleMigrations\Model\MigrationVersionTable;

/**
 * Main migration logic
 */
class Migration implements ServiceLocatorAwareInterface
{
    protected $migrationsDir;
    protected $migrationsNamespace;
    protected $adapter;
    /**
     * @var \Zend\Db\Adapter\Driver\ConnectionInterface
     */
    protected $connection;
    protected $metadata;
    protected $migrationVersionTable;
    protected $outputWriter;
    protected $useModules;

    /**
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * @param \Zend\Db\Adapter\Adapter $adapter
     * @param array $config
     * @param \ZfSimpleMigrations\Model\MigrationVersionTable $migrationVersionTable
     * @param OutputWriter $writer
     * @throws MigrationException
     */
    public function __construct(Adapter $adapter, array $config, MigrationVersionTable $migrationVersionTable, OutputWriter $writer = null)
    {
        $this->adapter = $adapter;
        $this->metadata = new Metadata($this->adapter);
        $this->connection = $this->adapter->getDriver()->getConnection();
        $this->migrationsDir = $config['dir'];
        $this->useModules = $config['use_modules'];
        $this->migrationsNamespace = $config['namespace'];
        $this->migrationVersionTable = $migrationVersionTable;
        $this->outputWriter = is_null($writer) ? new OutputWriter() : $writer;

        if (is_null($this->migrationsDir) && !$this->useModules)
            throw new MigrationException('Migrations directory not set!');

        if (is_null($this->migrationsNamespace))
            throw new MigrationException('Unknown namespaces!');

        if (!is_dir($this->migrationsDir)) {
            if (!mkdir($this->migrationsDir, 0775)) {
                throw new MigrationException(sprintf('Failed to create migrations directory %s', $this->migrationsDir));
            }
        }

        $this->checkCreateMigrationTable();
    }

    /**
     * Create migrations table of not exists
     */
    protected function checkCreateMigrationTable()
    {
        $table = new Ddl\CreateTable(MigrationVersion::TABLE_NAME);
        $table->addColumn(new Ddl\Column\Integer('id', false, null, array('autoincrement' => true)));
        $table->addColumn(new Ddl\Column\BigInteger('version'));
        $table->addConstraint(new Ddl\Constraint\PrimaryKey('id'));
        $table->addConstraint(new Ddl\Constraint\UniqueKey('version'));

        $sql = new Sql($this->adapter);

        try {
            $this->adapter->query($sql->getSqlStringForSqlObject($table), Adapter::QUERY_MODE_EXECUTE);
        } catch (\Exception $e) {
            // currently there are no db-independent way to check if table exists
            // so we assume that table exists when we catch exception
        }
    }

    /**
     * @return int
     */
    public function getCurrentVersion()
    {
        return $this->migrationVersionTable->getCurrentVersion();
    }

    /**
     * @param int $version target migration version, if not set all not applied available migrations will be applied
     * @param bool $force force apply migration
     * @param bool $down rollback migration
     * @param bool $fake
     * @throws MigrationException
     */
    public function migrate($version = null, $force = false, $down = false, $fake = false)
    {
        $migrations = $this->getMigrationClasses($force);

        if (!is_null($version) && !$this->hasMigrationVersions($migrations, $version)) {
            throw new MigrationException(sprintf('Migration version %s is not found!', $version));
        }

        $currentMigrationVersion = $this->migrationVersionTable->getCurrentVersion();
        if (!is_null($version) && $version == $currentMigrationVersion && !$force) {
            throw new MigrationException(sprintf('Migration version %s is current version!', $version));
        }

        if ($version && $force) {
            foreach ($migrations as $migration) {
                if ($migration['version'] == $version) {
                    // if existing migration is forced to apply - delete its information from migrated
                    // to avoid duplicate key error
                    if (!$down) $this->migrationVersionTable->delete($migration['version']);
                    $this->applyMigration($migration, $down, $fake);
                    break;
                }
            }
            // target migration version not set or target version is greater than last applied migration -> apply migrations
        } elseif (is_null($version) || (!is_null($version) && $version > $currentMigrationVersion)) {
            foreach ($migrations as $migration) {
                if ($migration['version'] > $currentMigrationVersion) {
                    if (is_null($version) || (!is_null($version) && $version >= $migration['version'])) {
                        $this->applyMigration($migration, false, $fake);
                    }
                }
            }
            // target migration version is set -> rollback migration
        } elseif (!is_null($version) && $version < $currentMigrationVersion) {
            $migrationsByDesc = $this->sortMigrationsByVersionDesc($migrations);
            foreach ($migrationsByDesc as $migration) {
                if ($migration['version'] > $version && $migration['version'] <= $currentMigrationVersion) {
                    $this->applyMigration($migration, true, $fake);
                }
            }
        }
    }

    /**
     * @param \ArrayIterator $migrations
     * @return \ArrayIterator
     */
    public function sortMigrationsByVersionDesc(\ArrayIterator $migrations)
    {
        $sortedMigrations = clone $migrations;

        $sortedMigrations->uasort(function ($a, $b) {
            if ($a['version'] == $b['version']) {
                return 0;
            }

            return ($a['version'] > $b['version']) ? -1 : 1;
        });

        return $sortedMigrations;
    }

    /**
     * Check migrations classes existence
     *
     * @param \ArrayIterator $migrations
     * @param int $version
     * @return bool
     */
    public function hasMigrationVersions(\ArrayIterator $migrations, $version)
    {
        foreach ($migrations as $migration) {
            if ($migration['version'] == $version) return true;
        }

        return false;
    }

    /**
     * @param \ArrayIterator $migrations
     * @return int
     */
    public function getMaxMigrationVersion(\ArrayIterator $migrations)
    {
        $versions = array();
        foreach ($migrations as $migration) {
            $versions[] = $migration['version'];
        }

        sort($versions, SORT_NUMERIC);
        $versions = array_reverse($versions);

        return count($versions) > 0 ? $versions[0] : 0;
    }

    protected function getMigrationClassesFromDirectory($dir, $all = false, $namespace)
    {
        $classes = new \ArrayIterator();

        $iterator = new \GlobIterator(sprintf('%s/Version*.php', $dir), \FilesystemIterator::KEY_AS_FILENAME);
        foreach ($iterator as $item) {
            /** @var $item \SplFileInfo */
            if (preg_match('/(Version(.*))\.php/', $item->getFilename(), $matches)) {

                $applied = $this->migrationVersionTable->applied($matches[2]);
                if ($all || !$applied) {
                    $className = $matches[1];
                    if ($this->useModules) {
                        $className = $namespace . '\\' . $className;
                    } else {
                        $className = $this->migrationsNamespace . '\\' . $className;
                    }

                    if (!class_exists($className)) {
                        /** @noinspection PhpIncludeInspection */
                        require_once $dir . '/' . $item->getFilename();
                        echo $className . PHP_EOL;
                    }

                    if (class_exists($className)) {
                        $reflectionClass = new \ReflectionClass($className);
                        $reflectionDescription = new \ReflectionProperty($className, 'description');

                        if ($reflectionClass->implementsInterface('ZfSimpleMigrations\Library\MigrationInterface')) {
                            $classes->append(array(
                                'version' => $matches[2],
                                'class' => $className,
                                'description' => $reflectionDescription->getValue(),
                                'applied' => $applied,
                            ));
                        }
                    }
                }
            }
        }

        return $classes;
    }

    /**
     * @param bool $all
     * @return \ArrayIterator
     */
    public function getMigrationClasses($all = false)
    {
        if ($this->useModules) {
            $directories = new \DirectoryIterator(dirname(__FILE__) . '/../../../../../../module');
            $classes =  array();

            foreach ($directories as $dir) {
                if (!$dir->isDot() && substr($dir->getFileName(),0,1) !== '.') {
                    $path = dirname(__FILE__) . '/../../../../../../module/' . $dir->getFileName() . '/src/Migration';
                    $classes = array_merge($classes, $this->getMigrationClassesFromDirectory($path, $all, $dir->getFileName() . '\\Migration')->getArrayCopy());
                }
            }
            $classes = new \ArrayIterator($classes);
        } else {
            $classes = $this->getMigrationClassesFromDirectory($this->migrationsDir, $all);
        }

        $classes->uasort(function ($a, $b) {
            if ($a['version'] == $b['version']) {
                return 0;
            }

            return ($a['version'] < $b['version']) ? -1 : 1;
        });

        return $classes;
    }

    protected function applyMigration(array $migration, $down = false, $fake = false)
    {
        $this->connection->beginTransaction();

        try {
            /** @var $migrationObject AbstractMigration */
            $migrationObject = new $migration['class']($this->metadata, $this->outputWriter);

            if ($migrationObject instanceof ServiceLocatorAwareInterface) {
                if (is_null($this->serviceLocator)) {
                    throw new \RuntimeException(
                        sprintf(
                            'Migration class %s requires a ServiceLocator, but it is no instance available.',
                            get_class($migrationObject)
                        )
                    );
                }

                $migrationObject->setServiceLocator($this->serviceLocator);
            }

            $this->outputWriter->writeLine(sprintf("%sExecute migration class %s %s",
                $fake ? '[FAKE] ' : '', $migration['class'], $down ? 'down' : 'up'));

            if (!$fake) {
                $sqlList = $down ? $migrationObject->getDownSql() : $migrationObject->getUpSql();
                foreach ($sqlList as $sql) {
                    $this->outputWriter->writeLine("Execute query:\n\n" . $sql);
                    $this->connection->execute($sql);
                }
            }

            if ($down) {
                $this->migrationVersionTable->delete($migration['version']);
            } else {
                $this->migrationVersionTable->save($migration['version']);
            }
            $this->connection->commit();
        } catch (InvalidQueryException $e) {
            $this->connection->rollback();
            $msg = sprintf('%s: "%s"; File: %s; Line #%d', $e->getMessage(), $e->getPrevious()->getMessage(), $e->getFile(), $e->getLine());
            throw new MigrationException($msg);
        } catch (\Exception $e) {
            $this->connection->rollback();
            $msg = sprintf('%s; File: %s; Line #%d', $e->getMessage(), $e->getFile(), $e->getLine());
            throw new MigrationException($msg);
        }
    }

    /**
     * Set service locator
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @return mixed
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;

        return $this;
    }

    /**
     * Get service locator
     *
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }
}
