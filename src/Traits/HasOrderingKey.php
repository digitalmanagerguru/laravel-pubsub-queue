<?php

namespace Digitalmanagerguru\PubSubQueue\Traits;

trait HasOrderingKey
{
    public $orderingKey;

    public function setOrderingKey(string $orderingKey): self
    {
        $this->orderingKey = $orderingKey;

        return $this;
    }
}
