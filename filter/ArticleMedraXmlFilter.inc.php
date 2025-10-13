<?php

/**
 * @file plugins/importexport/medra/filter/ArticleMedraXmlFilter.inc.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleMedraXmlFilter
 * @ingroup plugins_importexport_medra
 *
 * @brief Class that converts an Article as work to a O4DOI XML document.
 */

import('plugins.importexport.medra.filter.O4DOIXmlFilter');


class ArticleMedraXmlFilter extends O4DOIXmlFilter {
	/**
	 * Constructor
	 * @param $filterGroup FilterGroup
	 */
	function __construct($filterGroup) {
		$this->setDisplayName('mEDRA XML article export');
		parent::__construct($filterGroup);
	}

	/**
	 * @copydoc O4DOIXmlFilter::isWork()
	 */
	function isWork($context, $plugin) {
		return true;
	}

	/**
	 *  @copydoc O4DOIXmlFilter::getRootNodeName
	 */
	function getRootNodeName() {
		return 'ONIXDOISerialArticleWorkRegistrationMessage';
	}

	//
	// Implement template methods from PersistableFilter
	//
	/**
	 * @copydoc PersistableFilter::getClassName()
	 */
	function getClassName() {
		return 'plugins.importexport.medra.filter.ArticleMedraXmlFilter';
	}

	//
	// Implement template methods from Filter
	//
	/**
	 * @see Filter::process()
	 * @param $pubObjects array Array of Submissions or ArticleGalleys
	 * @return DOMDocument
	 */
	function &process(&$pubObjects) {
		// Create the XML document
		$doc = new DOMDocument('1.0', 'utf-8');
		$doc->preserveWhiteSpace = false;
		$doc->formatOutput = true;
		$deployment = $this->getDeployment();
		$context = $deployment->getContext();

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
	 * @param $doc DOMDocument
	 * @param $pubObject Submission|ArticleGalley
	 * @return DOMElement
	 */
	function createArticleNode($doc, $pubObject) {
		$deployment = $this->getDeployment();
		$context = $deployment->getContext();
		$cache = $deployment->getCache();
		$plugin = $deployment->getPlugin();
		$request = Application::get()->getRequest();
		$router = $request->getRouter();

		assert ((is_a($pubObject, 'Submission') && $this->isWork($context, $plugin)) ||
				(is_a($pubObject, 'ArticleGalley') && !$this->isWork($context, $plugin)));

		if (is_a($pubObject, 'Submission')) {
			$galley = null;
			$article = $pubObject;
			if (!$cache->isCached('articles', $article->getId())) {
				$cache->add($article, null);
			}
			$articleNodeName = 'DOISerialArticleWork';
			$workOrProduct = 'Work';
			$epubFormat = O4DOI_EPUB_FORMAT_HTML;
		} else {
			$galley = $pubObject;
			$publication = Services::get('publication')->get($galley->getData('publicationId'));
			if ($cache->isCached('articles', $publication->getData('submissionId'))) {
				$article = $cache->get('articles', $publication->getData('submissionId'));
			} else {
				$article = Services::get('submission')->get($publication->getData('submissionId'));
				if ($article && $article->getData('status') === STATUS_PUBLISHED) $cache->add($article, null);
			}
			$articleNodeName = 'DOISerialArticleVersion';
			$workOrProduct = 'Product';
			$epubFormat = null;
			if ($galley->isPdfGalley()) {
				$epubFormat = O4DOI_EPUB_FORMAT_PDF;
			} else if ($galley->getRemoteURL() || $galley->getFileType() == 'text/html') {
				$epubFormat = O4DOI_EPUB_FORMAT_HTML;
			}
		}

		$articleNode = $doc->createElementNS($deployment->getNamespace(), $articleNodeName);
		// Notification type (mandatory)
		$doi = $pubObject->getStoredPubId('doi');
		$registeredDoi = $pubObject->getData('medra::registeredDoi');
		assert(empty($registeredDoi) || $registeredDoi == $doi);
		$notificationType = (empty($registeredDoi) ? O4DOI_NOTIFICATION_TYPE_NEW : O4DOI_NOTIFICATION_TYPE_UPDATE);
		$articleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'NotificationType', $notificationType));
		// DOI (mandatory)
		$articleNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'DOI', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));
		// DOI URL (mandatory)
		$urlPath = $article->getBestId();
		if ($galley) $urlPath = [$article->getBestId(), $galley->getBestGalleyId()];
		$url = $router->url($request, $context->getPath(), 'article', 'view', $urlPath, null, null, true);
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
		$genreDao = DAORegistry::getDAO('GenreDAO'); /* @var $genreDao GenreDAO */
		foreach ($galleysForCollection as $galleyForCollection) {
			if (!$galleyForCollection->getRemoteURL()) {
				$galleyForCollectionFile = $galleyForCollection->getFile();
				if ($galleyForCollectionFile) {
					$genre = $genreDao->getById($galleyForCollectionFile->getGenreId());
					if (!$genre->getSupplementary()) {
						$submissionGalleys[] = $galleyForCollection;
						if ($galleyForCollection->isPdfGalley()) {
							$pdfGalleys[] = $galleyForCollection;
							if (!$pdfGalleyInArticleLocale && $galleyForCollection->getLocale() == $article->getCurrentPublication()->getData('locale')) {
								$pdfGalleyInArticleLocale = $galleyForCollection;
							}
						}
					}
				}
			} else {
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
		$issueId = $article->getCurrentPublication()->getData('issueId');
		if ($cache->isCached('issues', $issueId)) {
		    $issue = $cache->get('issues', $issueId);
		} else {
		    $issue = Repo::issue()->get($issueId, $context->getId());
		    if ($issue) $cache->add($issue, null);
		}
		$journalId = $issue->getData('journalId');
		$accessRights = null;
		if($context->getData('publishingMode') == PUBLISHING_MODE_OPEN){
			$accessRights = 'openAccess';
		} else if($context->getData('publishingMode') == PUBLISHING_MODE_SUBSCRIPTION) {
			if ($issue->getAccessStatus() == ISSUE_ACCESS_OPEN) {
				$accessRights = 'openAccess';
			} else if ($article->getCurrentPublication()->getData('accessStatus') == ARTICLE_ACCESS_OPEN) {
						$accessRights = 'openAccess';
					}
		}
		$rightsURL = $article->getCurrentPublication()->getData('licenseUrl') ?? $context->getData('licenseUrl');
		if($accessRights == 'openAccess' || !empty($rightsURL)){
			$accessIndicatorsNode = $doc->createElementNS($deployment->getNamespace(), 'AccessIndicators');
			if($accessRights == 'openAccess'){
				$accessIndicatorsNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'FreeToRead'));
			}
			if(!empty($rightsURL)){
				$accessIndicatorsNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'License', $rightsURL));
			}
			$articleNode->appendChild($accessIndicatorsNode);
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
		$articleNode->appendChild($this->createIdentifierNode($doc, $workOrProduct, O4DOI_ID_TYPE_PROPRIETARY, $pubObjectProprietaryId));
		// Issue/journal locale precedence.
		$journalLocalePrecedence = $this->getObjectLocalePrecedence($context, null, null);
		// Serial Publication (mandatory)
		$articleNode->appendChild($this->createSerialPublicationNode($doc, $journalLocalePrecedence, $epubFormat));
		// Journal Issue (mandatory)
		$articleNode->appendChild($this->createJournalIssueNode($doc, $issue, $journalLocalePrecedence));

		// Object locale precedence.
		$objectLocalePrecedence = $this->getObjectLocalePrecedence($context, $article, $galley);
		// Content Item (mandatory for articles)
		$articleNode->appendChild($this->createContentItemNode($doc, $issue, $article, $galley, $doi, $objectLocalePrecedence));
		return $articleNode;
	}

	/**
	 * Create a content item node.
	 * @param $doc DOMDocument
	 * @param $issue Issue
	 * @param $article Submission
	 * @param $galley ArticleGalley
	 * @param $pubObjectDoi string
	 * @param $objectLocalePrecedence array
	 * @return DOMElement
	 */
	function createContentItemNode($doc, $issue, $article, $galley, $pubObjectDoi, $objectLocalePrecedence) {
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
		if ($galley && !$galley->getRemoteURL()) {
			$galleyFile = $galley->getFile();
			if ($galleyFile) {
				$path = $galleyFile->getData('path');
				$fileSize = Services::get('file')->fs->getSize($path);
				$contentItemNode->appendChild($this->createExtentNode($doc, $fileSize));
			}
		}
		// Article Title (mandatory)
		$titles = $this->getTranslationsByPrecedence($article->getCurrentPublication()->getTitles(), $objectLocalePrecedence);
		assert(!empty($titles));
		foreach ($titles as $locale => $title) {
			$localizedSubtitle = $article->getCurrentPublication()->getData('subtitle', $locale);
			$contentItemNode->appendChild($this->createTitleNode($doc, $locale, $title, O4DOI_TITLE_TYPE_FULL, $localizedSubtitle));
		}
		// Contributors
		$authors = $article->getCurrentPublication()->getData('authors');
		foreach ($authors as $author) {
			$contentItemNode->appendChild($this->createContributorNode($doc, $author, $objectLocalePrecedence));
		}
		// Language
		$languageCode = AppLocale::get3LetterIsoFromLocale($objectLocalePrecedence[0]);
		assert(!empty($languageCode));
		$languageNode = $doc->createElementNS($deployment->getNamespace(), 'Language');
		$languageNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'LanguageRole', O4DOI_LANGUAGE_ROLE_LANGUAGE_OF_TEXT));
		$languageNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'LanguageCode', $languageCode));
		$contentItemNode->appendChild($languageNode);
		// Article keywords
		// SubjectClass will be left out here, because we don't know the scheme/classification name
		$submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO'); /* @var $submissionKeywordDao SubmissionKeywordDAO */
		$allKeywords = $submissionKeywordDao->getKeywords($article->getCurrentPublication()->getId(), $context->getSupportedSubmissionLocales());
		$keywords = $this->getPrimaryTranslation($allKeywords, $objectLocalePrecedence);
		if (!empty($keywords)) {
			$keywordsString = implode(';', $keywords);
			$contentItemNode->appendChild($this->createSubjectNode($doc, O4DOI_SUBJECT_SCHEME_PUBLISHER, $keywordsString));
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
		if ($plugin->getSetting($context->getId(), 'exportIssuesAs') == O4DOI_ISSUE_AS_WORK) {
			// related work:
			// - is part of issue-as-work
			$issueWorkOrProduct = 'Work';
		} else {
			// related product:
			// - is part of issue-as-manifestation
			$issueWorkOrProduct = 'Product';
		}
		$issueProprietaryId = $context->getId() . '-' . $issue->getId();
		$relatedIssueIds = [O4DOI_ID_TYPE_PROPRIETARY => $issueProprietaryId];
		$issueDoi = $issue->getStoredPubId('doi');
		if (!empty($issueDoi)) $relatedIssueIds[O4DOI_ID_TYPE_DOI] = $issueDoi;
		$relatedIssueNode = $this->createRelatedNode($doc, $issueWorkOrProduct, O4DOI_RELATION_IS_PART_OF, $relatedIssueIds);
		// Galleys
		$galleysByArticle = $article->getCurrentPublication()->getData('galleys');
		if (!$galley) { // if exporting object is an article
			$contentItemNode->appendChild($relatedIssueNode);
			// related products:
			// - is manifested in articles-as-manifestation
			foreach($galleysByArticle as $relatedGalley) {
				$galleyProprietaryId = $context->getId() . '-' . $issue->getId() . '-' . $article->getId() . '-g' . $relatedGalley->getId();
				$relatedGalleyIds = [O4DOI_ID_TYPE_PROPRIETARY => $galleyProprietaryId];
				$galleyDoi = $relatedGalley->getStoredPubId('doi');
				if (!empty($galleyDoi)) $relatedGalleyIds[O4DOI_ID_TYPE_DOI] = $galleyDoi;
				$contentItemNode->appendChild($this->createRelatedNode($doc, 'Product', O4DOI_RELATION_IS_MANIFESTED_IN, $relatedGalleyIds));
				unset($relatedGalley, $relatedGalleyIds, $galleyProprietaryId, $galleyDoi);
			}
		} else {
			// Include issue-as-work before article-as-work.
			if ($issueWorkOrProduct == 'Work') $contentItemNode->appendChild($relatedIssueNode);

			// related work:
			// - is a manifestation of article-as-work
			$articleProprietaryId = $context->getId() . '-' . $article->getCurrentPublication()->getData('issueId') . '-' . $article->getId();
			$relatedArticleIds = [O4DOI_ID_TYPE_PROPRIETARY => $articleProprietaryId];
			$doi = $article->getCurrentPublication()->getStoredPubId('doi');
			if (!empty($doi)) $relatedArticleIds[O4DOI_ID_TYPE_DOI] = $doi;
			$contentItemNode->appendChild($this->createRelatedNode($doc, 'Work', O4DOI_RELATION_IS_A_MANIFESTATION_OF, $relatedArticleIds));
			unset($relatedArticleIds);

			// Include issue-as-manifestation after article-as-work.
			if ($issueWorkOrProduct == 'Product')$contentItemNode->appendChild($relatedIssueNode);

			// related products:
			foreach($galleysByArticle as $relatedGalley) {
				$galleyProprietaryId = $context->getId() . '-' . $issue->getId() . '-' . $article->getId() . '-g' . $relatedGalley->getId();
				$relatedGalleyIds = [O4DOI_ID_TYPE_PROPRIETARY => $galleyProprietaryId];
				$galleyDoi = $relatedGalley->getStoredPubId('doi');
				if (!empty($galleyDoi)) $relatedGalleyIds[O4DOI_ID_TYPE_DOI] = $galleyDoi;

				// - is a different form of all other articles-as-manifestation
				//   with the same article id and language but different form
				if ($galley->getLocale() == $relatedGalley->getLocale() &&
						$galley->getLabel() != $relatedGalley->getLabel()) {

							$contentItemNode->appendChild($this->createRelatedNode($doc, 'Product', O4DOI_RELATION_IS_A_DIFFERENT_FORM_OF, $relatedGalleyIds));
				}

				// - is a different language version of all other articles-as-manifestation
				//   with the same article id and form/label but different language
				if ($galley->getLabel() == $relatedGalley->getLabel() &&
						$galley->getLocale() != $relatedGalley->getLocale()) {

							$contentItemNode->appendChild($this->createRelatedNode($doc, 'Product', O4DOI_RELATION_IS_A_LANGUAGE_VERSION_OF, $relatedGalleyIds));
				}
				unset($relatedGalley, $relatedGalleyIds, $galleyProprietaryId, $galleyDoi);
			}

		}
		// Add citation list (unstructured)
		$citationDao = DAORegistry::getDAO('CitationDAO'); /* @var $citationDao CitationDAO */
		$parsedCitations = $citationDao->getByPublicationId($article->getCurrentPublication()->getId())->toArray();
		if(!empty($parsedCitations)){
			$this->appendCitationListNodes($doc, $contentItemNode, $pubObjectDoi, $parsedCitations);
		}

		return $contentItemNode;
	}

	/**
	 * Create a contributor node.
	 * @param $doc DOMDocument
	 * @param $author Author
	 * @param $objectLocalePrecedence array
	 * @return DOMElement
	 */
	function createContributorNode($doc, $author, $objectLocalePrecedence) {
		$deployment = $this->getDeployment();
		$contributorNode = $doc->createElementNS($deployment->getNamespace(), 'Contributor');
		// Sequence number
		$seq = $author->getSequence() ?? 0;
		$seq++; // Sequences must begin with 1, so bump our internal sequence by 1.
		$contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'SequenceNumber', $seq));
		// Contributor role (mandatory)
		$contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ContributorRole', O4DOI_CONTRIBUTOR_ROLE_ACTUAL_AUTHOR));
		// Contributor ORCID
		if (!empty($author->getOrcid())) {
			$contributorNode->appendChild($this->createNameIdentifierNode($doc, O4DOI_NAME_IDENTIFIER_TYPE_ORCID, $author->getOrcid()));
		}
		// Person name (mandatory)
		$personName = $author->getFullName(false);
		assert(!empty($personName));
		$contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'PersonName', htmlspecialchars($personName, ENT_COMPAT, 'UTF-8')));
		// Inverted person name
		$invertedPersonName = $author->getFullName(false, true);
		assert(!empty($invertedPersonName));
		$contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'PersonNameInverted', htmlspecialchars($invertedPersonName, ENT_COMPAT, 'UTF-8')));
		// Names before key
		$locale = $author->getSubmissionLocale();
		$nameBeforeKey = $author->getLocalizedData(IDENTITY_SETTING_GIVENNAME, $locale);
		assert(!empty($nameBeforeKey));
		$contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'NamesBeforeKey', htmlspecialchars($nameBeforeKey, ENT_COMPAT, 'UTF-8')));
		// Key names
		if ($author->getLocalizedFamilyName() != '') {
			$contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'KeyNames', htmlspecialchars($author->getLocalizedFamilyName(), ENT_COMPAT, 'UTF-8')));
		} else {
			$contributorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'KeyNames', htmlspecialchars($personName, ENT_COMPAT, 'UTF-8')));
		}
		// Affiliation
        $affiliation = $this->getPrimaryTranslation($author->getAffiliation(null), $objectLocalePrecedence);
        // Institution ROR
        $institution = $author->getData('rorId');
        if (!empty($affiliation) || !empty($institution)) {
            $affiliationNode = $doc->createElementNS($deployment->getNamespace(), 'ProfessionalAffiliation');
            if (!empty($affiliation)){
                $affiliationNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'Affiliation', htmlspecialchars($affiliation, ENT_COMPAT, 'UTF-8')));
            }   
            if (!empty($institution)) {
                $institutionNode = $doc->createElementNS($deployment->getNamespace(), 'InstitutionIdentifier', htmlspecialchars($institution, ENT_COMPAT, 'UTF-8'));
                $institutionNode->setAttribute('type', 'ror');
                $affiliationNode->appendChild($institutionNode);
            }
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
	 * @param $doc DOMDocument
	 * @param $subjectSchemeId string One of the O4DOI_SUBJECT_SCHEME_* constants.
	 * @param $subjectHeadingOrCode string The subject.
	 * @param $subjectSchemeName string|null A subject scheme name.
	 * @return DOMElement
	 */
	function createSubjectNode($doc, $subjectSchemeId, $subjectHeadingOrCode, $subjectSchemeName = null) {
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
	 * @param $doc DOMDocument
	 * @param $articleNode DOMElement
	 * @param $article Article
	 * @param $galleys array of galleys
	 */
	function appendAsCrawledCollectionNodes($doc, $articleNode, $article, $galleys) {
		$deployment = $this->getDeployment();
		$context = $deployment->getContext();
		$request = Application::get()->getRequest();

		$crawlerBasedCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'Collection');
		$crawlerBasedCollectionNode->setAttribute('property', 'crawler-based');
		foreach ($galleys as $crawledGalley) {
			$urlPath = [$article->getBestArticleId(), $crawledGalley->getBestGalleyId()];
			$resourceURL = $request->url($context->getPath(), 'article', 'download', $urlPath, null, null, true);
			$iParadigmsItemNode = $doc->createElementNS($deployment->getNamespace(), 'Item');
			$iParadigmsItemNode->setAttribute('crawler', 'iParadigms');
			$iParadigmsItemNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'Resource', htmlspecialchars($resourceURL)));
			$crawlerBasedCollectionNode->appendChild($iParadigmsItemNode);
		}
		$articleNode->appendChild($crawlerBasedCollectionNode);
	}

	/**
	 * Append the collection node 'Collection property="text-mining"' to the Article data node.
	 * @param $doc DOMDocument
	 * @param $articleNode DOMElement
	 * @param $article Article
	 * @param $galleys array of galleys
	 */
	function appendTextMiningCollectionNodes($doc, $articleNode, $article, $galleys) {
		$deployment = $this->getDeployment();
		$context = $deployment->getContext();
		$request = Application::get()->getRequest();

		$textMiningCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'Collection');
		$textMiningCollectionNode->setAttribute('property', 'text-mining');
		foreach ($galleys as $galley) {
			$urlPath = [$article->getBestArticleId(), $galley->getBestGalleyId()];
			$resourceURL = $request->url($context->getPath(), 'article', 'download', $urlPath, null, null, true);
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
	 * @param $doc DOMDocument
	 * @param $contentItemNode DOMElement
	 * @param $pubObjectDoi string
	 * @param $parsedCitations array of Citations
	 */
	function appendCitationListNodes($doc, $contentItemNode, $pubObjectDoi, $parsedCitations) {
		$deployment = $this->getDeployment();
		$medraCitationNamespace = 'http://www.medra.org/DOIMetadata/2.0/Citations';
		$citationListNode = $doc->createElementNS($deployment->getNamespace(), 'cl:CitationList');
		$citationListNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cl', $medraCitationNamespace);
		foreach($parsedCitations as $citation) {
			$articleCitationNode = $doc->createElementNS($medraCitationNamespace, 'ArticleCitation');
			$articleCitationNode->setAttribute('key', $pubObjectDoi . '_ref' . $citation->getData('seq'));
			$unstructuredCitationNode = $doc->createElementNS($medraCitationNamespace, 'UnstructuredCitation');
			$unstructuredCitationNode->appendChild($doc->createTextNode($citation->getData('rawCitation')));
			$articleCitationNode->appendChild($unstructuredCitationNode);
			$citationListNode->appendChild($articleCitationNode);
		}
		$contentItemNode->appendChild($citationListNode);
	}

}


