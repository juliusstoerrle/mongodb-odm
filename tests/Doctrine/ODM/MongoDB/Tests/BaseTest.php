<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Tests;

use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Driver\AnnotationDriver;
use Doctrine\ODM\MongoDB\Tests\Query\Filter\Filter;
use Doctrine\ODM\MongoDB\UnitOfWork;
use MongoDB\Client;
use MongoDB\Model\DatabaseInfo;
use PHPUnit\Framework\TestCase;
use const DOCTRINE_MONGODB_DATABASE;
use const DOCTRINE_MONGODB_SERVER;
use function array_key_exists;
use function array_map;
use function getenv;
use function in_array;
use function iterator_to_array;
use function version_compare;

abstract class BaseTest extends TestCase
{
    /** @var DocumentManager */
    protected $dm;
    /** @var UnitOfWork */
    protected $uow;

    public function setUp()
    {
        $this->dm  = $this->createTestDocumentManager();
        $this->uow = $this->dm->getUnitOfWork();
    }

    public function tearDown()
    {
        if (! $this->dm) {
            return;
        }

        // Check if the database exists. Calling listCollections on a non-existing
        // database in a sharded setup will cause an invalid command cursor to be
        // returned
        $client        = $this->dm->getClient();
        $databases     = iterator_to_array($client->listDatabases());
        $databaseNames = array_map(static function (DatabaseInfo $database) {
            return $database->getName();
        }, $databases);
        if (! in_array(DOCTRINE_MONGODB_DATABASE, $databaseNames)) {
            return;
        }

        $collections = $client->selectDatabase(DOCTRINE_MONGODB_DATABASE)->listCollections();

        foreach ($collections as $collection) {
            // See https://jira.mongodb.org/browse/SERVER-16541
            if ($collection->getName() === 'system.indexes') {
                continue;
            }

            $client->selectCollection(DOCTRINE_MONGODB_DATABASE, $collection->getName())->drop();
        }
    }

    protected function getConfiguration()
    {
        $config = new Configuration();

        $config->setProxyDir(__DIR__ . '/../../../../Proxies');
        $config->setProxyNamespace('Proxies');
        $config->setHydratorDir(__DIR__ . '/../../../../Hydrators');
        $config->setHydratorNamespace('Hydrators');
        $config->setPersistentCollectionDir(__DIR__ . '/../../../../PersistentCollections');
        $config->setPersistentCollectionNamespace('PersistentCollections');
        $config->setDefaultDB(DOCTRINE_MONGODB_DATABASE);
        $config->setMetadataDriverImpl($this->createMetadataDriverImpl());

        $config->addFilter('testFilter', Filter::class);
        $config->addFilter('testFilter2', Filter::class);

        return $config;
    }

    protected function createMetadataDriverImpl()
    {
        return AnnotationDriver::create(__DIR__ . '/../../../../Documents');
    }

    protected function createTestDocumentManager()
    {
        $config = $this->getConfiguration();
        $client = new Client(getenv('DOCTRINE_MONGODB_SERVER') ?: DOCTRINE_MONGODB_SERVER, [], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        return DocumentManager::create($client, $config);
    }

    protected function getServerVersion()
    {
        $result = $this->dm->getClient()->selectDatabase(DOCTRINE_MONGODB_DATABASE)->command(['buildInfo' => 1])->toArray()[0];

        return $result['version'];
    }

    protected function skipTestIfNotSharded($className)
    {
        $result = $this->dm->getDocumentDatabase($className)->command(['listCommands' => true])->toArray()[0];
        if (! $result['ok']) {
            $this->markTestSkipped('Could not check whether server supports sharding');
        }

        if (array_key_exists('shardCollection', $result['commands'])) {
            return;
        }

        $this->markTestSkipped('Test skipped because server does not support sharding');
    }

    protected function requireVersion($installedVersion, $requiredVersion, $operator, $message)
    {
        if (! version_compare($installedVersion, $requiredVersion, $operator)) {
            return;
        }

        $this->markTestSkipped($message);
    }

    protected function requireMongoDB32($message)
    {
        $this->requireVersion($this->getServerVersion(), '3.2.0', '<', $message);
    }

    protected function skipOnMongoDB34($message)
    {
        $this->requireVersion($this->getServerVersion(), '3.4.0', '>=', $message);
    }

    protected function requireMongoDB34($message)
    {
        $this->requireVersion($this->getServerVersion(), '3.4.0', '<', $message);
    }
}
