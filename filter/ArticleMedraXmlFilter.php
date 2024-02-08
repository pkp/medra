<?php

/**
 * @file plugins/generic/medra/filter/ArticleMedraXmlFilter.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleMedraXmlFilter
 *
 * @brief Class that converts an Article as work to a O4DOI XML document.
 */

namespace APP\plugins\generic\medra\filter;

use APP\author\Author;
use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\plugins\DOIPubIdExportPlugin;
use APP\plugins\generic\medra\filter\O4DOIXmlFilter;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use PKP\context\Context;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\galley\Galley;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\PKPNativeImportExportDeployment;
use PKP\submission\GenreDAO;


class ArticleMedraXmlFilter extends O4DOIXmlFilter
{

    /**
     * Constructor
     * @param \PKP\filter\FilterGroup $filterGroup
     */
    function __construct($filterGroup)
    {
        parent::__construct($filterGroup);
        $this->setDisplayName('mEDRA XML article export');
    }

    /**
     * @copydoc O4DOIXmlFilter::isWork()
     */
    function isWork(Context $context, DOIPubIdExportPlugin $plugin): bool
    {
        return true;
    }

    /**
     *  @copydoc O4DOIXmlFilter::getRootNodeName
     */
    function getRootNodeName(): string
    {
        return 'ONIXDOISerialArticleWorkRegistrationMessage';
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @see Filter::process()
     * @param Submission|Galley[] $pubObjects
     * @return DOMDocument
     */
    function &process(&$pubObjects)
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

        // Create and append the article nodes,
        // containing all article information
        foreach($pubObjects as $pubObject) {
            $rootNode->appendChild($this->createArticleNode($doc, $pubObject));
        }
        return $doc;
    }

    /**
     * Create and return the article (as work or as manifestation) node.
     */
    function createArticleNode(DOMDocument $doc, Submission|Galley $pubObject): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $cache = $deployment->getCache();
        $plugin = $deployment->getPlugin();
        $request = Application::get()->getRequest();

        if (is_a($pubObject, 'Submission')) {
            $galley = null;
            /** @var Submission $article */
            $article = $pubObject;
            $doi = $article->getCurrentPublication()->getDoi();
            $registeredDoi = $article->getCurrentPublication()->getData('medra::registeredDoi');
            if (!$cache->isCached('articles', $article->getId())) {
                $cache->add($article, null);
            }
            $articleNodeName = 'DOISerialArticleWork';
            $workOrProduct = 'Work';
            $epubFormat = self::O4DOI_EPUB_FORMAT_HTML;
        } else {
            /** @var Galley $galley */
            $galley = $pubObject;
            $doi = $pubObject->getDoi();
            $registeredDoi = $pubObject->getData('medra::registeredDoi');
            $publication = Repo::publication()->get($galley->getData('publicationId'));
            if ($cache->isCached('articles', $publication->getData('submissionId'))) {
                /** @var Submission $article */
                $article = $cache->get('articles', $publication->getData('submissionId'));
            } else {
                $article = Repo::submission()->get($publication->getData('submissionId'));
                if ($article && $article->getData('status') === Submission::STATUS_PUBLISHED) $cache->add($article, null);
            }
            $articleNodeName = 'DOISerialArticleVersion';
            $workOrProduct = 'Product';
            $epubFormat = null;
            $submissionFileId = $galley->getData('submissionFileId');
            if ($submissionFileId && $submissionFile = Repo::submissionFile()->get($submissionFileId)) {
                if ($submissionFile->getData('mimetype') == 'application/pdf') {
                    $epubFormat = self::O4DOI_EPUB_FORMAT_PDF;
                } else if ($submissionFile->getData('mimetype') == 'text/html') {
                    $epubFormat = self::O4DOI_EPUB_FORMAT_HTML;
                }
            } else if ($galley->getData('urlRemote')) {
                $epubFormat = self::O4DOI_EPUB_FORMAT_HTML;
            }
        }

        $articleNode = $doc->createElementNS($deployment->getNamespace(), $articleNodeName);
        // Notification type (mandatory)
        assert(empty($registeredDoi) || $registeredDoi == $doi);
        $notificationType = (empty($registeredDoi) ? self::O4DOI_NOTIFICATION_TYPE_NEW : self::O4DOI_NOTIFICATION_TYPE_UPDATE);
        $articleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'NotificationType', $notificationType));
        // DOI (mandatory)
        $articleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'DOI', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));
        // DOI URL (mandatory)
        $urlPath = $article->getBestId();
        if ($galley) $urlPath = [$article->getBestId(), $galley->getBestGalleyId()];
        $dispatcher = $this->_getDispatcher($request);
        $url = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'article', 'view', $urlPath, null, null, true);
        if ($plugin->isTestMode($context)) {
            // Change server domain for testing.
            $url = PKPString::regexp_replace('#://[^\s]+/index.php#', '://example.com/index.php', $url);
        }
        $articleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'DOIWebsiteLink', $url));

        // Add Collection on the content
        // Collection property="crawler-based"
        $galleysForCollection = $article->getCurrentPublication()->getData('galleys');
        // All full-texts, PDF full-texts and remote galleys for text-mining and as-crawled URL
        $submissionGalleys = $pdfGalleys = $remoteGalleys = [];
        // preferred PDF full-text for the as-crawled URL
        $pdfGalleyInArticleLocale = null;
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        foreach ($galleysForCollection as $galleyForCollection) {
            /** @var Galley $galleyForCollection */
            $submissionFileId = $galleyForCollection->getData('submissionFileId');
            if ($submissionFileId && $galleyForCollectionFile = Repo::submissionFile()->get($submissionFileId)) {
                $genre = $genreDao->getById($galleyForCollectionFile->getData('genreId'));
                if (!$genre->getSupplementary()) {
                    $submissionGalleys[] = $galleyForCollection;
                    if ($galleyForCollectionFile->getData('mimetype') == 'application/pdf') {
                        $pdfGalleys[] = $galleyForCollection;
                        if (!$pdfGalleyInArticleLocale && $galleyForCollection->getLocale() == $article->getCurrentPublication()->getData('locale')) {
                            $pdfGalleyInArticleLocale = $galleyForCollection;
                        }
                    }
                }
            } else if ($galley->getData('urlRemote')) {
                $remoteGalleys[] = $galleyForCollection;
            }
        }

        // as-crawled URLs
        $asCrawledGalleys = [];
        if ($pdfGalleyInArticleLocale) {
            $asCrawledGalleys = [$pdfGalleyInArticleLocale];
        } elseif (!empty($pdfGalleys)) {
            $asCrawledGalleys = [$pdfGalleys[0]];
        } else {
            $asCrawledGalleys = $submissionGalleys;
        }
        // as-crawled URL collection node
        if (!empty($asCrawledGalleys)) {
            $this->appendAsCrawledCollectionNodes($doc, $articleNode, $article, $asCrawledGalleys);
        }
        // text-mining collection node
        $submissionGalleys = array_merge($submissionGalleys, $remoteGalleys);
        if (!empty($submissionGalleys)) {
            $this->appendTextMiningCollectionNodes($doc, $articleNode, $article, $submissionGalleys);
        }

        // DOI strucural type
        $articleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'DOIStructuralType', $this->getDOIStructuralType()));
        // Registrant (mandatory)
        $articleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'RegistrantName', htmlspecialchars($plugin->getSetting($context->getId(), 'registrantName'), ENT_COMPAT, 'UTF-8')));
        // Registration authority (mandatory)
        $articleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'RegistrationAuthority', 'mEDRA'));
        // WorkIdentifier - proprietary ID
        $pubObjectProprietaryId = $context->getId() . '-' . $article->getCurrentPublication()->getData('issueId') . '-' . $article->getId();
        if ($galley) $pubObjectProprietaryId .= '-g' . $galley->getId();
        $articleNode->appendChild($this->createIdentifierNode($doc, $workOrProduct, self::O4DOI_ID_TYPE_PROPRIETARY, $pubObjectProprietaryId));
        // Issue/journal locale precedence.
        $journalLocalePrecedence = $this->getObjectLocalePrecedence($context, null, null);
        // Serial Publication (mandatory)
        $articleNode->appendChild($this->createSerialPublicationNode($doc, $journalLocalePrecedence, $epubFormat));
        // Journal Issue (mandatory)
        $issueId = $article->getCurrentPublication()->getData('issueId');
        if ($cache->isCached('issues', $issueId)) {
            /** @var Issue $issue */
            $issue = $cache->get('issues', $issueId);
        } else {
            $issue = Repo::issue()->get($issueId, $context->getId());
            if ($issue) $cache->add($issue, null);
        }
        $articleNode->appendChild($this->createJournalIssueNode($doc, $issue, $journalLocalePrecedence));

        // Object locale precedence.
        $objectLocalePrecedence = $this->getObjectLocalePrecedence($context, $article, $galley);
        // Content Item (mandatory for articles)
        $articleNode->appendChild($this->createContentItemNode($doc, $issue, $article, $galley, $objectLocalePrecedence));
        return $articleNode;
    }

    /**
     * Create a content item node.
     */
    function createContentItemNode(DOMDocument $doc, Issue $issue, Submission $article, ?Galley $galley, array $objectLocalePrecedence): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        $contentItemNode = $doc->createElementNS($deployment->getNamespace(), 'ContentItem');
        // Sequence number
        $seq = $article->getCurrentPublication()->getData('seq');
        if ($seq) {
            $contentItemNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'SequenceNumber', $seq));
        }
        // Describe page runs
        $pages = $article->getCurrentPublication()->getPageArray();
        if ($pages) {
            $textItemNode = $doc->createElementNS($deployment->getNamespace(), 'TextItem');
            foreach ($pages as $range) {
                $pageRunNode = $doc->createElementNS($deployment->getNamespace(), 'PageRun');
                $node = $doc->createElementNS($deployment->getNamespace(), 'FirstPageNumber', htmlspecialchars($range[0]));
                $pageRunNode->appendChild($node);
                if (isset($range[1])) {
                    $node = $doc->createElementNS($deployment->getNamespace(), 'LastPageNumber', htmlspecialchars($range[1]));
                    $pageRunNode->appendChild($node);
                }
                $textItemNode->appendChild($pageRunNode);
            }
            $contentItemNode->appendChild($textItemNode);
        }
        // Extent (for article-as-manifestation only)
        if ($galley && !$galley->getData('urlRemote')) {
            $submissionFileId = $galley->getData('submissionFileId');
            if ($submissionFileId && $galleyFile = Repo::submissionFile()->get($submissionFileId)) {
                $path = $galleyFile->getData('path');
                $size = Services::get('file')->fs->fileSize($path);
                $contentItemNode->appendChild($this->createExtentNode($doc, $size));
            }
        }
        // Article Title (mandatory)
        $titles = $this->getTranslationsByPrecedence($article->getCurrentPublication()->getTitles(), $objectLocalePrecedence);
        assert(!empty($titles));
        foreach ($titles as $locale => $title) {
            $localizedSubtitle = $article->getCurrentPublication()->getData('subtitle', $locale);
            $contentItemNode->appendChild($this->createTitleNode($doc, $locale, $title, self::O4DOI_TITLE_TYPE_FULL, $localizedSubtitle));
        }
        // Contributors
        $authors = $article->getCurrentPublication()->getData('authors');
        foreach ($authors as $author) {
            $contentItemNode->appendChild($this->createContributorNode($doc, $author, $objectLocalePrecedence));
        }
        // Language
        $languageCode = LocaleConversion::get3LetterIsoFromLocale($objectLocalePrecedence[0]);
        assert(!empty($languageCode));
        $languageNode = $doc->createElementNS($deployment->getNamespace(), 'Language');
        $languageNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'LanguageRole', self::O4DOI_LANGUAGE_ROLE_LANGUAGE_OF_TEXT));
        $languageNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'LanguageCode', $languageCode));
        $contentItemNode->appendChild($languageNode);
        // Article keywords
        // SubjectClass will be left out here, because we don't know the scheme/classification name
        $allKeywords = $article->getCurrentPublication()->getData('keywords');
        $keywords = $this->getPrimaryTranslation($allKeywords, $objectLocalePrecedence);
        if (!empty($keywords)) {
            $keywordsString = implode(';', $keywords);
            $contentItemNode->appendChild($this->createSubjectNode($doc, self::O4DOI_SUBJECT_SCHEME_PUBLISHER, $keywordsString));
        }
        // Object Description 'OtherText'
        $descriptions = $this->getTranslationsByPrecedence($article->getCurrentPublication()->getData('abstract'), $objectLocalePrecedence);
        foreach ($descriptions as $locale => $description) {
            $contentItemNode->appendChild($this->createOtherTextNode($doc, $locale, $description));
        }
        // Article Publication Date
        $datePublished = $article->getCurrentPublication()->getData('datePublished');
        if (!empty($datePublished)) {
            $contentItemNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'PublicationDate', date('Ymd', strtotime($datePublished))));
        }

        // Relations
        // Issue
        if ($plugin->getSetting($context->getId(), 'exportIssuesAs') == self::O4DOI_ISSUE_AS_WORK) {
            // related work:
            // - is part of issue-as-work
            $issueWorkOrProduct = 'Work';
        } else {
            // related product:
            // - is part of issue-as-manifestation
            $issueWorkOrProduct = 'Product';
        }
        $issueProprietaryId = $context->getId() . '-' . $issue->getId();
        $relatedIssueIds = [self::O4DOI_ID_TYPE_PROPRIETARY => $issueProprietaryId];
        $issueDoi = $issue->getStoredPubId('doi');
        if (!empty($issueDoi)) $relatedIssueIds[self::O4DOI_ID_TYPE_DOI] = $issueDoi;
        $relatedIssueNode = $this->createRelatedNode($doc, $issueWorkOrProduct, self::O4DOI_RELATION_IS_PART_OF, $relatedIssueIds);
        // Galleys
        $galleysByArticle = $article->getCurrentPublication()->getData('galleys');
        if (!$galley) { // if exporting object is an article
            $contentItemNode->appendChild($relatedIssueNode);
            // related products:
            // - is manifested in articles-as-manifestation
            foreach($galleysByArticle as $relatedGalley) {
                $galleyProprietaryId = $context->getId() . '-' . $issue->getId() . '-' . $article->getId() . '-g' . $relatedGalley->getId();
                $relatedGalleyIds = [self::O4DOI_ID_TYPE_PROPRIETARY => $galleyProprietaryId];
                $galleyDoi = $relatedGalley->getStoredPubId('doi');
                if (!empty($galleyDoi)) $relatedGalleyIds[self::O4DOI_ID_TYPE_DOI] = $galleyDoi;
                $contentItemNode->appendChild($this->createRelatedNode($doc, 'Product', self::O4DOI_RELATION_IS_MANIFESTED_IN, $relatedGalleyIds));
                unset($relatedGalley, $relatedGalleyIds, $galleyProprietaryId, $galleyDoi);
            }
        } else {
            // Include issue-as-work before article-as-work.
            if ($issueWorkOrProduct == 'Work') $contentItemNode->appendChild($relatedIssueNode);

            // related work:
            // - is a manifestation of article-as-work
            $articleProprietaryId = $context->getId() . '-' . $article->getCurrentPublication()->getData('issueId') . '-' . $article->getId();
            $relatedArticleIds = [self::O4DOI_ID_TYPE_PROPRIETARY => $articleProprietaryId];
            $doi = $article->getCurrentPublication()->getDoi();
            if (!empty($doi)) $relatedArticleIds[self::O4DOI_ID_TYPE_DOI] = $doi;
            $contentItemNode->appendChild($this->createRelatedNode($doc, 'Work', self::O4DOI_RELATION_IS_A_MANIFESTATION_OF, $relatedArticleIds));
            unset($relatedArticleIds);

            // Include issue-as-manifestation after article-as-work.
            if ($issueWorkOrProduct == 'Product')$contentItemNode->appendChild($relatedIssueNode);

            // related products:
            foreach($galleysByArticle as $relatedGalley) {
                $galleyProprietaryId = $context->getId() . '-' . $issue->getId() . '-' . $article->getId() . '-g' . $relatedGalley->getId();
                $relatedGalleyIds = [self::O4DOI_ID_TYPE_PROPRIETARY => $galleyProprietaryId];
                $galleyDoi = $relatedGalley->getDoi();
                if (!empty($galleyDoi)) $relatedGalleyIds[self::O4DOI_ID_TYPE_DOI] = $galleyDoi;

                // - is a different form of all other articles-as-manifestation
                //   with the same article id and language but different form
                if ($galley->getLocale() == $relatedGalley->getLocale() &&
                        $galley->getLabel() != $relatedGalley->getLabel()) {

                            $contentItemNode->appendChild($this->createRelatedNode($doc, 'Product', self::O4DOI_RELATION_IS_A_DIFFERENT_FORM_OF, $relatedGalleyIds));
                }

                // - is a different language version of all other articles-as-manifestation
                //   with the same article id and form/label but different language
                if ($galley->getLabel() == $relatedGalley->getLabel() &&
                        $galley->getLocale() != $relatedGalley->getLocale()) {

                            $contentItemNode->appendChild($this->createRelatedNode($doc, 'Product', self::O4DOI_RELATION_IS_A_LANGUAGE_VERSION_OF, $relatedGalleyIds));
                }
                unset($relatedGalley, $relatedGalleyIds, $galleyProprietaryId, $galleyDoi);
            }

        }
        // Add citation list (unstructured)
        $citationDao = DAORegistry::getDAO('CitationDAO'); /** @var CitationDAO $citationDao */
        $parsedCitations = $citationDao->getByPublicationId($article->getCurrentPublication()->getId())->toArray();
        if(!empty($parsedCitations)){
            $this->appendCitationListNodes($doc, $contentItemNode, $article, $parsedCitations);
        }

        return $contentItemNode;
    }

    /**
     * Create a contributor node.
     */
    function createContributorNode(DOMDocument $doc, Author $author, array $objectLocalePrecedence): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $contributorNode = $doc->createElementNS($deployment->getNamespace(), 'Contributor');
        // Sequence number
        $seq = $author->getSequence() ?? 0;
        $seq++; // Sequences must begin with 1, so bump our internal sequence by 1.
        $contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'SequenceNumber', $seq));
        // Contributor role (mandatory)
        $contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ContributorRole', self::O4DOI_CONTRIBUTOR_ROLE_ACTUAL_AUTHOR));
        // Contributor ORCID
        if (!empty($author->getOrcid())) {
            $contributorNode->appendChild($this->createNameIdentifierNode($doc, self::O4DOI_NAME_IDENTIFIER_TYPE_ORCID, $author->getOrcid()));
        }
        // Person name (mandatory)
        $personName = $author->getFullName(false, false, $objectLocalePrecedence[0]);
        assert(!empty($personName));
        $contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'PersonName', htmlspecialchars($personName, ENT_COMPAT, 'UTF-8')));
        // Inverted person name
        $invertedPersonName = $author->getFullName(false, true, $objectLocalePrecedence[0]);
        assert(!empty($invertedPersonName));
        $contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'PersonNameInverted', htmlspecialchars($invertedPersonName, ENT_COMPAT, 'UTF-8')));
        // Names before key
        $locale = $author->getSubmissionLocale();
        $nameBeforeKey = $author->getLocalizedData(Author::IDENTITY_SETTING_GIVENNAME, $locale);
        assert(!empty($nameBeforeKey));
        $contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'NamesBeforeKey', htmlspecialchars($nameBeforeKey, ENT_COMPAT, 'UTF-8')));
        // Key names
        if (($familyName = $author->getLocalizedData(Author::IDENTITY_SETTING_FAMILYNAME, $locale)) != '') {
            $contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'KeyNames', htmlspecialchars($familyName, ENT_COMPAT, 'UTF-8')));
        } else {
            $contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'KeyNames', htmlspecialchars($personName, ENT_COMPAT, 'UTF-8')));
        }
        // Affiliation
        $affiliation = $this->getPrimaryTranslation($author->getAffiliation(null), $objectLocalePrecedence);
        if (!empty($affiliation)) {
            $affiliationNode = $doc->createElementNS($deployment->getNamespace(), 'ProfessionalAffiliation');
            $affiliationNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'Affiliation', htmlspecialchars($affiliation, ENT_COMPAT, 'UTF-8')));
            $contributorNode->appendChild($affiliationNode);
        }
        // Biographical note
        $bioNote = $this->getPrimaryTranslation($author->getBiography(null), $objectLocalePrecedence);
        if (!empty($bioNote)) {
            $contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'BiographicalNote', htmlspecialchars(PKPString::html2text($bioNote), ENT_COMPAT, 'UTF-8')));
        }
        return $contributorNode;
    }

    /**
     * Create a subject node.
     * @param string $subjectSchemeId O4DOI_SUBJECT_SCHEME_*
     */
    function createSubjectNode(DOMDocument $doc, string $subjectSchemeId, string $subjectHeadingOrCode, string $subjectSchemeName = null): DOMElement
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $subjectNode = $doc->createElementNS($deployment->getNamespace(), 'Subject');
        // Subject Scheme Identifier
        $subjectNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'SubjectSchemeIdentifier', $subjectSchemeId));
        if (is_null($subjectSchemeName)) {
            // Subject Heading
            $subjectNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'SubjectHeadingText', htmlspecialchars($subjectHeadingOrCode, ENT_COMPAT, 'UTF-8')));
        } else {
            // Subject Scheme Name
            $subjectNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'SubjectSchemeName', htmlspecialchars($subjectSchemeName, ENT_COMPAT, 'UTF-8')));
            // Subject Code
            $subjectNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'SubjectCode', htmlspecialchars($subjectHeadingOrCode, ENT_COMPAT, 'UTF-8')));
        }
        return $subjectNode;
    }

    /**
     * Append the collection node 'Collection property="crawler-based"' to the Article data node.
     */
    function appendAsCrawledCollectionNodes(DOMDocument $doc, DOMElement $articleNode, Submission $article, array $galleys): void
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();

        $crawlerBasedCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'Collection');
        $crawlerBasedCollectionNode->setAttribute('property', 'crawler-based');
        foreach ($galleys as $crawledGalley) {
            $urlPath = [$article->getBestArticleId(), $crawledGalley->getBestGalleyId()];
            $dispatcher = $this->_getDispatcher($request);
            $resourceURL = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'article', 'download', $urlPath, null, null, true);
            //$resourceURL = $request->url($context->getPath(), 'article', 'download', $urlPath, null, null, true);
            $iParadigmsItemNode = $doc->createElementNS($deployment->getNamespace(), 'Item');
            $iParadigmsItemNode->setAttribute('crawler', 'iParadigms');
            $iParadigmsItemNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'Resource', htmlspecialchars($resourceURL)));
            $crawlerBasedCollectionNode->appendChild($iParadigmsItemNode);
        }
        $articleNode->appendChild($crawlerBasedCollectionNode);
    }

    /**
     * Append the collection node 'Collection property="text-mining"' to the Article data node.
     */
    function appendTextMiningCollectionNodes(DOMDocument $doc, DOMElement $articleNode, Submission $article, array $galleys): void
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();

        $textMiningCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'Collection');
        $textMiningCollectionNode->setAttribute('property', 'text-mining');
        foreach ($galleys as $galley) {
            $urlPath = [$article->getBestArticleId(), $galley->getBestGalleyId()];
            $dispatcher = $this->_getDispatcher($request);
            $resourceURL = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'article', 'download', $urlPath, null, null, true);
            //$resourceURL = $request->url($context->getPath(), 'article', 'download', $urlPath, null, null, true);
            $textMiningItemNode = $doc->createElementNS($deployment->getNamespace(), 'Item');
            $resourceNode = $doc->createElementNS($deployment->getNamespace(), 'Resource', htmlspecialchars($resourceURL));
            if (!$galley->getRemoteURL()) $resourceNode->setAttribute('mime_type', $galley->getFileType());
            $textMiningItemNode->appendChild($resourceNode);
            $textMiningCollectionNode->appendChild($textMiningItemNode);
        }
        $articleNode->appendChild($textMiningCollectionNode);
    }

    /**
     * Append the CitationList node with unstructured citations to the ContentItem data node.
     */
    function appendCitationListNodes(DOMDocument $doc, DOMElement $contentItemNode, Submission $article, array $parsedCitations): void
    {
        /** @var PKPNativeImportExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $medraCitationNamespace = 'http://www.medra.org/DOIMetadata/2.0/Citations';
        $citationListNode = $doc->createElementNS($deployment->getNamespace(), 'cl:CitationList');
        $citationListNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cl', $medraCitationNamespace);
        foreach($parsedCitations as $citation) {
            $articleCitationNode = $doc->createElementNS($medraCitationNamespace, 'ArticleCitation');
            $articleCitationNode->setAttribute('key', $article->getCurrentPublication()->getData('pub-id::doi') . '_ref' . $citation->getData('seq'));
            $unstructuredCitationNode = $doc->createElementNS($medraCitationNamespace, 'UnstructuredCitation');
            $unstructuredCitationNode->appendChild($doc->createTextNode($citation->getData('rawCitation')));
            $articleCitationNode->appendChild($unstructuredCitationNode);
            $citationListNode->appendChild($articleCitationNode);
        }
        $contentItemNode->appendChild($citationListNode);
    }
}
