<?php

/**
 * @file plugins/generic/medra/MedraDoiDataMigration.php
 *
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MedraDoiDataMigration
 *
 * @brief Migrations for the mEDRA DOI settings
 */

namespace APP\plugins\generic\medra;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\doi\Doi;
use PKP\file\FileManager;
use PKP\install\DowngradeNotSupportedException;
use PKP\install\Installer;

class MedraDoiDataMigration extends Migration
{
    protected Installer $installer;

    public function __construct(Installer $installer)
    {
        $this->installer = $installer;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ($this->installer) {
            $version = $this->installer->getCurrentVersion();
            if ($version->getProduct() == 'medra' && $version->getProductType() == 'plugins.generic') {
                /** @var VersionDAO $versionDao */
                $versionDao = DAORegistry::getDAO('VersionDAO');
                $installedPluginVersion = $versionDao->getCurrentVersion($version->getProductType(), $version->getProduct());
                if (!$installedPluginVersion) {
                    $this->migrateMedraSettings();
                }
            }
        }
    }

    public function migrateMedraSettings(): void
    {
        // ===== Filters ===== //
        // Old filters should not be there in 3.4 but
        // for a case there are, remove them.
        // The new filters will be installed with this plugin.
        $galleyFilterGroupId = DB::table('filters')
            ->where('class_name', '=', 'plugins.importexport.medra.filter.GalleyMedraXmlFilter')
            ->orWhere('class_name', '=', 'plugins.importexport.medra.filter.ArticleMedraXmlFilter')
            ->orWhere('class_name', '=', 'plugins.importexport.medra.filter.IssueMedraXmlFilter')
            ->pluck('filter_group_id');
        DB::table('filter_groups')->whereIn('filter_group_id', $galleyFilterGroupId)->delete();


        // ===== Issues Statuses & Settings ===== //
        // 1. Get issues with mEDRA-related info
        $issueData = DB::table('issues', 'i')
            ->leftJoin('issue_settings as iss', 'i.issue_id', '=', 'iss.issue_id')
            ->whereIn('iss.setting_name', ['medra::registeredDoi', 'medra::status'])
            ->select(['i.issue_id', 'i.doi_id', 'iss.setting_name', 'iss.setting_value'])
            ->get()
            ->reduce(function ($carry, $item) {
                if (!isset($carry[$item->issue_id])) {
                    $carry[$item->issue_id] = [
                        'doi_id' => $item->doi_id
                    ];
                }

                $carry[$item->issue_id][$item->setting_name] = $item->setting_value;
                return $carry;
            }, []);

        // 2. Map statuses insert statements
        $statuses = [];
        $registrationAgencies = [];
        foreach ($issueData as $item) {
            // Status
            if (isset($item['medra::status'])) {
                $status = Doi::STATUS_ERROR;
                $registrationAgency = null;
                if (in_array($item['medra::status'], ['found', 'registered', 'markedRegistered'])) {
                    if ($item['medra::status'] === 'registered') {
                        $registrationAgency = 'MedraExportPlugin';
                    }
                    $status = Doi::STATUS_REGISTERED;
                } elseif (isset($item['medra::registeredDoi'])) {
                    $status = Doi::STATUS_REGISTERED;
                }
                $statuses[$item['doi_id']] = ['status' => $status];
                $registrationAgencies[$item['doi_id']] = $registrationAgency;
            }
        }

        // 3. Insert updated statuses
        foreach ($statuses as $doiId => $insert) {
            DB::table('dois')
                ->where('doi_id', '=', $doiId)
                ->update($insert);
        }

        foreach ($registrationAgencies as $doiId => $agency) {
            if ($agency === null) {
                continue;
            }

            DB::table('doi_settings')
                ->insert([
                    'doi_id' => $doiId,
                    'setting_name' => 'registrationAgency',
                    'setting_value' => $agency
                ]);
        }

        // 4. Clean up old settings
        DB::table('issue_settings')
            ->whereIn('setting_name', ['medra::registeredDoi', 'medra::status'])
            ->delete();

        // ===== Publications Statuses & Settings ===== //
        // 1. Get publications with mEDRA-related info
        $publicationData = DB::table('submissions', 's')
            ->leftJoin('submission_settings as ss', 's.submission_id', '=', 'ss.submission_id')
            ->leftJoin('publications as p', 's.current_publication_id', '=', 'p.publication_id')
            ->whereIn('ss.setting_name', ['medra::registeredDoi', 'medra::status'])
            ->select(['p.publication_id', 'p.doi_id', 'ss.setting_name', 'ss.setting_value'])
            ->get()
            ->reduce(function ($carry, $item) {
                if (!isset($carry[$item->publication_id])) {
                    $carry[$item->publication_id] = [
                        'doi_id' => $item->doi_id
                    ];
                }

                $carry[$item->publication_id][$item->setting_name] = $item->setting_value;
                return $carry;
            }, []);

        // 2. Map statuses insert statements
        $statuses = [];
        $registrationAgencies = [];
        foreach ($publicationData as $item) {
            // Status
            if (isset($item['medra::status'])) {
                $status = Doi::STATUS_ERROR;
                $registrationAgency = null;
                if (in_array($item['medra::status'], ['found', 'registered', 'markedRegistered'])) {
                    if ($item['medra::status'] === 'registered') {
                        $registrationAgency = 'MedraExportPlugin';
                    }
                    $status = Doi::STATUS_REGISTERED;
                } elseif (isset($item['medra::registeredDoi'])) {
                    $status = Doi::STATUS_REGISTERED;
                }
                $statuses[$item['doi_id']] = ['status' => $status];
                $registrationAgencies[$item['doi_id']] = $registrationAgency;
            }
        }

        // 3. Insert updated statuses
        foreach ($statuses as $doiId => $insert) {
            DB::table('dois')
                ->where('doi_id', '=', $doiId)
                ->update($insert);
        }

        foreach ($registrationAgencies as $doiId => $agency) {
            if ($agency === null) {
                continue;
            }

            DB::table('doi_settings')
                ->insert([
                    'doi_id' => $doiId,
                    'setting_name' => 'registrationAgency',
                    'setting_value' => $agency
                ]);
        }

        // 4. Clean up old settings
        DB::table('publication_settings')
            ->whereIn('setting_name', ['medra::registeredDoi', 'medra::status'])
            ->delete();

        // ===== Galleys Statuses & Settings ===== //
        // 1. Get galleys with mEDRA-related info
        $galleyData = DB::table('publication_galleys', 'pg')
            ->leftJoin('publication_galley_settings as pgs', 'pg.galley_id', '=', 'pgs.galley_id')
            ->whereIn('pgs.setting_name', ['medra::registeredDoi', 'medra::status'])
            ->select(['pg.galley_id', 'pg.doi_id', 'pgs.setting_name', 'pgs.setting_value'])
            ->get()
            ->reduce(function ($carry, $item) {
                if (!isset($carry[$item->galley_id])) {
                    $carry[$item->galley_id] = [
                        'doi_id' => $item->doi_id
                    ];
                }

                $carry[$item->galley_id][$item->setting_name] = $item->setting_value;
                return $carry;
            }, []);

        // 2. Map statuses insert statements
        $statuses = [];
        $registrationAgencies = [];
        foreach ($galleyData as $item) {
            // Status
            if (isset($item['medra::status'])) {
                $status = Doi::STATUS_ERROR;
                $registrationAgency = null;
                if (in_array($item['medra::status'], ['found', 'registered', 'markedRegistered'])) {
                    if ($item['medra::status'] === 'registered') {
                        $registrationAgency = 'MedraExportPlugin';
                    }
                    $status = Doi::STATUS_REGISTERED;
                } elseif (isset($item['medra::registeredDoi'])) {
                    $status = Doi::STATUS_REGISTERED;
                }
                $statuses[$item['doi_id']] = ['status' => $status];
                $registrationAgencies[$item['doi_id']] = $registrationAgency;
            }
        }

        // 3. Insert updated statuses
        foreach ($statuses as $doiId => $insert) {
            DB::table('dois')
                ->where('doi_id', '=', $doiId)
                ->update($insert);
        }

        foreach ($registrationAgencies as $doiId => $agency) {
            if ($agency === null) {
                continue;
            }

            DB::table('doi_settings')
                ->insert([
                    'doi_id' => $doiId,
                    'setting_name' => 'registrationAgency',
                    'setting_value' => $agency
                ]);
        }

        // 4. Clean up old settings
        DB::table('publication_galley_settings')
            ->whereIn('setting_name', ['medra::registeredDoi', 'medra::status'])
            ->delete();

        // ===== General cleanup ===== //

        // If any mEDRA settings are configured, assume plugin is in use and enable
        $contextsWithPluginEnabled = DB::table('journals')
            ->whereIn('journal_id', function (Builder $q) {
                $q->select('context_id')
                    ->from('plugin_settings')
                    ->where('plugin_name', '=', 'medraexportplugin');
            })
            ->select(['journal_id'])
            ->get();
        $contextsWithPluginEnabled->each(function ($item) {
            DB::table('plugin_settings')
                ->insert(
                    [
                        'plugin_name' => 'medraplugin',
                        'context_id' => $item->journal_id,
                        'setting_name' => 'enabled',
                        'setting_value' => 1,
                        'setting_type' => 'bool'
                    ]
                );
        });

        // Enable automatic DOI deposit if configured
        $contextsWithAutomaticDeposit = DB::table('journals')
            ->whereIn('journal_id', function (Builder $q) {
                $q->select(['context_id'])
                    ->from('plugin_settings')
                    ->where('plugin_name', '=', 'medraexportplugin')
                    ->where('setting_name', '=', 'automaticRegistration')
                    ->where('setting_value', '=', 1) ;
            })
            ->select(['journal_id'])
            ->get();
        $contextsWithAutomaticDeposit->each(function ($item) {
            DB::table('journal_settings')
                ->upsert(
                    [
                        'journal_id' => $item->journal_id,
                        'setting_name' => 'automaticDoiDeposit',
                        'setting_value' => 1
                    ],
                    ['journal_id', 'locale', 'setting_name'],
                    ['setting_value']
                );
        });

        DB::table('plugin_settings')
            ->where('plugin_name', '=', 'medraexportplugin')
            ->where('setting_name', '=', 'automaticRegistration')
            ->delete();

        DB::table('plugin_settings')
            ->where('plugin_name', '=', 'medraexportplugin')
            ->update(['plugin_name' => 'medraplugin']);

        // Delete no-longer-in-use version for importExport plugin
        DB::table('versions')
            ->where('product_type', '=', 'plugins.importexport')
            ->where('product', '=', 'medra')
            ->delete();

        // Remove scheduled task
        DB::table('scheduled_tasks')
            ->where('class_name', '=', 'plugins.importexport.medra.MedraInfoSender')
            ->delete();

        // Delete no-longer-in-use files for importExport plugin, in case there are still there.
        $fileManager = new FileManager();
        $oldMedraImportExportPlugin = Core::getBaseDir() . '/plugins/importexport/medra';
        $fileManager->rmtree($oldMedraImportExportPlugin);
    }

    /**
     * Reverse the migrations
     *
     * @throws DowngradeNotSupportedException
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
