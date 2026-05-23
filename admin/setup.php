<?php
/* Copyright (C) 2026 Pierre Ardoin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup lmdbwebsite
 * \brief   LMDB Website setup page.
 */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/lmdbwebsite/lib/lmdbwebsite.lib.php');
dol_include_once('/lmdbwebsite/class/lmdbwebsiteinstaller.class.php');

global $conf, $db, $langs, $user;

$langs->loadLangs(array('admin', 'lmdbwebsite@lmdbwebsite'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$installer = new LmdbwebsiteInstaller($db);

$settings = array(
	'LMDBWEBSITE_SITE_URL' => array('label' => 'LmdbwebsiteSiteUrl', 'type' => 'text'),
	'LMDBWEBSITE_PRODUCT_REF_BASE' => array('label' => 'LmdbwebsiteProductRefBase', 'type' => 'text'),
	'LMDBWEBSITE_PRODUCT_REF_STANDARD' => array('label' => 'LmdbwebsiteProductRefStandard', 'type' => 'text'),
	'LMDBWEBSITE_PRODUCT_REF_PRO' => array('label' => 'LmdbwebsiteProductRefPro', 'type' => 'text'),
	'LMDBWEBSITE_PRODUCT_REF_BASE_ANNUAL' => array('label' => 'LmdbwebsiteProductRefBaseAnnual', 'type' => 'text'),
	'LMDBWEBSITE_PRODUCT_REF_STANDARD_ANNUAL' => array('label' => 'LmdbwebsiteProductRefStandardAnnual', 'type' => 'text'),
	'LMDBWEBSITE_PRODUCT_REF_PRO_ANNUAL' => array('label' => 'LmdbwebsiteProductRefProAnnual', 'type' => 'text'),
	'LMDBWEBSITE_USER_ID' => array('label' => 'LmdbwebsiteUserId', 'type' => 'number'),
	'LMDBWEBSITE_BANK_ACCOUNT_ID' => array('label' => 'LmdbwebsiteBankAccountId', 'type' => 'number'),
);

if ($action === 'save') {
	$error = 0;
	foreach ($settings as $key => $meta) {
		$value = trim(GETPOST($key, 'alphanohtml'));
		$result = dolibarr_set_const($db, $key, $value, 'chaine', 0, '', $conf->entity);
		if ($result <= 0) {
			$error++;
		}
	}
	if ($error) {
		setEventMessages($langs->trans('Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
}

if ($action === 'createwebsite') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		accessforbidden('POST required');
	}

	$result = $installer->syncWebsite($user);
	if (is_array($result)) {
		$siteAction = empty($result['site_created']) ? $langs->trans('LmdbwebsiteWebsiteSynchronized') : $langs->trans('LmdbwebsiteWebsiteCreated');
		setEventMessages($langs->trans(
			'LmdbwebsiteWebsiteSyncSummary',
			$siteAction,
			$result['pages_created'],
			$result['pages_updated'],
			$result['assets_copied'],
			$result['target_dir']
		), null, 'mesgs');
	} else {
		$message = $installer->error;
		if ($message === 'LmdbwebsiteMissingWebsiteModule') {
			$message = $langs->trans($message);
		}
		setEventMessages($message, $installer->errors, 'errors');
	}
}

llxHeader('', $langs->trans('LmdbwebsiteSetup'));

$head = lmdbwebsiteAdminPrepareHead();
$formToken = newToken();
print load_fiche_titre($langs->trans('LmdbwebsiteSetup'), '', 'title_setup');
print dol_get_fiche_head($head, 'setup', $langs->trans('LmdbwebsiteSetup'), -1);

if (empty($conf->stancer) || empty($conf->stancer->enabled)) {
	print '<div class="warning">'.$langs->trans('LmdbwebsiteMissingStancer').'</div>';
}

print '<form method="post" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$formToken.'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

foreach ($settings as $key => $meta) {
	$value = getDolGlobalString($key, '');
	print '<tr class="oddeven">';
	print '<td>'.$langs->trans($meta['label']).'</td>';
	print '<td><input class="flat minwidth300" type="'.$meta['type'].'" name="'.$key.'" value="'.dol_escape_htmltag($value).'"></td>';
	print '</tr>';
}

print '</table>';
print '<div class="center">';
print '<input class="button button-save" type="submit" value="'.$langs->trans('Save').'">';
print '</div>';
print '</form>';

$websiteInfo = $installer->getWebsiteInfo();
$websiteStatus = $websiteInfo['found'] ? $langs->trans('LmdbwebsiteWebsiteFound', $websiteInfo['id'], $websiteInfo['pages']) : $langs->trans('LmdbwebsiteWebsiteMissing');
$websiteStatus .= $websiteInfo['found'] ? ' - '.$langs->trans($websiteInfo['status'] ? 'LmdbwebsiteWebsitePublished' : 'LmdbwebsiteWebsiteDraft') : '';

print '<br>';
print load_fiche_titre($langs->trans('LmdbwebsiteDolibarrWebsite'), '', 'globe');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbwebsiteWebsiteTargetPath').'</td><td>'.dol_escape_htmltag($websiteInfo['target_dir']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbwebsiteWebsiteCurrentStatus').'</td><td>'.dol_escape_htmltag($websiteStatus).'</td></tr>';
print '</table>';

if (!$websiteInfo['website_enabled']) {
	print '<div class="warning">'.$langs->trans('LmdbwebsiteMissingWebsiteModule').'</div>';
}

print '<form method="post" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.$formToken.'">';
print '<input type="hidden" name="action" value="createwebsite">';
print '<p class="opacitymedium">'.$langs->trans('LmdbwebsiteWebsiteButtonHelp').'</p>';
print '<div class="center">';
print '<input class="button" type="submit"'.($websiteInfo['website_enabled'] ? '' : ' disabled="disabled"').' value="'.$langs->trans('LmdbwebsiteCreateWebsite').'">';
print '</div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
