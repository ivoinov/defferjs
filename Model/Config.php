<?php

namespace Ivoinov\DeferJS\Model;

use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Config
{
    /**
     * Data constructor.
     *
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        $this->scopeConfig = $context->getScopeConfig();
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return (bool)$this->scopeConfig->getValue('deferjs/general/active', ScopeInterface::SCOPE_STORE);
    }
}
