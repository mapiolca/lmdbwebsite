<?php
define('NOLOGIN', '1');
define('NOCSRFCHECK', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');

require '../../../main.inc.php';

dol_include_once('/lmdbwebsite/class/lmdbwebsitesubscription.class.php');
require_once __DIR__.'/common.php';

global $db;

$errors = array();
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';

$offer = lmdbwebsite_public_clean($posted ? ($_POST['offer'] ?? '') : ($_GET['offer'] ?? 'base'));
$frequency = lmdbwebsite_public_clean($posted ? ($_POST['frequency'] ?? '') : ($_GET['frequency'] ?? 'monthly'));
$recurrenceMode = lmdbwebsite_public_clean($posted ? ($_POST['recurrence_mode'] ?? '') : ($_GET['recurrence_mode'] ?? 'manual_card'));
$allowedOffers = array('base', 'standard', 'pro');
$allowedFrequencies = array('monthly', 'annual');
$allowedModes = array('manual_card', 'card_auto', 'sepa_auto');
if (!in_array($offer, $allowedOffers, true)) {
	$offer = 'base';
}
if (!in_array($frequency, $allowedFrequencies, true)) {
	$frequency = 'monthly';
}
if (!in_array($recurrenceMode, $allowedModes, true)) {
	$recurrenceMode = 'manual_card';
}

$values = array(
	'company_name' => '',
	'firstname' => '',
	'lastname' => '',
	'email' => '',
	'phone' => '',
	'siret' => '',
	'address' => '',
	'zip' => '',
	'town' => '',
	'utm_source' => lmdbwebsite_public_clean($_GET['utm_source'] ?? ''),
	'utm_medium' => lmdbwebsite_public_clean($_GET['utm_medium'] ?? ''),
	'utm_campaign' => lmdbwebsite_public_clean($_GET['utm_campaign'] ?? ''),
);

if ($posted) {
	foreach ($values as $key => $default) {
		$values[$key] = lmdbwebsite_public_clean($_POST[$key] ?? '');
	}

	if (!lmdbwebsite_check_csrf_token((string) ($_POST['token'] ?? ''))) {
		$errors[] = 'La session a expire, merci de reessayer.';
	}
	if (!lmdbwebsite_rate_limit('subscribe', 5, 900)) {
		$errors[] = 'Trop de tentatives. Merci de reessayer dans quelques minutes.';
	}
	if (lmdbwebsite_public_clean($_POST['website'] ?? '') !== '') {
		$errors[] = 'La demande ne peut pas etre envoyee.';
	}
	if ($values['company_name'] === '') {
		$errors[] = 'Le nom de l entreprise est requis.';
	}
	if ($values['firstname'] === '' || $values['lastname'] === '') {
		$errors[] = 'Le prenom et le nom du contact sont requis.';
	}
	if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
		$errors[] = 'L email de facturation est invalide.';
	}
	$values['siret'] = preg_replace('/\D/', '', $values['siret']);
	if (strlen($values['siret']) !== 14) {
		$errors[] = 'Le SIRET doit contenir 14 chiffres.';
	}
	if ($values['phone'] === '') {
		$errors[] = 'Le telephone est requis.';
	}
	if ($values['address'] === '' || $values['zip'] === '' || $values['town'] === '') {
		$errors[] = 'L adresse de facturation complete est requise.';
	}
	if (empty($_POST['accept_terms'])) {
		$errors[] = 'Vous devez accepter les conditions de vente.';
	}

	if (empty($errors)) {
		$subscription = new LmdbwebsiteSubscription($db);
		$data = array_merge($values, array(
			'offer' => $offer,
			'frequency' => $frequency,
			'recurrence_mode' => $recurrenceMode,
		));
		$result = $subscription->createFromPublicRequest($data);
		if (is_array($result) && !empty($result['payment_url'])) {
			header('Location: '.$result['payment_url']);
			exit;
		}
		$errors[] = $subscription->error ?: 'Impossible de creer l abonnement. Merci de nous contacter.';
	}
}

$currentOffer = lmdbwebsite_public_offer($offer);
$price = $frequency === 'annual' ? (float) ($currentOffer['annual'] ?? 0) : (float) ($currentOffer['monthly'] ?? 0);
$period = $frequency === 'annual' ? '/an HT' : '/mois HT';

lmdbwebsite_public_header('Abonnement', 'Choisissez une offre et demarrez votre abonnement Les Metiers du Batiment.');
print '<section class="panel">';
print '<div class="panel-header">';
print '<p class="eyebrow">Abonnement Dolibarr</p>';
print '<h1>Choisir une offre et payer avec Stancer</h1>';
print '<p>Le module cree le tiers, le contact, le contrat, la facture modele et la premiere facture avant de vous rediriger vers le paiement securise.</p>';
print '</div>';

print '<div class="grid">';
foreach (lmdbwebsite_public_offers() as $offerItem) {
	$itemPrice = $frequency === 'annual' ? (float) ($offerItem['annual'] ?? 0) : (float) ($offerItem['monthly'] ?? 0);
	print '<article class="offer">';
	print '<strong>'.lmdbwebsite_escape($offerItem['name'] ?? '').'</strong>';
	print '<p>'.lmdbwebsite_escape($offerItem['summary'] ?? '').'</p>';
	print '<div class="price">'.lmdbwebsite_escape(number_format($itemPrice, 0, ',', ' ')).' EUR '.$period.'</div>';
	print '</article>';
}
print '</div>';

lmdbwebsite_public_errors($errors);

print '<form method="post" action="'.lmdbwebsite_escape($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.lmdbwebsite_escape(lmdbwebsite_csrf_token()).'">';
foreach (array('utm_source', 'utm_medium', 'utm_campaign') as $utmKey) {
	print '<input type="hidden" name="'.$utmKey.'" value="'.lmdbwebsite_escape($values[$utmKey]).'">';
}
print '<label class="hidden-field">Site web<input type="text" name="website" autocomplete="off"></label>';
print '<div class="form-grid">';
print '<label>Offre<select required name="offer">';
foreach (lmdbwebsite_public_offers() as $offerItem) {
	$id = (string) ($offerItem['id'] ?? '');
	print '<option value="'.lmdbwebsite_escape($id).'"'.lmdbwebsite_public_selected($id, $offer).'>'.lmdbwebsite_escape($offerItem['name'] ?? '').'</option>';
}
print '</select></label>';
print '<label>Frequence<select required name="frequency">';
print '<option value="monthly"'.lmdbwebsite_public_selected('monthly', $frequency).'>Mensuelle</option>';
print '<option value="annual"'.lmdbwebsite_public_selected('annual', $frequency).'>Annuelle</option>';
print '</select></label>';
print '<label class="full">Mode de reglement<select required name="recurrence_mode">';
foreach ($allowedModes as $mode) {
	print '<option value="'.lmdbwebsite_escape($mode).'"'.lmdbwebsite_public_selected($mode, $recurrenceMode).'>'.lmdbwebsite_escape(lmdbwebsite_public_recurrence_label($mode)).'</option>';
}
print '</select></label>';
print '<label>Entreprise<input required type="text" name="company_name" value="'.lmdbwebsite_escape($values['company_name']).'"></label>';
print '<label>Email de facturation<input required type="email" name="email" value="'.lmdbwebsite_escape($values['email']).'"></label>';
print '<label>Prenom<input required type="text" name="firstname" value="'.lmdbwebsite_escape($values['firstname']).'"></label>';
print '<label>Nom<input required type="text" name="lastname" value="'.lmdbwebsite_escape($values['lastname']).'"></label>';
print '<label>Telephone<input required type="tel" name="phone" value="'.lmdbwebsite_escape($values['phone']).'"></label>';
print '<label>SIRET<input required type="text" name="siret" inputmode="numeric" pattern="[0-9 ]{14,17}" autocomplete="off" value="'.lmdbwebsite_escape($values['siret']).'"></label>';
print '<label>Code postal<input required type="text" name="zip" value="'.lmdbwebsite_escape($values['zip']).'"></label>';
print '<label class="full">Adresse<input required type="text" name="address" value="'.lmdbwebsite_escape($values['address']).'"></label>';
print '<label>Ville<input required type="text" name="town" value="'.lmdbwebsite_escape($values['town']).'"></label>';
print '</div>';
print '<label class="checkbox"><input required type="checkbox" name="accept_terms" value="1"><span>J accepte les conditions de vente et la creation des objets Dolibarr necessaires a l abonnement.</span></label>';
print '<div class="actions"><button type="submit">Continuer vers le paiement</button><a class="button secondary" href="/contact/">Offre sur mesure</a></div>';
print '</form>';
print '</section>';
lmdbwebsite_public_footer();
