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

        // Custom fields are installed on first activation, not during install
        // This avoids dependency issues during plugin installation
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->getCustomFieldInstaller()?->update($updateContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        $this->getCustomFieldInstaller()?->install($activateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if (!$uninstallContext->keepUserData()) {
            $this->getCustomFieldInstaller()?->uninstall($uninstallContext->getContext());
        }
    }

    private function getCustomFieldInstaller(): ?CustomFieldInstaller
    {
        if ($this->container === null) {
            return null;
        }

        try {
            /** @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository $customFieldSetRepository */
            $customFieldSetRepository = $this->container->get('custom_field_set.repository');

            return new CustomFieldInstaller($customFieldSetRepository);
        } catch (\Exception $e) {
            return null;
        }
    }
}
