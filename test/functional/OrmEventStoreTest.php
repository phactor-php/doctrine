<?php

namespace PhactorTest\Doctrine;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Tools\SchemaTool;
use Phactor\Actor\ActorIdentity;
use Phactor\Doctrine\Dbal\JsonObject;
use Phactor\Doctrine\Entity\ActorDomainMessage;
use Phactor\Doctrine\Entity\DomainMessage as DomainMessageEntity;
use Phactor\Doctrine\Entity\Snapshot;
use Phactor\Doctrine\Mappings;
use Phactor\Doctrine\OrmEventStore;
use Phactor\DomainMessage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phactor\Doctrine\OrmEventStore
 * @uses \Phactor\Actor\ActorIdentity
 * @uses \Phactor\DomainMessage
 * @uses \Phactor\Doctrine\Entity\DomainMessage
 * @uses \Phactor\Doctrine\Entity\ActorDomainMessage
 * @uses \Phactor\Doctrine\Dbal\JsonObject
 * @uses \Phactor\Doctrine\Entity\Snapshot
 */
class OrmEventStoreTest extends TestCase
{
    private EntityManager $em;
    private SchemaTool $schemaTool;

    public function setUp(): void
    {
        if (Type::hasType('json_object')) {
            Type::overrideType('json_object', JsonObject::class);
        } else {
            Type::addType('json_object', JsonObject::class);
        }

        $config = new Configuration();

        $config->setMetadataCacheImpl(new ArrayCache());
        $config->setQueryCacheImpl(new ArrayCache());
        $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        $config->setProxyNamespace('Doctrine\Tests\Proxies');
        $config->setProxyDir('/dev/null');
        $config->setMetadataDriverImpl(
            new XmlDriver([Mappings::XML_MAPPINGS])
        );

        $conn = array(
            'driver' => 'pdo_sqlite',
            'path' => ':memory:',
        );

        $this->em = EntityManager::create($conn, $config);
        $this->schemaTool = new SchemaTool($this->em);

        $classes[] = $this->em->getClassMetadata(ActorDomainMessage::class);
        $classes[] = $this->em->getClassMetadata(DomainMessageEntity::class);
        $classes[] = $this->em->getClassMetadata(Snapshot::class);

        $this->schemaTool->createSchema($classes);
    }

    public function testSave()
    {
        $sut = new OrmEventStore($this->em);
        $identity = new ActorIdentity('stdClass', 'id');
        $domainMessage = DomainMessage::recordMessage('eid', null, $identity, 1, new \stdClass);
        $domainMessage = $domainMessage->withMetadata(['meta' => 'data']);
        $sut->save($identity, $domainMessage);

        $fromDatastore = $sut->load($identity);

        self::assertEqualsWithDelta([$domainMessage], $fromDatastore, 1);
    }

    public function testSnapshot()
    {
        $sut = new OrmEventStore($this->em);
        $identity = new ActorIdentity('stdClass', 'id');
        $domainMessages[] = DomainMessage::recordMessage('eid1', null, $identity, 1, new \stdClass);
        $domainMessages[] = DomainMessage::recordMessage('eid2', null, $identity, 2, new \stdClass);
        $domainMessages[] = DomainMessage::recordMessage('eid3', null, $identity, 3, new \stdClass);
        $sut->save($identity, ...$domainMessages);

        $sut->saveSnapshot($identity, 2, 'some-state');

        $fromDatastore = $sut->loadFromLastSnapshot($identity);

        self::assertEqualsWithDelta([$domainMessages[2]], $fromDatastore, 1);
    }

    public function testLoadByIds()
    {
        $sut = new OrmEventStore($this->em);
        $identity = new ActorIdentity('stdClass', 'id');
        $domainMessages[] = DomainMessage::recordMessage('eid1', null, $identity, 1, new \stdClass);
        $domainMessages[] = DomainMessage::recordMessage('eid2', null, $identity, 2, new \stdClass);
        $domainMessages[] = DomainMessage::recordMessage('eid3', null, $identity, 3, new \stdClass);
        $sut->save($identity, ...$domainMessages);

        $fromDatastore = $sut->loadEventsByIds('eid2', 'eid3');

        self::assertEqualsWithDelta([$domainMessages[1], $domainMessages[2]], $fromDatastore, 1);
    }

    public function testLoadByClasses()
    {
        $sut = new OrmEventStore($this->em);
        $identity = new ActorIdentity('stdClass', 'id');
        $domainMessages[] = DomainMessage::recordMessage('eid1', null, $identity, 1, new \DateTime());
        $domainMessages[] = DomainMessage::recordMessage('eid2', null, $identity, 2, new \stdClass);
        $domainMessages[] = DomainMessage::recordMessage('eid3', null, $identity, 3, new \stdClass);
        $sut->save($identity, ...$domainMessages);

        $fromDatastore = $sut->loadEventsByClasses(\stdClass::class);

        self::assertEqualsWithDelta([$domainMessages[1], $domainMessages[2]], $fromDatastore, 1);
    }
}
