<?php

namespace Phactor\Doctrine\Entity;

class DomainMessage
{
    private $id;
    private $correlationId;
    private $causationId;
    private $time;
    private $message;
    private $messageClass;
    private $metadata = [];
    private $producerClass;
    private $producerId;
    private $produced;

    public static function fromArray(array $values)
    {
        $object = new self();
        $object->id = $values['id'];
        $object->correlationId = $values['correlationId'];
        $object->causationId = $values['causationId'];
        $object->time = $values['time'];
        $object->message = $values['message'];
        $object->messageClass = $values['messageClass'];
        $object->metadata = $values['metadata'];
        $object->producerClass = $values['producerClass'];
        $object->producerId = $values['producerId'];
        $object->produced = $values['produced'];

        return $object;
    }

    public function toArray()
    {
        $values = [];
        $values['id'] = $this->id;
        $values['correlationId'] = $this->correlationId;
        $values['causationId'] = $this->causationId;
        $values['time'] = $this->time;
        $values['message'] = $this->message;
        $values['messageClass'] = $this->messageClass;
        $values['metadata'] = $this->metadata;
        $values['producerClass'] = $this->producerClass;
        $values['producerId'] = $this->producerId;
        $values['produced'] = $this->produced;

        return $values;
    }
}
