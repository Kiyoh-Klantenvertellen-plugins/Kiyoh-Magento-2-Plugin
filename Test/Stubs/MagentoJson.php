<?php

namespace Magento\Framework\Serialize\Serializer;

class Json
{
    public function serialize($data): string
    {
        return (string) json_encode($data);
    }

    /**
     * @return mixed
     */
    public function unserialize(string $string)
    {
        return json_decode($string, true);
    }
}