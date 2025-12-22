<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use ShopwareWebhookConnector\Installer\CustomFieldInstaller;

class ShopwareWebhookConnector extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $this->getCustomFieldInstaller()->install($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->getCustomFieldInstaller()->update($updateContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        $this->getCustomFieldInstaller()->update($activateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if (!$uninstallContext->keepUserData()) {
            $this->getCustomFieldInstaller()->uninstall($uninstallContext->getContext());
        }
    }

    private function getCustomFieldInstaller(): CustomFieldInstaller
    {
        /** @var CustomFieldInstaller $installer */
        $installer = $this->container->get(CustomFieldInstaller::class);

        return $installer;
    }
}
