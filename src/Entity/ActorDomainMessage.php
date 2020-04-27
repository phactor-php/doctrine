<?php


namespace Phactor\Doctrine\Entity;


class ActorDomainMessage
{
    private $actorId;
    private $actorClass;
    private $recorded;
    private $version;
    private $domainMessageId;

    public static function fromArray(array $values)
    {
        $object = new self();
        $object->actorId = $values['actorId'];
        $object->actorClass = $values['actorClass'];
        $object->recorded = $values['recorded'];
        $object->version = $values['version'];
        $object->domainMessageId = $values['id'];

        return $object;
    }

    public function toArray()
    {
        $values = [];
        $values['actorId'] = $this->actorId;
        $values['actorClass'] = $this->actorClass;
        $values['recorded'] = $this->recorded;
        $values['version'] = $this->version;
        $values['domainMessage'] = $this->domainMessage;

        return $values;
    }
}
