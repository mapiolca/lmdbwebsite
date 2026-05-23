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

global $db, $langs, $user;

$langs->loadLangs(array('admin', 'lmdbwebsite@lmdbwebsite'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

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

llxHeader('', $langs->trans('LmdbwebsiteSetup'));

$head = lmdbwebsiteAdminPrepareHead();
print load_fiche_titre($langs->trans('LmdbwebsiteSetup'), '', 'title_setup');
print dol_get_fiche_head($head, 'setup', $langs->trans('LmdbwebsiteSetup'), -1);

if (empty($conf->stancer) || empty($conf->stancer->enabled)) {
	print '<div class="warning">'.$langs->trans('LmdbwebsiteMissingStancer').'</div>';
}

print '<form method="post" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
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

print dol_get_fiche_end();

llxFooter();
$db->close();
