<?php

/**
 * @file plugins/generic/medra/classes/MedraSetting.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MedraSettings
 *
 * @brief Setting management class to handle schema, fields, validation, etc. for mEDRA plugin
 */

namespace APP\plugins\generic\medra\classes;

use APP\plugins\generic\medra\filter\O4DOIXmlFilter;
use PKP\components\forms\FieldHTML;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldSelect;
use PKP\components\forms\FieldText;
use PKP\context\Context;
use PKP\facades\Locale;

class MedraSettings extends \PKP\doi\RegistrationAgencySettings
{
    public function getSchema(): \stdClass
    {
        return (object) [
            'title' => 'mEDRA Plugin',
            'description' => 'Registration agency plugin for mEDRA',
            'type' => 'object',
            'required' => [
                'registrantName',
                'fromCompany',
                'fromName',
                'fromEmail',
                'publicationCountry',
                'exportIssuesAs',
            ],
            'properties' => (object) [
                'registrantName' => (object) [
                    'type' => 'string',
                    'validation' => ['max:60']
                ],
                'fromName' => (object) [
                    'type' => 'string',
                    'validation' => ['max:60']
                ],
                'fromCompany' => (object) [
                    'type' => 'string',
                    'validation' => ['max:60']
                ],
                'fromEmail' => (object) [
                    'type' => 'string',
                    'validation' => ['max:90']
                ],
                'publicationCountry' => (object) [
                    'type' => 'string'
                ],
                'exportIssuesAs' => (object) [
                    'type' => 'integer',
                    'validation' => ["in:1,2"]
                ],
                'username' => (object) [
                    'type' => 'string',
                    'validation' => [
                        'nullable',
                        'max:50',
                        'regex:/^[^:]*$/'
                    ]
                ],
                'password' => (object) [
                    'type' => 'string',
                    'validation' => ['nullable', 'max:50']
                ],
                'crEnabled' => (object) [
                    'type' => 'boolean',
                    'validation' => ['nullable']
                ],
                'testMode' => (object) [
                    'type' => 'boolean',
                    'validation' => ['nullable']
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getFields(Context $context): array
    {
        $countries = [];
        foreach (Locale::getCountries() as $country) {
            $countries[] = [
                'value' => $country->getAlpha2(),
                'label' => $country->getLocalName()
            ];
        }
        usort($countries, function ($a, $b) {
            return strcmp($a['label'], $b['label']);
        });

        $exportIssueOptions = [
            ['value' => O4DOIXmlFilter::O4DOI_ISSUE_AS_WORK, 'label' => __('plugins.importexport.medra.settings.form.work')],
            ['value' => O4DOIXmlFilter::O4DOI_ISSUE_AS_MANIFESTATION, 'label' => __('plugins.importexport.medra.settings.form.manifestation')],
        ];

        return [
            new FieldHTML('preamble', [
                'label' => __('plugins.importexport.medra.settings.label'),
                'description' => $this->_getPreambleText(),
            ]),
            new FieldText('registrantName', [
                'label' => __('plugins.importexport.medra.settings.form.registrantName.label'),
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'registrantName'),
                'description' => __('plugins.importexport.medra.settings.form.registrantName'),
                'isRequired' => true,
            ]),
            new FieldText('fromName', [
                'label' => __('plugins.importexport.medra.settings.form.fromName'),
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'fromName'),
                'description' => __('plugins.importexport.medra.settings.form.fromFields'),
                'isRequired' => true,
            ]),
            new FieldText('fromCompany', [
                'label' => __('plugins.importexport.medra.settings.form.fromCompany'),
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'fromCompany'),
                'isRequired' => true,
            ]),
            new FieldText('fromEmail', [
                'label' => __('plugins.importexport.medra.settings.form.fromEmail'),
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'fromEmail'),
                'isRequired' => true,
            ]),
            new FieldSelect('publicationCountry', [
                'label' => __('common.country'),
                'description' => __('plugins.importexport.medra.settings.form.publicationCountry'),
                'options' => $countries,
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'publicationCountry'),
                'isRequired' => true,
            ]),
            new FieldSelect('exportIssuesAs', [
                'label' => __('plugins.importexport.medra.settings.form.exportIssuesAs.label'),
                'description' => __('plugins.importexport.medra.settings.form.exportIssuesAs'),
                'options' => $exportIssueOptions,
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'exportIssuesAs'),
                'isRequired' => true,
            ]),
            new FieldText('username', [
                'label' => __('plugins.importexport.medra.settings.form.username'),
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'username'),
            ]),
            new FieldText('password', [
                'label' => __('plugins.importexport.common.settings.form.password'),
                'description' => __('plugins.importexport.common.settings.form.password.description'),
                'inputType' => 'password',
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'password'),
            ]),
            new FieldOptions('crEnabled', [
                'label' => __('plugins.importexport.medra.crossref.label'),
                'options' => [
                    ['value' => true, 'label' => __('plugins.importexport.medra.crossref')],
                ],
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'crEnabled'),
            ]),
            new FieldOptions('testMode', [
                'label' => __('plugins.importexport.common.settings.form.testMode.label'),
                'options' => [
                    ['value' => true, 'label' => __('plugins.importexport.medra.settings.form.testMode.description')],
                ],
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'testMode'),
            ]),
        ];
    }

    protected function _getPreambleText(): string
    {
        $text = '';
        $text .= '<p>' . __('plugins.importexport.medra.settings.description') . '</p>';
        $text .= '<p>' . __('plugins.importexport.medra.intro') . '</p>';
        return $text;
    }
}
