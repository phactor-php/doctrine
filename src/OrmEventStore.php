<?php

namespace Phactor\Doctrine;

use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Phactor\Actor\ActorIdentity;
use Phactor\Doctrine\Entity\ActorDomainMessage;
use Phactor\DomainMessage;
use Phactor\EventStore\EventStore as EventStoreInterface;
use Phactor\EventStore\NoEventsFound;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Class OrmEventStore
 * @package Carnage\Cqorms\Persistence\EventStore
 */
final class OrmEventStore implements EventStoreInterface
{
    private $entityManager;
    private $hydrateCallback;
    private $extractCallback;

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

    public function eventsMatching(Criteria $criteria): Iterable
    {
        $repository = $this->entityManager->getRepository(DomainMessage::class);
        return $repository->matching($criteria);
    }

    public function load(ActorIdentity $identity): Iterable
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
            ->setParameter(0, $identity->getClass(), ParameterType::STRING)
            ->setParameter(1, $identity->getId(), ParameterType::STRING)
            ->execute();

        $events = [];

        while($record = $results->fetch(FetchMode::ASSOCIATIVE)) {
            $domainMessage = DomainMessage::anonMessage('', new \stdClass());
            ($this->hydrateCallback)($domainMessage, $record);
            $events[] = $domainMessage;
        }

        if (empty($events)) {
            throw new NoEventsFound('Not found');
        }

        return $events;
    }

    public function save(ActorIdentity $identity, DomainMessage ...$messages)
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
}
