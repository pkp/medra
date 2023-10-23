{**
 * @file plugins/generic/medra/templates/index.tpl
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * List of operations this plugin can perform
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle}
	</h1>

	{capture assign=doiManagementUrl}{url page="dois"}{/capture}
	{capture assign=doiSettingsUrl}{url page="management" op="settings" path="distribution" anchor="dois"}{/capture}
	<notification type="warning">{translate key="manager.dois.settings.relocated" doiManagementUrl=$doiManagementUrl doiSettingsUrl=$doiSettingsUrl}</notification>
{/block}
