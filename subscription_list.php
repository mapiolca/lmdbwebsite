<?php
/**
 * LMDB Website subscription list.
 */

require '../../main.inc.php';

dol_include_once('/lmdbwebsite/class/lmdbwebsitesubscription.class.php');

global $db, $conf, $langs, $user;

$langs->loadLangs(array('lmdbwebsite@lmdbwebsite', 'companies', 'bills', 'contracts'));

if (!$user->hasRight('lmdbwebsite', 'read')) {
	accessforbidden();
}

$sql = 'SELECT s.rowid, s.ref, s.status, s.offer_code, s.frequency, s.recurrence_mode, s.next_invoice_date,';
$sql .= ' so.nom as socname, s.fk_soc, s.fk_contrat, s.fk_facture_initiale, s.fk_facture_rec';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbwebsite_subscription as s';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe as so ON so.rowid = s.fk_soc';
$sql .= ' WHERE s.entity = '.((int) $conf->entity);
$sql .= ' ORDER BY s.rowid DESC';
$resql = $db->query($sql);

llxHeader('', $langs->trans('ModuleLmdbwebsiteName'));

print load_fiche_titre($langs->trans('ModuleLmdbwebsiteName'), '', 'globe');

if (!$resql) {
	print '<div class="error">'.$db->lasterror().'</div>';
	llxFooter();
	$db->close();
	exit;
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>Ref</td><td>Tiers</td><td>Offre</td><td>Frequence</td><td>Reglement</td><td>Statut</td><td>Prochaine facture</td><td>Objets</td>';
print '</tr>';

while ($obj = $db->fetch_object($resql)) {
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($obj->ref).'</td>';
	print '<td>';
	if (!empty($obj->fk_soc)) {
		print '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int) $obj->fk_soc.'">'.dol_escape_htmltag($obj->socname).'</a>';
	}
	print '</td>';
	print '<td>'.dol_escape_htmltag($obj->offer_code).'</td>';
	print '<td>'.dol_escape_htmltag($obj->frequency).'</td>';
	print '<td>'.dol_escape_htmltag($obj->recurrence_mode).'</td>';
	print '<td>'.dol_escape_htmltag($obj->status).'</td>';
	print '<td>'.dol_escape_htmltag($obj->next_invoice_date).'</td>';
	print '<td>';
	$links = array();
	if (!empty($obj->fk_contrat)) {
		$links[] = '<a href="'.DOL_URL_ROOT.'/contrat/card.php?id='.(int) $obj->fk_contrat.'">Contrat</a>';
	}
	if (!empty($obj->fk_facture_initiale)) {
		$links[] = '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.(int) $obj->fk_facture_initiale.'">Facture</a>';
	}
	if (!empty($obj->fk_facture_rec)) {
		$links[] = '<a href="'.DOL_URL_ROOT.'/compta/facture-rec/card.php?id='.(int) $obj->fk_facture_rec.'">Modele</a>';
	}
	print implode(' | ', $links);
	print '</td>';
	print '</tr>';
}

print '</table>';

llxFooter();
$db->close();
