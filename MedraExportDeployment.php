<?php
/**
 * @file plugins/generic/medra/MedraExportDeployment.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MedraExportDeployment
 *
 * @brief Base class configuring the mEDRA export process to an
 * application's specifics.
 */

namespace APP\plugins\generic\medra;

use APP\plugins\PubObjectCache;
use APP\plugins\generic\medra\MedraExportPlugin;
use PKP\context\Context;
use PKP\plugins\ImportExportPlugin;


class MedraExportDeployment
{
	public const MEDRA_XMLNS = 'http://www.editeur.org/onix/DOIMetadata/2.0';
	public const MEDRA_XMLNS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';
	public const MEDRA_XSI_SCHEMAVERSION = '2.0';
	public const MEDRA_XSI_SCHEMALOCATION = 'http://www.medra.org/schema/onix/DOIMetadata/2.0/ONIX_DOIMetadata_2.0.xsd';
	public const MEDRA_XSI_SCHEMALOCATION_DEV = 'http://www-medra-dev.medra.org/schema/onix/DOIMetadata/2.0/ONIX_DOIMetadata_2.0.xsd';

	/**
	 * Get the plugin cache
	 */
	function getCache(): PubObjectCache
	{
		return $this->plugin->getCache();
	}

	function __construct(
		public Context $context,
		public MedraExportPlugin $plugin
	) {}

	//
	// Deployment items for subclasses to override
	//
	/**
	 * Get the namespace URN
	 */
	function getNamespace(): string
	{
		return self::MEDRA_XMLNS;
	}

	/**
	 * Get the schema instance URN
	 */
	function getXmlSchemaInstance(): string
	{
		return self::MEDRA_XMLNS_XSI;
	}

	/**
	 * Get the schema version
	 */
	function getXmlSchemaVersion(): string
	{
		return self::MEDRA_XSI_SCHEMAVERSION;
	}

	/**
	 * Get the schema location URL
	 */
	function getXmlSchemaLocation(): string
	{
		return $this->plugin->isTestMode($this->context) ? self::MEDRA_XSI_SCHEMALOCATION_DEV : self::MEDRA_XSI_SCHEMALOCATION;
	}

	/**
	 * Get the schema filename.
	 */
	function getSchemaFilename(): string
	{
		return $this->getXmlSchemaLocation();
	}

	//
	// Getter/setters
	//
	/**
	 * Set the import/export context.
	 */
	function setContext(Context $context): void
	{
		$this->context = $context;
	}

	/**
	 * Get the import/export context.
	 */
	function getContext(): Context
	{
		return $this->context;
	}

	/**
	 * Set the import/export plugin.
	 */
	function setPlugin(ImportExportPlugin $plugin): void
	{
		$this->plugin = $plugin;
	}

	/**
	 * Get the import/export plugin.
	 */
	function getPlugin(): ImportExportPlugin
	{
		return $this->plugin;
	}
}
