<?php

declare(strict_types=1);

namespace ShopwareWebhookConnector\Installer;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * CustomFieldInstaller
 *
 * Creates and manages custom fields for checkout integration:
 * - Customer PO Number (Bestellnummer / Kommission)
 * - Tel. Avis (Telefonisches Avis)
 * - Forklift Required (Hebebühne)
 * - Invoice Email (Abweichende Rechnungs-E-Mail)
 */
class CustomFieldInstaller
{
    public const CUSTOM_FIELD_SET_NAME = 'webhook_connector_checkout';
    private const CONFIG_PREFIX = 'ShopwareWebhookConnector.config.';

    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function install(Context $context): void
    {
        if ($this->isEnabled()) {
            $this->createCustomFieldSet($context);
        }
    }

    public function update(Context $context): void
    {
        if ($this->isEnabled()) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELD_SET_NAME));
            $result = $this->customFieldSetRepository->search($criteria, $context);

            if ($result->getTotal() === 0) {
                $this->createCustomFieldSet($context);
            }
        } else {
            // Custom fields disabled - optionally remove them
            // For safety, we don't auto-remove to preserve data
        }
    }

    public function uninstall(Context $context): void
    {
        $this->removeCustomFieldSet($context);
    }

    private function isEnabled(): bool
    {
        return (bool) $this->systemConfigService->get(self::CONFIG_PREFIX . 'enableCheckoutCustomFields');
    }

    private function createCustomFieldSet(Context $context): void
    {
        // Check if already exists
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELD_SET_NAME));

        $result = $this->customFieldSetRepository->search($criteria, $context);

        if ($result->getTotal() > 0) {
            return;
        }

        // Create custom field set for orders
        $this->customFieldSetRepository->create([
            [
                'name' => self::CUSTOM_FIELD_SET_NAME,
                'config' => [
                    'label' => [
                        'de-DE' => 'Webhook Connector - Checkout-Optionen',
                        'en-GB' => 'Webhook Connector - Checkout Options',
                    ],
                ],
                'customFields' => [
                    [
                        'name' => 'custom_po_number',
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'de-DE' => 'Ihre Bestellnummer / Kommission',
                                'en-GB' => 'Your Order Reference / Commission',
                            ],
                            'helpText' => [
                                'de-DE' => 'Optional: Interne Bestellnummer des Kunden',
                                'en-GB' => 'Optional: Customer\'s internal order reference',
                            ],
                            'placeholder' => [
                                'de-DE' => 'z.B. Auftrag-2025-001',
                                'en-GB' => 'e.g. Order-2025-001',
                            ],
                            'componentName' => 'sw-text-field',
                            'customFieldType' => 'text',
                            'customFieldPosition' => 1,
                        ],
                    ],
                    [
                        'name' => 'custom_tel_avis',
                        'type' => CustomFieldTypes::BOOL,
                        'config' => [
                            'label' => [
                                'de-DE' => 'Tel. Avis gewünscht (+7,50 €)',
                                'en-GB' => 'Telephone notification required (+7.50 €)',
                            ],
                            'helpText' => [
                                'de-DE' => 'Wir rufen Sie vor der Zustellung an',
                                'en-GB' => 'We will call you before delivery',
                            ],
                            'componentName' => 'sw-checkbox-field',
                            'customFieldType' => 'checkbox',
                            'customFieldPosition' => 2,
                        ],
                    ],
                    [
                        'name' => 'custom_forklift_required',
                        'type' => CustomFieldTypes::BOOL,
                        'config' => [
                            'label' => [
                                'de-DE' => 'Hebebühne erforderlich',
                                'en-GB' => 'Forklift required',
                            ],
                            'helpText' => [
                                'de-DE' => 'Bei schweren/sperrigen Artikeln',
                                'en-GB' => 'For heavy/bulky items',
                            ],
                            'componentName' => 'sw-checkbox-field',
                            'customFieldType' => 'checkbox',
                            'customFieldPosition' => 3,
                        ],
                    ],
                    [
                        'name' => 'invoice_email',
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'de-DE' => 'Abweichende Rechnungs-E-Mail',
                                'en-GB' => 'Alternative Invoice Email',
                            ],
                            'helpText' => [
                                'de-DE' => 'Falls Rechnungen an eine andere Adresse gehen sollen',
                                'en-GB' => 'If invoices should be sent to a different address',
                            ],
                            'placeholder' => [
                                'de-DE' => 'buchhaltung@firma.de',
                                'en-GB' => 'accounting@company.com',
                            ],
                            'componentName' => 'sw-text-field',
                            'customFieldType' => 'text',
                            'customFieldPosition' => 4,
                        ],
                    ],
                ],
                'relations' => [
                    [
                        'entityName' => 'order',
                    ],
                ],
            ],
        ], $context);
    }

    private function removeCustomFieldSet(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELD_SET_NAME));

        $result = $this->customFieldSetRepository->search($criteria, $context);

        if ($result->getTotal() === 0) {
            return;
        }

        $ids = [];
        foreach ($result->getEntities() as $entity) {
            $ids[] = ['id' => $entity->getId()];
        }

        $this->customFieldSetRepository->delete($ids, $context);
    }
}
