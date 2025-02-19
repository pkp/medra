<?php

/**
 * @file plugins/generic/medra/filter/IssueMedraXmlFilter.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IssueMedraXmlFilter
 *
 * @brief Class that converts an Issue as work or manifestation to a O4DOI XML document.
 */

namespace APP\plugins\generic\medra\filter;

use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\issue\IssueGalleyDAO;
use APP\plugins\DOIPubIdExportPlugin;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\filter\FilterGroup;
use PKP\galley\Galley;
use PKP\plugins\importexport\native\PKPNativeImportExportDeployment;

class IssueMedraXmlFilter extends O4DOIXmlFilter
{
    /**
     * Constructor
     * @param $filterGroup FilterGroup
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('mEDRA XML issue export');
        parent::__construct($filterGroup);
    }

    /**
     * @copydoc O4DOIXmlFilter::isWork()
     */
    public function isWork(Context $context, DOIPubIdExportPlugin $plugin): bool
    {
        return $plugin->getSetting($context->getId(), 'exportIssuesAs') == self::O4DOI_ISSUE_AS_WORK;
    }

    /**
     *  @copydoc O4DOIXmlFilter::getRootNodeName
     */
    public function getRootNodeName(): string
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        return $this->isWork($context, $plugin) ? 'ONIXDOISerialIssueWorkRegistrationMessage' : 'ONIXDOISerialIssueVersionRegistrationMessage';
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @see Filter::process()
     * @param array $pubObjects Array of Issues
     * @return DOMDocument
     */
    public function &process(&$pubObjects)
    {
        // Create the XML document
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        // Create the root node
        $rootNode = $this->createRootNode($doc, $this->getRootNodeName());
        $doc->appendChild($rootNode);

        // Create and appet the header node and all parts inside it
        $rootNode->appendChild($this->createHeadNode($doc));

        // Create and append the issue nodes
        foreach ($pubObjects as $pubObject) {
            $rootNode->appendChild($this->createIssueNode($doc, $pubObject));
        }
        return $doc;
    }

    /**
     * Create and return an issue node, either as work or as manifestation.
     */
    public function createIssueNode(DOMDocument $doc, Issue $pubObject): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $cache = $deployment->getCache();
        $plugin = $deployment->getPlugin();
        $request = Application::get()->getRequest();

        $issueNodeName = $this->isWork($context, $plugin) ? 'DOISerialIssueWork' : 'DOISerialIssueVersion';
        $issueNode = $doc->createElementNS($deployment->getNamespace(), $issueNodeName);

        // Notification type (mandatory)
        $doi = $pubObject->getDoi();
        $registeredDoi = $pubObject->getData('medra::registeredDoi');
        assert(empty($registeredDoi) || $registeredDoi == $doi);
        $notificationType = (empty($registeredDoi) ? self::O4DOI_NOTIFICATION_TYPE_NEW : self::O4DOI_NOTIFICATION_TYPE_UPDATE);
        $issueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'NotificationType', $notificationType));

        // DOI (mandatory)
        $issueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'DOI', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));

        // DOI URL (mandatory)
        $dispatcher = $this->getDispatcher($request);
        $url = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'issue', 'view', $pubObject->getBestIssueId(), null, null, true);
        if ($plugin->isTestMode($context)) {
            // Change server domain for testing.
            $url = preg_replace('#://[^\s]+/index.php#u', '://example.com/index.php', $url);
        }
        $issueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'DOIWebsiteLink', $url));

        // DOI structural type
        $structuralType = $this->isWork($context, $plugin) ? 'Abstraction' : 'DigitalFixation';
        $issueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'DOIStructuralType', $structuralType));

        // Registrant (mandatory)
        $issueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'RegistrantName', htmlspecialchars($plugin->getSetting($context->getId(), 'registrantName'), ENT_COMPAT, 'UTF-8')));

        // Registration authority (mandatory)
        $issueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'RegistrationAuthority', 'mEDRA'));

        // Work/ProductIdentifier - proprietary ID
        $pubObjectProprietaryId = $context->getId() . '-' . $pubObject->getId();
        $workOrProduct = $this->isWork($context, $plugin) ? 'Work' : 'Product';
        $issueNode->appendChild($this->createIdentifierNode($doc, $workOrProduct, self::O4DOI_ID_TYPE_PROPRIETARY, $pubObjectProprietaryId));

        // Issue/journal and object locale precedence.
        $journalLocalePrecedence = $objectLocalePrecedence = $this->getObjectLocalePrecedence($context, null, null);

        // Serial Publication (mandatory)
        $issueNode->appendChild($this->createSerialPublicationNode($doc, $journalLocalePrecedence, self::O4DOI_EPUB_FORMAT_HTML));

        // Journal Issue (mandatory)
        $issueId = $pubObject->getId();
        if (!$cache->isCached('issues', $issueId)) {
            $cache->add($pubObject, null);
        }
        $issueNode->appendChild($this->createJournalIssueNode($doc, $pubObject, $journalLocalePrecedence));

        // Object Description 'OtherText'
        $descriptions = $this->getTranslationsByPrecedence($pubObject->getDescription(null), $objectLocalePrecedence);
        foreach ($descriptions as $locale => $description) {
            $issueNode->appendChild($this->createOtherTextNode($doc, $locale, $description));
        }

        // 4) issue (as-work and as-manifestation):
        // related works:
        // - includes articles-as-work
        $submissionsIterator = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterByIssueIds([$pubObject->getId()])
            ->filterByStatus([Submission::STATUS_PUBLISHED])
            ->getMany();
        $relatedGalleys = [];
        foreach ($submissionsIterator as $relatedSubmission) {
            /** @var Submission $relatedSubmission */
            $articleProprietaryId = $context->getId() . '-' . $pubObject->getId() . '-' . $relatedSubmission->getId();
            $relatedSubmissionIds = array(self::O4DOI_ID_TYPE_PROPRIETARY => $articleProprietaryId);
            $doi = $relatedSubmission->getCurrentPublication()->getDoi();
            if (!empty($doi)) {
                $relatedSubmissionIds[self::O4DOI_ID_TYPE_DOI] = $doi;
            }
            $issueNode->appendChild($this->createRelatedNode($doc, 'Work', self::O4DOI_RELATION_INCLUDES, $relatedSubmissionIds));
            $galleys = Repo::galley()
                ->getCollector()
                ->filterByPublicationIds([$relatedSubmission->getData('currentPublicationId')])
                ->getMany();
            $relatedGalleys[$relatedSubmission->getId()] = $galleys;
        }

        // related products:
        // - includes articles-as-manifestation
        foreach ($relatedGalleys as $relatedSubmissionId => $submissionGalleys) {
            foreach ($submissionGalleys as $galley) {
                /** @var Galley $galley */
                $galleyProprietaryId = $context->getId() . '-' . $pubObject->getId() . '-' . $relatedSubmissionId . '-g' . $galley->getId();
                $relatedGalleyIds = array(self::O4DOI_ID_TYPE_PROPRIETARY => $galleyProprietaryId);
                $doi = $galley->getDoi();
                if (!empty($doi)) {
                    $relatedGalleyIds[self::O4DOI_ID_TYPE_DOI] = $doi;
                }
                $issueNode->appendChild($this->createRelatedNode($doc, 'Product', self::O4DOI_RELATION_INCLUDES, $relatedGalleyIds));
            }
        }

        return $issueNode;
    }

    /**
     * @copydoc O4DOIXmlFilter::createJournalIssueNode()
     */
    public function createJournalIssueNode(DOMDocument $doc, Issue $issue, array $journalLocalePrecedence): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();

        $journalIssueNode = parent::createJournalIssueNode($doc, $issue, $journalLocalePrecedence);

        // Publication Date
        $datePublished = $issue->getDatePublished();
        if (!empty($datePublished)) {
            $journalIssueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'PublicationDate', date('Ymd', strtotime($datePublished))));
        }

        // Issue Title (mandatory)
        $localizedTitles = $this->getTranslationsByPrecedence($issue->getTitle(null), $journalLocalePrecedence);
        // Retrieve the first key/value pair...
        foreach ($localizedTitles as $locale => $localizedTitle) {
            break;
        }
        if (empty($localizedTitle)) {
            $localizedTitles = $this->getTranslationsByPrecedence($context->getName(null), $journalLocalePrecedence);
            // Retrieve the first key/value pair...
            foreach ($localizedTitles as $locale => $localizedTitle) {
                break;
            }
            assert(!empty($localizedTitle));

            // Hack to make sure that no untranslated title appears:
            $showTitle = $issue->getShowTitle();
            $issue->setShowTitle(0);
            $localizedTitle = $localizedTitle . ', ' . $issue->getIssueIdentification();
            $issue->setShowTitle($showTitle);
        }
        $journalIssueNode->appendChild($this->createTitleNode($doc, $locale, $localizedTitle, self::O4DOI_TITLE_TYPE_ISSUE));

        // Extent (for issues-as-manifestation only)
        if (!$this->isWork($context, $plugin)) {
            $issueGalleyDao = DAORegistry::getDAO('IssueGalleyDAO'); /** @var IssueGalleyDAO $issueGalleyDao */
            $issueGalleys = $issueGalleyDao->getByIssueId($issue->getId());
            foreach ($issueGalleys as $issueGalley) {
                $fileSize = $issueGalley->getFileSize();
                $journalIssueNode->appendChild($this->createExtentNode($doc, $fileSize));
            }
        }
        return $journalIssueNode;
    }
}
