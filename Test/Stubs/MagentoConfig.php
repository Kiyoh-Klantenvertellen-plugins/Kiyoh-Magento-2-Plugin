<?php

namespace Magento\Framework\App\Config;

interface ScopeConfigInterface
{
    public function getValue($path, $scopeType = null, $scopeCode = null);
}