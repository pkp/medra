<?php

/**
 * @file plugins/generic/medra/filter/O4DOIXmlFilter.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class O4DOIXmlFilter
 *
 * @brief Basis class for converting objects (issues, articles, galleys) to a O4DOI XML document.
 */

namespace APP\plugins\generic\medra\filter;

use APP\core\Application;
use APP\core\Request;
use APP\issue\Issue;
use APP\plugins\DOIPubIdExportPlugin;
use APP\submission\Submission;
use PKP\context\Context;
use DOMDocument;
use DOMElement;
use PKP\core\Dispatcher;
use PKP\core\PKPString;
use PKP\facades\Locale;
use PKP\filter\FilterGroup;
use PKP\galley\Galley;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\native\filter\NativeExportFilter;
use PKP\plugins\importexport\native\PKPNativeImportExportDeployment;

abstract class O4DOIXmlFilter extends NativeExportFilter
{
    // Work or manifestation
    public const O4DOI_ISSUE_AS_WORK = 0x01;
    public const O4DOI_ISSUE_AS_MANIFESTATION = 0x02;
    public const O4DOI_ARTICLE_AS_WORK = 0x03;
    public const O4DOI_ARTICLE_AS_MANIFESTATION = 0x04;

    // Notification types
    public const O4DOI_NOTIFICATION_TYPE_NEW = '06';
    public const O4DOI_NOTIFICATION_TYPE_UPDATE = '07';

    // ID types
    public const O4DOI_ID_TYPE_PROPRIETARY = '01';
    public const O4DOI_ID_TYPE_DOI = '06';
    public const O4DOI_ID_TYPE_ISSN = '07';

    // Text formats
    public const O4DOI_TEXTFORMAT_ASCII = '00';

    // Title types
    public const O4DOI_TITLE_TYPE_FULL = '01';
    public const O4DOI_TITLE_TYPE_ISSUE = '07';

    // Name identifier types
    public const O4DOI_NAME_IDENTIFIER_TYPE_PROPRIETARY = '01';
    public const O4DOI_NAME_IDENTIFIER_TYPE_ISNI = '16';
    public const O4DOI_NAME_IDENTIFIER_TYPE_ORCID = '21';

    // Publishing roles
    public const O4DOI_PUBLISHING_ROLE_PUBLISHER = '01';

    // Product forms
    public const O4DOI_PRODUCT_FORM_PRINT = 'JB';
    public const O4DOI_PRODUCT_FORM_ELECTRONIC = 'JD';

    // ePublication formats
    // S. ONIX List 11 (https://onix-codelists.io/codelist/11)
    // We will consider only HTML and PDF
    public const O4DOI_EPUB_FORMAT_HTML = '01';
    public const O4DOI_EPUB_FORMAT_PDF = '02';

    // Date formats
    public const O4DOI_DATE_FORMAT_YYYY = '05';

    // Extent types
    public const O4DOI_EXTENT_TYPE_FILESIZE = '22';

    // Extent units
    public const O4DOI_EXTENT_UNIT_BYTES = '17';

    // Contributor roles
    public const O4DOI_CONTRIBUTOR_ROLE_ACTUAL_AUTHOR = 'A01';

    // Language roles
    public const O4DOI_LANGUAGE_ROLE_LANGUAGE_OF_TEXT = '01';

    // Subject schemes
    public const O4DOI_SUBJECT_SCHEME_KEYWORDS = '20';
    public const O4DOI_SUBJECT_SCHEME_PUBLISHER = '23';
    public const O4DOI_SUBJECT_SCHEME_PROPRIETARY = '24';

    // Text type codes
    public const O4DOI_TEXT_TYPE_MAIN_DESCRIPTION = '01';

    // Relation codes
    public const O4DOI_RELATION_INCLUDES = '80';
    public const O4DOI_RELATION_IS_PART_OF = '81';
    public const O4DOI_RELATION_IS_A_NEW_VERSION_OF = '82';
    public const O4DOI_RELATION_HAS_A_NEW_VERSION = '83';
    public const O4DOI_RELATION_IS_A_DIFFERENT_FORM_OF = '84';
    public const O4DOI_RELATION_IS_A_LANGUAGE_VERSION_OF = '85';
    public const O4DOI_RELATION_IS_MANIFESTED_IN = '89';
    public const O4DOI_RELATION_IS_A_MANIFESTATION_OF = '90';

    /**
     * Constructor
     * @param FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        parent::__construct($filterGroup);
    }

    /**
     * Get whether the object exported is considered as work
     */
    public function isWork(Context $context, DOIPubIdExportPlugin $plugin): bool
    {
        return true;
    }

    /**
     * Get root node name
     */
    abstract public function getRootNodeName(): string;

    //
    // Common filter functions
    //
    /**
     * Create and return the root node.
     */
    public function createRootNode(DOMDocument $doc, string $rootNodeName): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $rootNode = $doc->createElementNS($deployment->getNamespace(), $rootNodeName);
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $deployment->getXmlSchemaInstance());
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());
        return $rootNode;
    }

    /**
     * Create and return the head node.
     */
    public function createHeadNode(DOMDocument $doc): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        $headNode = $doc->createElementNS($deployment->getNamespace(), 'Header');
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'FromCompany', htmlspecialchars($plugin->getSetting($context->getId(), 'fromCompany'), ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'FromPerson', htmlspecialchars($plugin->getSetting($context->getId(), 'fromName'), ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'FromEmail', htmlspecialchars($plugin->getSetting($context->getId(), 'fromEmail'), ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ToCompany', 'mEDRA'));
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'SentDate', date('YmdHi')));
        // Message note
        $app = Application::get();
        $name = $app->getName();
        $version = $app->getCurrentVersion();
        $versionString = $version->getVersionString();
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'MessageNote', "This dataset was exported with $name, version $versionString."));
        return $headNode;
    }

    /**
     * Generate O4DOI serial publication node.
     *
     * @param ?string $epubFormat O4DOI_EPUB_FORMAT_*
     */
    public function createSerialPublicationNode(DOMDocument $doc, array $journalLocalePrecedence, ?string $epubFormat = null): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        $serialPublicationNode = $doc->createElementNS($deployment->getNamespace(), 'SerialPublication');
        // Serial Work (mandatory)
        $serialPublicationNode->appendChild($this->createSerialWorkNode($doc, $journalLocalePrecedence));
        // Electronic Serial Version
        $onlineIssn = $context->getData('onlineIssn') ?? '';
        $serialPublicationNode->appendChild($this->createSerialVersionNode($doc, $onlineIssn, self::O4DOI_PRODUCT_FORM_ELECTRONIC, $epubFormat));
        // Print Serial Version
        if (($printIssn = $context->getData('printIssn')) && $this->isWork($context, $plugin)) {
            $serialPublicationNode->appendChild($this->createSerialVersionNode($doc, $printIssn, self::O4DOI_PRODUCT_FORM_PRINT, null));
        }
        return $serialPublicationNode;
    }

    /**
     * Generate O4DOI serial work node.
     */
    public function createSerialWorkNode(DOMDocument $doc, array $journalLocalePrecedence): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        $serialWorkNode = $doc->createElementNS($deployment->getNamespace(), 'SerialWork');
        // Title (mandatory)
        $journalTitles = $this->getTranslationsByPrecedence($context->getName(), $journalLocalePrecedence);
        assert(!empty($journalTitles));
        foreach ($journalTitles as $locale => $journalTitle) {
            $serialWorkNode->appendChild($this->createTitleNode($doc, $locale, $journalTitle, self::O4DOI_TITLE_TYPE_FULL));
        }
        // Publisher
        $serialWorkNode->appendChild($this->createPublisherNode($doc, $journalLocalePrecedence));
        // Country of Publication (mandatory)
        $serialWorkNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'CountryOfPublication', htmlspecialchars($plugin->getSetting($context->getId(), 'publicationCountry'), ENT_COMPAT, 'UTF-8')));
        return $serialWorkNode;
    }

    /**
     * Create a title node.
     *
     * @param string $titleType O4DOI_TITLE_TYPE_*
     */
    public function createTitleNode(DOMDocument $doc, string $locale, string $localizedTitle, string $titleType, ?string $localizedSubtitle = null): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $titleNode = $doc->createElementNS($deployment->getNamespace(), 'Title');
        // Text format
        $titleNode->setAttribute('textformat', self::O4DOI_TEXTFORMAT_ASCII);
        // Language
        $language = LocaleConversion::get3LetterIsoFromLocale($locale);
        assert(!empty($language));
        $titleNode->setAttribute('language', $language);
        // Title type (mandatory)
        $titleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'TitleType', $titleType));
        // Title text (mandatory)
        $titleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'TitleText', htmlspecialchars(PKPString::html2text($localizedTitle), ENT_COMPAT, 'UTF-8')));
        // Subtitle (optional)
        if ($localizedSubtitle) {
            $titleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'Subtitle', htmlspecialchars(PKPString::html2text($localizedSubtitle), ENT_COMPAT, 'UTF-8')));
        }
        return $titleNode;
    }

    /**
     * Create a NameIdentifier node.
     *
     * @param string $nameIDType O4DOI_NAME_IDENTIFIER_TYPE_*
     */
    public function createNameIdentifierNode(DOMDocument $doc, string $nameIDType, string $idValue): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $nameIdentifierNode = $doc->createElementNS($deployment->getNamespace(), 'NameIdentifier');
        // NameIDType (mandatory)
        $nameIdentifierNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'NameIDType', $nameIDType));
        // IDValue (mandatory)
        $nameIdentifierNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'IDValue', $idValue));
        return $nameIdentifierNode;
    }

    /**
     * Create a publisher node.
     */
    public function createPublisherNode(DOMDocument $doc, array $journalLocalePrecedence): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $publisherNode = $doc->createElementNS($deployment->getNamespace(), 'Publisher');
        // Publishing role (mandatory)
        $publisherNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'PublishingRole', self::O4DOI_PUBLISHING_ROLE_PUBLISHER));
        // Publisher name (mandatory)
        $publisher = $context->getData('publisherInstitution');
        if (empty($publisher)) {
            // Use the journal title if no publisher is set.
            // This corresponds to the logic implemented for OAI interfaces, too.
            $publisher = $this->getPrimaryTranslation($context->getName(null), $journalLocalePrecedence);
        }
        assert(!empty($publisher));
        $publisherNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'PublisherName', htmlspecialchars($publisher, ENT_COMPAT, 'UTF-8')));
        return $publisherNode;
    }

    /**
     * Create a serial version node.
     *
     * @param string $productForm O4DOI_PRODUCT_FORM_*
     * @param ?string $epubFormat O4DOI_EPUB_FORMAT_*
     */
    public function createSerialVersionNode(DOMDocument $doc, string $issn, string $productForm, ?string $epubFormat = null): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $serialVersionNode = $doc->createElementNS($deployment->getNamespace(), 'SerialVersion');

        // Proprietary Journal Identifier
        if ($productForm == self::O4DOI_PRODUCT_FORM_ELECTRONIC) {
            $serialVersionNode->appendChild($this->createIdentifierNode($doc, 'Product', self::O4DOI_ID_TYPE_PROPRIETARY, $context->getId()));
        }

        // ISSN
        if (!empty($issn)) {
            $serialVersionNode->appendChild($this->createIdentifierNode($doc, 'Product', self::O4DOI_ID_TYPE_ISSN, $issn));
        }

        // Product Form
        $serialVersionNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ProductForm', $productForm));
        if ($productForm == self::O4DOI_PRODUCT_FORM_ELECTRONIC) {
            // ePublication Format
            if ($epubFormat) {
                $serialVersionNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'EpubFormat', $epubFormat));
            }
            // ePublication Format Description
            $serialVersionNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'EpubFormatDescription', 'Open Journal Systems (OJS)'));
        }
        return $serialVersionNode;
    }

    /**
     * Create the journal issue node.
     */
    public function createJournalIssueNode(DOMDocument $doc, Issue $issue, array $journalLocalePrecedence): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $journalIssueNode = $doc->createElementNS($deployment->getNamespace(), 'JournalIssue');

        // Volume
        $volume = $issue->getVolume();
        if (!empty($volume) && $issue->getShowVolume()) {
            $journalIssueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'JournalVolumeNumber', htmlspecialchars($volume, ENT_COMPAT, 'UTF-8')));
        }

        // Number
        $number = $issue->getNumber();
        if (!empty($number) && $issue->getShowNumber()) {
            $journalIssueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'JournalIssueNumber', htmlspecialchars($number, ENT_COMPAT, 'UTF-8')));
        }

        // Identification
        $identification = $issue->getIssueIdentification();
        if (!empty($identification)) {
            $journalIssueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'JournalIssueDesignation', htmlspecialchars($identification, ENT_COMPAT, 'UTF-8')));
        }
        assert(!(empty($number) && empty($identification)));

        // Nominal Year
        $year = (string) $issue->getYear();
        $yearlen = strlen($year);
        if ($issue->getShowYear() && !empty($year) && ($yearlen == 2 || $yearlen == 4)) {
            $issueDateNode = $doc->createElementNS($deployment->getNamespace(), 'JournalIssueDate');
            $issueDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'DateFormat', self::O4DOI_DATE_FORMAT_YYYY));
            // Try to extend the year if necessary.
            if ($yearlen == 2) {
                // Assume that the issue date will never be
                // more than one year in the future.
                if ((int)$year <= (int)date('y') + 1) {
                    $year = '20' . $year;
                } else {
                    $year = '19' . $year;
                }
            }
            $issueDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'Date', $year));
            $journalIssueNode->appendChild($issueDateNode);
        }
        return $journalIssueNode;
    }

    /**
     * Create a related work or product node.
     *
     * @param string $relationCode O4DOI_RELATION_*
     */
    public function createRelatedNode(DOMDocument $doc, string $workOrProduct, string $relationCode, array $ids): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $relatedNode = $doc->createElementNS($deployment->getNamespace(), "Related$workOrProduct");

        // Relation code (mandatory)
        $relatedNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'RelationCode', $relationCode));

        // Work/Product ID (mandatory)
        foreach ($ids as $idType => $id) {
            $relatedNode->appendChild($this->createIdentifierNode($doc, $workOrProduct, $idType, $id));
        }
        return $relatedNode;
    }

    /**
     * Create a work or product id node.
     * @param string $workOrProduct "Work" or "Product"
     * @param string $idType O4DOI_ID_TYPE_*
     */
    public function createIdentifierNode(DOMDocument $doc, string $workOrProduct, string $idType, string $id): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $productIdentifierNode = $doc->createElementNS($deployment->getNamespace(), "{$workOrProduct}Identifier");

        // ID type (mandatory)
        $productIdentifierNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), "{$workOrProduct}IDType", $idType));

        // ID (mandatory)
        $productIdentifierNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'IDValue', $id));
        return $productIdentifierNode;
    }

    /**
     * Create an extent node.
     */
    public function createExtentNode(DOMDocument $doc, int $fileSize): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $extentNode = $doc->createElementNS($deployment->getNamespace(), 'Extent');

        // Extent type
        $extentNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ExtentType', self::O4DOI_EXTENT_TYPE_FILESIZE));

        // Extent value
        $extentNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ExtentValue', $fileSize));

        // Extent unit
        $extentNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ExtentUnit', self::O4DOI_EXTENT_UNIT_BYTES));

        return $extentNode;
    }

    /**
     * Create a description text node.
     */
    public function createOtherTextNode(DOMDocument $doc, string $locale, string $description): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $otherTextNode = $doc->createElementNS($deployment->getNamespace(), 'OtherText');

        // Text Type
        $otherTextNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'TextTypeCode', self::O4DOI_TEXT_TYPE_MAIN_DESCRIPTION));

        // Text
        $language = LocaleConversion::get3LetterIsoFromLocale($locale);
        assert(!empty($language));
        $otherTextNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'Text', htmlspecialchars(PKPString::html2text($description), ENT_COMPAT, 'UTF-8')));
        $node->setAttribute('textformat', self::O4DOI_TEXTFORMAT_ASCII);
        $node->setAttribute('language', $language);
        return $otherTextNode;
    }

    //
    // Helper functions
    //
    /**
     * Get DOIStructuralType
     */
    public function getDOIStructuralType(): string
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        if ($this->isWork($context, $plugin)) {
            return 'Abstraction';
        } else {
            return 'DigitalFixation';
        }
    }

    /**
     * Identify the locale precedence for this export.
     *
     * @return array A list of valid PKP locales in descending
     *  order of priority.
     */
    public function getObjectLocalePrecedence(Context $context, ?Submission $article, ?Galley $galley): array
    {
        $locales = array();
        if (is_a($galley, 'Galley') && Locale::isLocaleValid($galley->getLocale())) {
            $locales[] = $galley->getLocale();
        }
        if (is_a($article, 'Submission')) {
            if (Locale::isLocaleValid($article->getData('locale'))) {
                $locales[] = $article->getData('locale');
            }
        }

        // Use the journal locale as fallback.
        $locales[] = $context->getPrimaryLocale();

        // Use form locales as fallback.
        $formLocales = array_keys($context->getSupportedFormLocaleNames());
        // Sort form locales alphabetically so that
        // we get a well-defined order.
        sort($formLocales);
        foreach ($formLocales as $formLocale) {
            if (!in_array($formLocale, $locales)) {
                $locales[] = $formLocale;
            }
        }

        assert(!empty($locales));
        return $locales;
    }

    /**
     * Identify the primary translation from an array of
     * localized data.
     *
     * @param ?array $localizedData An array of localized
     *  data (key: locale, value: localized data).
     * @param array $localePrecedence An array of locales
     *  by descending priority.
     *
     * @return mixed|null The value of the primary locale
     *  or null if no primary translation could be found.
     */
    public function getPrimaryTranslation(?array $localizedData, array $localePrecedence)
    {
        // Check whether we have localized data at all.
        if (!is_array($localizedData) || empty($localizedData)) {
            return null;
        }

        // Try all locales from the precedence list first.
        foreach ($localePrecedence as $locale) {
            if (isset($localizedData[$locale]) && !empty($localizedData[$locale])) {
                return $localizedData[$locale];
            }
        }

        // As a fallback: use any translation by alphabetical
        // order of locales.
        ksort($localizedData);
        foreach ($localizedData as $locale => $value) {
            if (!empty($value)) {
                return $value;
            }
        }

        // If we found nothing (how that?) return null.
        return null;
    }

    /**
     * Re-order localized data by locale precedence.
     * @param ?array $localizedData An array of localized
     *  data (key: locale, value: localized data).
     * @param array $localePrecedence An array of locales
     *  by descending priority.
     * @return array Re-ordered localized data.
     */
    public function getTranslationsByPrecedence(?array $localizedData, array $localePrecedence): array
    {
        $reorderedLocalizedData = array();

        // Check whether we have localized data at all.
        if (!is_array($localizedData) || empty($localizedData)) {
            return $reorderedLocalizedData;
        }

        // Order by explicit locale precedence first.
        foreach ($localePrecedence as $locale) {
            if (isset($localizedData[$locale]) && !empty($localizedData[$locale])) {
                $reorderedLocalizedData[$locale] = $localizedData[$locale];
            }
            unset($localizedData[$locale]);
        }

        // Order any remaining values alphabetically by locale
        // and amend the re-ordered array.
        ksort($localizedData);
        return array_merge($reorderedLocalizedData, $localizedData);
    }

    /**
     * Helper to ensure dispatcher is available even when called from CLI tools
     */
    protected function getDispatcher(Request $request): Dispatcher
    {
        return $request->getDispatcher() ?? Application::get()->getDispatcher();
    }
}
