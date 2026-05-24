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
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/lmdbwebsite/lib/lmdbwebsite.lib.php');
dol_include_once('/lmdbwebsite/class/lmdbwebsiteinstaller.class.php');

global $conf, $db, $langs, $user;

$langs->loadLangs(array('admin', 'lmdbwebsite@lmdbwebsite'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$installer = new LmdbwebsiteInstaller($db);
$form = new Form($db);

$settings = array(
	'LMDBWEBSITE_SITE_URL' => array('label' => 'LmdbwebsiteSiteUrl', 'type' => 'text'),
	'LMDBWEBSITE_DOLIBARR_URL' => array('label' => 'LmdbwebsiteDolibarrUrl', 'type' => 'text'),
	'LMDBWEBSITE_PRODUCT_REF_BASE' => array('label' => 'LmdbwebsiteProductRefBase', 'type' => 'service'),
	'LMDBWEBSITE_PRODUCT_REF_STANDARD' => array('label' => 'LmdbwebsiteProductRefStandard', 'type' => 'service'),
	'LMDBWEBSITE_PRODUCT_REF_PRO' => array('label' => 'LmdbwebsiteProductRefPro', 'type' => 'service'),
	'LMDBWEBSITE_PRODUCT_REF_BASE_ANNUAL' => array('label' => 'LmdbwebsiteProductRefBaseAnnual', 'type' => 'service'),
	'LMDBWEBSITE_PRODUCT_REF_STANDARD_ANNUAL' => array('label' => 'LmdbwebsiteProductRefStandardAnnual', 'type' => 'service'),
	'LMDBWEBSITE_PRODUCT_REF_PRO_ANNUAL' => array('label' => 'LmdbwebsiteProductRefProAnnual', 'type' => 'service'),
	'LMDBWEBSITE_USER_ID' => array('label' => 'LmdbwebsiteUserId', 'type' => 'user'),
	'LMDBWEBSITE_BANK_ACCOUNT_ID' => array('label' => 'LmdbwebsiteBankAccountId', 'type' => 'bankaccount'),
);
$serviceOptions = lmdbwebsite_admin_get_service_options($db);

if ($action === 'save') {
	$error = 0;
	foreach ($settings as $key => $meta) {
		$value = lmdbwebsite_admin_get_setting_post_value($key, $meta);
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
		$messages = array();
		$messages[] = $langs->trans(
			'LmdbwebsiteWebsiteSyncSummary',
			$siteAction,
			$result['pages_created'],
			$result['pages_updated'],
			$result['assets_copied']
		);
		$messages[] = $langs->trans(
			'LmdbwebsiteWebsiteSyncDirectory',
			$result['target_dir']
		);
		setEventMessages(null, $messages, 'mesgs');
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
	if ($meta['type'] === 'service') {
		print '<td>'.lmdbwebsite_admin_print_service_select($key, $value, $serviceOptions).'</td>';
	} elseif ($meta['type'] === 'user') {
		print '<td>'.lmdbwebsite_admin_print_user_select($form, $key, $value).'</td>';
	} elseif ($meta['type'] === 'bankaccount') {
		print '<td>'.lmdbwebsite_admin_print_bank_account_select($form, $key, $value).'</td>';
	} else {
		print '<td><input class="flat minwidth300" type="'.$meta['type'].'" name="'.$key.'" value="'.dol_escape_htmltag($value).'"></td>';
	}
	print '</tr>';
}

print '</table>';
print '<div class="center">';
print '<input class="button button-save" type="submit" value="'.$langs->trans('Save').'">';
print '</div>';
print '</form>';

$websiteInfo = $installer->getWebsiteInfo();
$directoryDiagnostics = $installer->getWebsiteDirectoryDiagnostics();
$websiteStatus = $websiteInfo['found'] ? $langs->trans('LmdbwebsiteWebsiteFound', $websiteInfo['id'], $websiteInfo['pages']) : $langs->trans('LmdbwebsiteWebsiteMissing');
$websiteStatus .= $websiteInfo['found'] ? ' - '.$langs->trans($websiteInfo['status'] ? 'LmdbwebsiteWebsitePublished' : 'LmdbwebsiteWebsiteDraft') : '';

print '<br>';
print load_fiche_titre($langs->trans('LmdbwebsiteDolibarrWebsite'), '', 'globe');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbwebsiteWebsiteTargetPath').'</td><td>'.dol_escape_htmltag($websiteInfo['target_dir']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbwebsiteWebsiteCurrentStatus').'</td><td>'.dol_escape_htmltag($websiteStatus).'</td></tr>';
print '</table>';

print '<br>';
print load_fiche_titre($langs->trans('LmdbwebsiteWebsiteDirectoryDiagnostic'), '', 'folder-open');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
$diagnosticRows = array(
	array('LmdbwebsiteWebsiteDiagnosticTargetDir', 'target_dir', 'text'),
	array('LmdbwebsiteWebsiteDiagnosticTargetExists', 'target_exists', 'bool'),
	array('LmdbwebsiteWebsiteDiagnosticTargetWritable', 'target_writable', 'bool'),
	array('LmdbwebsiteWebsiteDiagnosticParentDir', 'parent_dir', 'text'),
	array('LmdbwebsiteWebsiteDiagnosticParentExists', 'parent_exists', 'bool'),
	array('LmdbwebsiteWebsiteDiagnosticParentWritable', 'parent_writable', 'bool'),
	array('LmdbwebsiteWebsiteDiagnosticClosestParent', 'closest_existing_parent', 'text'),
	array('LmdbwebsiteWebsiteDiagnosticClosestParentWritable', 'closest_existing_parent_writable', 'bool'),
	array('LmdbwebsiteWebsiteDiagnosticDolibarrMainDataRoot', 'dolibarr_main_data_root', 'text'),
	array('LmdbwebsiteWebsiteDiagnosticDolDataRoot', 'dol_data_root', 'text'),
	array('LmdbwebsiteWebsiteDiagnosticWebsiteDirOutput', 'website_dir_output', 'text'),
	array('LmdbwebsiteWebsiteDiagnosticLmdbwebsiteDirOutput', 'lmdbwebsite_dir_output', 'text'),
	array('LmdbwebsiteWebsiteDiagnosticOpenBaseDir', 'open_basedir', 'text'),
);
foreach ($diagnosticRows as $row) {
	$value = isset($directoryDiagnostics[$row[1]]) ? $directoryDiagnostics[$row[1]] : '';
	if ($row[2] === 'bool') {
		$value = $langs->trans($value ? 'Yes' : 'No');
	} elseif ($value === '') {
		$value = '-';
	}
	print '<tr class="oddeven"><td>'.$langs->trans($row[0]).'</td><td>'.dol_escape_htmltag((string) $value).'</td></tr>';
}
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

/**
 * Return a sanitized setup value from POST.
 *
 * @param string $key Settings key
 * @param array<string,string> $meta Settings metadata
 * @return string
 */
function lmdbwebsite_admin_get_setting_post_value($key, $meta)
{
	if (in_array($meta['type'], array('user', 'bankaccount'), true)) {
		$value = (int) GETPOST($key, 'int');
		return $value > 0 ? (string) $value : '';
	}

	return trim(GETPOST($key, 'alphanohtml'));
}

/**
 * Return service products available for the current multicompany context.
 *
 * @param DoliDB $db Database handler
 * @return array<int,array<string,string>>
 */
function lmdbwebsite_admin_get_service_options($db)
{
	global $conf;

	$entities = array((int) $conf->entity);
	if (function_exists('getEntity')) {
		$entities = array();
		$productEntities = getEntity('product');
		if (is_array($productEntities)) {
			$productEntities = implode(',', $productEntities);
		}
		foreach (explode(',', (string) $productEntities) as $entity) {
			$entities[] = (int) $entity;
		}
	}
	$entities[] = 0;
	$entities = array_values(array_unique(array_filter($entities, 'is_numeric')));
	if (empty($entities)) {
		$entities = array((int) $conf->entity);
	}

	$sql = 'SELECT rowid, ref, label, entity FROM '.MAIN_DB_PREFIX.'product';
	$sql .= ' WHERE fk_product_type = 1';
	$sql .= ' AND tosell = 1';
	$sql .= ' AND entity IN ('.implode(',', array_map('intval', $entities)).')';
	$sql .= ' ORDER BY ref ASC, label ASC';

	$options = array();
	$resql = $db->query($sql);
	if (!$resql) {
		return $options;
	}

	while ($obj = $db->fetch_object($resql)) {
		$label = $obj->ref;
		if (!empty($obj->label)) {
			$label .= ' - '.$obj->label;
		}
		if ((int) $obj->entity !== (int) $conf->entity) {
			$label .= ' (entity '.$obj->entity.')';
		}
		$options[] = array(
			'ref' => (string) $obj->ref,
			'label' => $label,
		);
	}

	return $options;
}

/**
 * Print a select2 service selector.
 *
 * @param string $key Settings key
 * @param string $value Current product ref
 * @param array<int,array<string,string>> $options Service options
 * @return string
 */
function lmdbwebsite_admin_print_service_select($key, $value, $options)
{
	global $langs;

	$html = '<select class="flat minwidth300 maxwidth500" name="'.dol_escape_htmltag($key).'" id="'.dol_escape_htmltag($key).'">';
	$html .= '<option value=""></option>';
	$found = $value === '';
	foreach ($options as $option) {
		$selected = ((string) $option['ref'] === (string) $value) ? ' selected="selected"' : '';
		if ($selected !== '') {
			$found = true;
		}
		$html .= '<option value="'.dol_escape_htmltag($option['ref']).'"'.$selected.'>'.dol_escape_htmltag($option['label']).'</option>';
	}
	if (!$found) {
		$html .= '<option value="'.dol_escape_htmltag($value).'" selected="selected">'.dol_escape_htmltag($langs->trans('LmdbwebsiteServiceNotFound', $value)).'</option>';
	}
	$html .= '</select>';
	if (function_exists('ajax_combobox')) {
		$html .= ajax_combobox($key);
	}

	return $html;
}

/**
 * Print a select2 user selector for the current multicompany context.
 *
 * @param Form $form Form helper
 * @param string $key Settings key
 * @param string $value Current user id
 * @return string
 */
function lmdbwebsite_admin_print_user_select(Form $form, $key, $value)
{
	$selected = (int) $value > 0 ? (int) $value : '';
	if (method_exists($form, 'select_dolusers')) {
		return $form->select_dolusers($selected, $key, 1, null, 0, '', '', 'default', 0, 1, '', 0, '', 'minwidth300 maxwidth500');
	}

	return '<input class="flat minwidth300" type="number" name="'.dol_escape_htmltag($key).'" value="'.dol_escape_htmltag($value).'">';
}

/**
 * Print a select2 bank account selector for open accounts.
 *
 * @param Form $form Form helper
 * @param string $key Settings key
 * @param string $value Current bank account id
 * @return string
 */
function lmdbwebsite_admin_print_bank_account_select(Form $form, $key, $value)
{
	$selected = (int) $value > 0 ? (int) $value : '';
	if (method_exists($form, 'select_comptes')) {
		return (string) $form->select_comptes($selected, $key, 0, '', 1, '', 1, 'minwidth300 maxwidth500', 1);
	}

	return '<input class="flat minwidth300" type="number" name="'.dol_escape_htmltag($key).'" value="'.dol_escape_htmltag($value).'">';
}
