<?php

/**
 * @file plugins/importexport/medra/tests/classes/MedraWebserviceTest.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MedraWebserviceTest
 * @ingroup plugins_importexport_medra_tests_classes
 * @see MedraWebserviceTest
 *
 * @brief Test class for MedraWebservice.
 */

namespace APP\plugins\generic\medra\functional;

use DOMDocument;
use PKP\tests\PKPTestCase;
use APP\plugins\generic\medra\classes\MedraWebservice;
use PKP\config\Config;

class MedraWebserviceTest extends PKPTestCase
{
    private MedraWebservice $ws;

    protected function setUp(): void
    {
        // Retrieve and check configuration.
        $medraPassword = Config::getVar('debug', 'webtest_medra_pw');
        if (empty($medraPassword)) {
            $this->markTestSkipped(
                'Please set webtest_medra_pw in your config.php\'s ' .
                '[debug] section to the password of your Medra test account.'
            );
        }

        $this->ws = new MedraWebservice(MedraWebservice::MEDRA_WS_ENDPOINT_DEV, 'TEST_OJS', $medraPassword);
        parent::setUp();
    }

    /**
     * @covers MedraWebservice
     */
    public function testUpload()
    {
        self::assertTrue($this->ws->upload($this->getTestData()));
    }

    /**
     * @covers MedraWebservice
     */
    public function testUploadWithError()
    {
        $metadata = str_replace('SerialVersion', 'UnknownElement', $this->getTestData());
        $expectedError = "mEDRA: 500 - uploaded file is not valid: cvc-complex-type.2.4.a: " .
            "Invalid content was found starting with element 'UnknownElement'. " .
            "One of '{\"http://www.editeur.org/onix/DOIMetadata/2.0\":SerialVersion}' is expected.";
        self::assertEquals($expectedError, $this->ws->upload($metadata));
    }

    /**
     * @covers MedraWebservice
     */
    public function testViewMetadata()
    {
        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->loadXML($this->getTestData());
        $elem = $dom->getElementsByTagName('DOISerialIssueWork')->item(0);
        $attr = $dom->createAttribute('xmlns');
        $attr->value = 'http://www.editeur.org/onix/DOIMetadata/2.0';
        $elem->appendChild($attr);

        $result = str_replace(
            '<NotificationType>07</NotificationType>',
            '<NotificationType>06</NotificationType>',
            $this->ws->viewMetadata('10.5236/jpkjpk.v1i1')
        );

        self::assertXmlStringEqualsXmlString($dom->saveXml($elem), $result);
    }

    /**
     * O4DOI data for testing.
     * @return string
     */
    private function getTestData()
    {
        $sampleFile = './plugins/generic/medra/tests/functional/serial-issue-as-work.xml';
        return file_get_contents($sampleFile);
    }
}
