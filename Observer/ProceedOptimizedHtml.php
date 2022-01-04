<?php
/*
 *  Copyright 2016 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 *  Licensed under the Apache License, Version 2.0 (the "License").
 *  You may not use this file except in compliance with the License.
 *  A copy of the License is located at
 *
 *  http://aws.amazon.com/apache2.0
 *
 *  or in the "license" file accompanying this file. This file is distributed
 *  on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 *  express or implied. See the License for the specific language governing
 *  permissions and limitations under the License.
 */

namespace Ivoinov\DeferJS\Observer;

use Magento\Framework\Event\ObserverInterface;
use Ivoinov\DeferJS\Model\Config;
use Ivoinov\DeferJS\Model\Optimization;

class ProceedOptimizedHtml implements ObserverInterface
{
    /**
     * @var Config
     */
    private $configModel;
    /**
     * @var Optimization
     */
    private $optimizationModel;

    public function __construct(Config $configModel, Optimization $optimizationModel)
    {
        $this->configModel = $configModel;
        $this->optimizationModel = $optimizationModel;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->configModel->isEnabled()) {
            return;
        }
        $response = $observer->getEvent()->getData('response');
        if (!$response) {
            return;
        }
        $html = $response->getBody();
        $html = $this->optimizationModel->getHtml($html);
        if ($html == '') {
            return;
        }
        $response->setBody($html);
    }
}
