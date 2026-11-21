<?php

namespace Magento\Framework\App;

interface CacheInterface
{
    public function load($identifier);
    public function save($data, $identifier, $tags = [], $lifeTime = null);
    public function remove($identifier);
    public function clean($mode = null, $tags = []);
}