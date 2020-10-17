<?php

namespace Phactor\Doctrine;

use Doctrine\DBAL\Driver\ResultStatement;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Phactor\Actor\ActorIdentity;
use Phactor\Doctrine\Entity\ActorDomainMessage;
use Phactor\Doctrine\Entity\Snapshot;
use Phactor\DomainMessage;
use Phactor\EventStore\EventStore as EventStoreInterface;
use Phactor\EventStore\LoadsEvents;
use Phactor\EventStore\NoEventsFound;
use Doctrine\ORM\EntityManagerInterface;
use Phactor\EventStore\TakesSnapshots;

final class OrmEventStore implements EventStoreInterface, TakesSnapshots, LoadsEvents
{
    private EntityManagerInterface $entityManager;
    private \Closure $hydrateCallback;
    private \Closure $extractCallback;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->hydrateCallback = \Closure::bind(static function ($object, $values) use ($entityManager) {
            $messageDecoder = Type::getType('json_object');
            $metadataDecoder = Type::getType('json_array');

            $object->id = $values['id'];
            $object->correlationId = $values['correlationId'];
            $object->causationId = $values['causationId'];
            $object->time = new \DateTimeImmutable($values['time']);
            $object->message = $messageDecoder->convertToPHPValue($values['message'], $entityManager->getConnection()->getDatabasePlatform());
            $object->metadata = $metadataDecoder->convertToPHPValue($values['metadata'], $entityManager->getConnection()->getDatabasePlatform());
            if ($values['producerClass'] !== null && $values['producerId'] !== null) {
                $object->producer = new ActorIdentity($values['producerClass'], $values['producerId']);
            }
            $object->produced = new \DateTimeImmutable($values['produced']);
            $object->actor = new ActorIdentity($values['actorClass'], $values['actorId']);;
            $object->version = $values['version'];
            $object->recorded = new \DateTimeImmutable($values['recorded']);

        }, null, 'Phactor\\DomainMessage');
        $this->extractCallback = \Closure::bind(static function ($object, &$values) {
            $actorExtractor = \Closure::bind(static function ($object, &$values, $prefix) {
                $values[$prefix . 'Class'] = $object->class;
                $values[$prefix . 'Id'] = $object->id;
            }, null, 'Phactor\\Actor\\ActorIdentity');

            $values['id'] = $object->id;
            $values['correlationId'] = $object->correlationId;
            $values['causationId'] = $object->causationId;
            $values['time'] = $object->time;
            $values['messageClass'] = get_class($object->message);
            $values['message'] = $object->message;
            $values['metadata'] = $object->metadata;
            $object->producer === null ?: $actorExtractor($object->producer, $values, 'producer');
            $values['produced'] = $object->produced;

            $actorExtractor($object->actor, $values, 'actor');
            $values['version'] = $object->version;
            $values['recorded'] = $object->recorded;

        }, null, 'Phactor\\DomainMessage');
    }

    public function loadEventsByIds(string ...$ids)
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $results = $qb
            ->select('d.*', 'a.*')
            ->from('DomainMessage', 'd')
            ->leftJoin('d', 'ActorDomainMessage', 'a', 'a.domainMessageId = d.id')
            ->where($qb->expr()->in('d.id', array_fill(0, count($ids), '?')))
            ->setParameters($ids)
            ->execute();

        $events = $this->hydrateEvents($results);

        return $events;
    }

    public function loadEventsByClasses(string ...$classes)
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $results = $qb
            ->select('d.*', 'a.*')
            ->from('DomainMessage', 'd')
            ->leftJoin('d', 'ActorDomainMessage', 'a', 'a.domainMessageId = d.id')
            ->where($qb->expr()->in('d.messageClass', array_fill(0, count($classes), '?')))
            ->setParameters($classes)
            ->execute();

        $events = $this->hydrateEvents($results);

        return $events;
    }

    public function load(ActorIdentity $identity): iterable
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $results = $qb
            ->select('d.*', 'a.*')
            ->from('DomainMessage', 'd')
            ->leftJoin('d', 'ActorDomainMessage', 'a', 'a.domainMessageId = d.id')
            ->where($qb->expr()->andX(
                $qb->expr()->eq('actorClass', '?'),
                $qb->expr()->eq('actorId', '?')
            ))
            ->orderBy('version')
            ->setParameter(0, $identity->getClass(), ParameterType::STRING)
            ->setParameter(1, $identity->getId(), ParameterType::STRING)
            ->execute();

        $events = $this->hydrateEvents($results);

        if (empty($events)) {
            throw new NoEventsFound('Not found');
        }

        return $events;
    }

    public function save(ActorIdentity $identity, DomainMessage ...$messages): void
    {
        $this->entityManager->beginTransaction();

        foreach ($messages as $message) {
            $data = [];
            ($this->extractCallback)($message, $data);

            if ($message->isNewMessage()) {
                $this->entityManager->persist(Entity\DomainMessage::fromArray($data));
            }

            $this->entityManager->persist(ActorDomainMessage::fromArray($data));
        }

        $this->entityManager->flush();
        $this->entityManager->commit();
    }

    public function saveSnapshot(ActorIdentity $actorIdentity, int $version, string $snapshot): void
    {
        $latestSnapshot = $this->loadLatestSnapshot($actorIdentity);

        if ($latestSnapshot === null) {
            $entity = new Snapshot($actorIdentity->getId(), $actorIdentity->getClass(), $version, $snapshot);
            $this->entityManager->persist($entity);
        } else {
            $latestSnapshot->update($version, $snapshot);
        }

        $this->entityManager->flush();
    }

    public function hasSnapshot(ActorIdentity $actorIdentity): bool
    {
        $latestSnapshot = $this->loadLatestSnapshot($actorIdentity);

        return $latestSnapshot!== null;
    }

    public function loadSnapshot(ActorIdentity $actorIdentity): string
    {
        $latestSnapshot = $this->loadLatestSnapshot($actorIdentity);

        return $latestSnapshot->getSnapshot();
    }

    public function loadFromLastSnapshot(ActorIdentity $actorIdentity): iterable
    {
        $latestSnapshot = $this->loadLatestSnapshot($actorIdentity);
        $version = $latestSnapshot->getVersion();
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $results = $qb
            ->select('d.*', 'a.*')
            ->from('DomainMessage', 'd')
            ->leftJoin('d', 'ActorDomainMessage', 'a', 'a.domainMessageId = d.id')
            ->where($qb->expr()->andX(
                $qb->expr()->eq('actorClass', '?'),
                $qb->expr()->eq('actorId', '?'),
                $qb->expr()->gt('version', $version)
            ))
            ->orderBy('version')
            ->setParameter(0, $actorIdentity->getClass(), ParameterType::STRING)
            ->setParameter(1, $actorIdentity->getId(), ParameterType::STRING)
            ->execute();

        $events = $this->hydrateEvents($results);

        return $events;
    }

    private function loadLatestSnapshot(ActorIdentity $actorIdentity): ?Snapshot
    {
        return $this->entityManager->getRepository(Snapshot::class)->find(
            ['id' => $actorIdentity->getId(), 'class' => $actorIdentity->getClass()],
        );
    }

    private function hydrateEvents(ResultStatement $results): array
    {
        $events = [];

        while ($record = $results->fetch(FetchMode::ASSOCIATIVE)) {
            $domainMessage = DomainMessage::anonMessage('', new \stdClass());
            ($this->hydrateCallback)($domainMessage, $record);
            $events[] = $domainMessage;
        }

        return $events;
    }
}
