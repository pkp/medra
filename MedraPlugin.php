<?php

/**
 * @file plugins/generic/medra/MedraPlugin.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2003-2024 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class MedraPlugin
 *
 * @brief Plugin to let managers export, and deposit DOIs and metadata to mEDRA
 *
 */

namespace APP\plugins\generic\medra;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\plugins\generic\medra\classes\MedraSettings;
use APP\plugins\IDoiRegistrationAgency;
use APP\submission\Submission;
use Illuminate\Support\Collection;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\doi\RegistrationAgencySettings;
use PKP\install\Installer;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\services\PKPSchemaService;
use PKP\site\VersionDAO;

class MedraPlugin extends GenericPlugin implements IDoiRegistrationAgency
{
    private ?MedraExportPlugin $exportPlugin = null;
    private MedraSettings $settingsObject;

    /**
     * @see Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.medra.displayName');
    }

    /**
     * @see Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.medra.description');
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            Hook::add('Installer::preInstall', [$this, 'preInstall']);
            // If the system isn't installed, or is performing an upgrade, don't
            // register hooks. This will prevent DB access attempts before the
            // schema is installed.
            if (Application::isUnderMaintenance()) {
                return true;
            }

            if ($this->getEnabled($mainContextId)) {
                $this->pluginInitialization();
            }
        }

        return $success;
    }

    /**
     * Remove plugin as configured registration agency if set at the time plugin is disabled.
     *
     * @copydoc LazyLoadPlugin::setEnabled()
     */
    public function setEnabled($enabled)
    {
        parent::setEnabled($enabled);
        if (!$enabled) {
            $contextId = $this->getCurrentContextId();
            /** @var \PKP\context\ContextDAO $contextDao */
            $contextDao = Application::getContextDAO();
            $context = $contextDao->getById($contextId);
            if ($context->getData(Context::SETTING_CONFIGURED_REGISTRATION_AGENCY) === $this->getName()) {
                $context->setData(Context::SETTING_CONFIGURED_REGISTRATION_AGENCY, Context::SETTING_NO_REGISTRATION_AGENCY);
                $contextDao->updateObject($context);
            }
        }
    }

    /**
     * Provides submissions metadata in ONIX4DOI format for download
     *
     * @param Submission[] $submissions
     */
    public function exportSubmissions(array $submissions, Context $context): array
    {
        // Get filter and set objectsFileNamePart (see: PubObjectsExportPlugin::prepareAndExportPubObjects)
        $filterName = $this->exportPlugin->getSubmissionFilter();
        $xmlErrors = [];

        $temporaryFileId = $this->exportPlugin->exportAsDownload($context, $submissions, $filterName, 'articles', null, $xmlErrors);
        return ['temporaryFileId' => $temporaryFileId, 'xmlErrors' => $xmlErrors];
    }

    /**
     * Registers submissions DOIs
     *
     * @param Submission[] $submissions
     */
    public function depositSubmissions(array $submissions, Context $context): array
    {
        $filterName = $this->exportPlugin->getSubmissionFilter();
        $responseMessage = '';
        $status = $this->exportPlugin->exportAndDeposit($context, $submissions, $filterName, $responseMessage);

        return [
            'hasErrors' => !$status,
            'responseMessage' => $responseMessage
        ];
    }

    /**
     * Provides issues metadata in ONIX4DOI format for download
     *
     * @param Issue[] $issues
     */
    public function exportIssues(array $issues, Context $context): array
    {
        // Get filter and set objectsFileNamePart (see: PubObjectsExportPlugin::prepareAndExportPubObjects)
        $filterName = $this->exportPlugin->getIssueFilter();
        $xmlErrors = [];

        $temporaryFileId = $this->exportPlugin->exportAsDownload($context, $issues, $filterName, 'issues', null, $xmlErrors);
        return ['temporaryFileId' => $temporaryFileId, 'xmlErrors' => $xmlErrors];
    }

    /**
     * Registers issues DOIs
     *
     * @param Issue[] $issues
     */
    public function depositIssues(array $issues, Context $context): array
    {
        $filterName = $this->exportPlugin->getIssueFilter();
        $responseMessage = '';
        $status = $this->exportPlugin->exportAndDeposit($context, $issues, $filterName, $responseMessage);

        return [
            'hasErrors' => !$status,
            'responseMessage' => $responseMessage
        ];
    }

    /**
     * Add properties for mEDRA to the DOI entity for storage in the database.
     *
     * @param string $hookName Schema::get::doi
     * @param array $args [
     *
     *      @option stdClass $schema
     * ]
     *
     */
    public function addToSchema(string $hookName, array $args): bool
    {
        $schema = &$args[0];

        $settings = [
            $this->getFailedMsgSettingName(),
        ];

        foreach ($settings as $settingName) {
            $schema->properties->{$settingName} = (object) [
                'type' => 'string',
                'apiSummary' => true,
                'validation' => ['nullable'],
            ];
        }

        return HOOK::CONTINUE;
    }

    /**
     * Includes plugin in list of configurable registration agencies for DOI depositing functionality
     *
     * @param string $hookName DoiSettingsForm::setEnabledRegistrationAgencies
     * @param array $args [
     *
     *      @option $enabledRegistrationAgencies array
     * ]
     */
    public function addAsRegistrationAgencyOption(string $hookName, array $args)
    {
        /** @var Collection<int,IDoiRegistrationAgency> $enabledRegistrationAgencies */
        $enabledRegistrationAgencies = &$args[0];
        $enabledRegistrationAgencies->add($this);
        return HOOK::CONTINUE;
    }

    /**
     * Includes human-readable name of registration agency for display in conjunction with how/with whom the
     * DOI was registered.
     *
     * @param string $hookName DoiListPanel::setConfig
     * @param array $args [
     *
     *      @option $config array
     * ]
     */
    public function addRegistrationAgencyName(string $hookName, array $args): bool
    {
        $config = &$args[0];
        $config['registrationAgencyNames'][$this->exportPlugin->getName()] = $this->getRegistrationAgencyName();
        return HOOK::CONTINUE;
    }

    /**
     * Get configured registration agency display name for use in DOI management pages
     *
     */
    public function getRegistrationAgencyName(): string
    {
        return __('plugins.importexport.medra.registrationAgency.name');
    }

    /**
     * Checks if plugin meets registration agency-specific requirements for being active and handling deposits
     *
     */
    public function isPluginConfigured(Context $context): bool
    {
        $settingsObject = $this->getSettingsObject();

        /** @var PKPSchemaService $schemaService */
        $schemaService = Services::get('schema');
        $requiredProps = $schemaService->getRequiredProps($settingsObject::class);

        foreach ($requiredProps as $requiredProp) {
            $settingValue = $this->getSetting($context->getId(), $requiredProp);
            if (empty($settingValue)) {
                return false;
            }
        }

        $doiPrefix = $context->getData(Context::SETTING_DOI_PREFIX);
        if (empty($doiPrefix)) {
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getSettingsObject(): RegistrationAgencySettings
    {
        if (!isset($this->settingsObject)) {
            $this->settingsObject = new MedraSettings($this);
        }
        return $this->settingsObject;
    }

    /**
     * Adds self to "allowed" list of pub object types that can be assigned DOIs for this registration agency.
     *
     * @param string $hookName DoiSetupSettingsForm::getObjectTypes
     * @param array $args [
     *
     *      @option array &$objectTypeOptions
     * ]
     */
    public function addAllowedObjectTypes(string $hookName, array $args): bool
    {
        $objectTypeOptions = &$args[0];
        $allowedTypes = $this->getAllowedDoiTypes();

        $objectTypeOptions = array_map(function ($option) use ($allowedTypes) {
            if (in_array($option['value'], $allowedTypes)) {
                $option['allowedBy'][] = $this->getName();
            }
            return $option;
        }, $objectTypeOptions);

        return Hook::CONTINUE;
    }

    /**
     * @inheritDoc
     */
    public function getAllowedDoiTypes(): array
    {
        return [
            Repo::doi()::TYPE_PUBLICATION,
            Repo::doi()::TYPE_ISSUE,
        ];
    }

    /**
     * Adds mEDRA specific info to Repo::doi()->markRegistered()
     *
     * @param string $hookName Doi::markRegistered
     *
     */
    public function editMarkRegisteredParams(string $hookName, array $args): bool
    {
        $editParams = &$args[0];
        $editParams[$this->getFailedMsgSettingName()] = null;
        return HOOK::CONTINUE;
    }

    /**
     * Get key for retrieving error message if one exists on DOI object
     */
    public function getErrorMessageKey(): ?string
    {
        return $this->getFailedMsgSettingName();
    }

    /**
     * Get key for retrieving registered message if one exists on DOI object
     *
     */
    public function getRegisteredMessageKey(): ?string
    {
        return null;
    }

    /**
     * Get request failed message setting name.
     */
    public function getFailedMsgSettingName(): string
    {
        return $this->getName() . '_failedMsg';
    }

    /**
     * Helper to register hooks that are used in normal plugin setup.
     */
    private function pluginInitialization()
    {
        PluginRegistry::register('importexport', new MedraExportPlugin($this), $this->getPluginPath());
        $this->exportPlugin = PluginRegistry::getPlugin('importexport', 'MedraExportPlugin');

        Hook::add('DoiSettingsForm::setEnabledRegistrationAgencies', [$this, 'addAsRegistrationAgencyOption']);
        Hook::add('DoiSetupSettingsForm::getObjectTypes', [$this, 'addAllowedObjectTypes']);
        Hook::add('DoiListPanel::setConfig', [$this, 'addRegistrationAgencyName']);
        Hook::add('Schema::get::doi', [$this, 'addToSchema']);
        Hook::add('Doi::markRegistered', [$this, 'editMarkRegisteredParams']);
    }

    /**
     * Call the migration script before the plugin installation
     *
     * @param string $hookName Installer::preInstall
     */
    public function preInstall($hookName, $args)
    {
        /** @var Installer $installer */
        $installer = $args[0];
        $version = $installer->getCurrentVersion();
        if ($version->getProduct() == 'medra' && $version->getProductType() == 'plugins.generic') {
            /** @var VersionDAO $versionDao */
            $versionDao = DAORegistry::getDAO('VersionDAO');
            $installedPluginVersion = $versionDao->getCurrentVersion($version->getProductType(), $version->getProduct());
            if (!$installedPluginVersion) {
                $migration = new MedraDoiDataMigration($installer, $this);
                $migration->up();
            }
        }
        return HOOK::CONTINUE;
    }
}
