<?php
define('NOLOGIN', '1');
define('NOCSRFCHECK', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');

require '../../../main.inc.php';

dol_include_once('/lmdbwebsite/class/lmdbwebsitesubscription.class.php');
require_once __DIR__.'/common.php';

global $db;

$token = lmdbwebsite_public_clean($_GET['token'] ?? ($_POST['token_onboarding'] ?? ''));
$errors = array();
$done = false;
$subscription = new LmdbwebsiteSubscription($db);

if ($token === '' || $subscription->fetch(0, '', 'onboarding_token', $token) <= 0) {
	$errors[] = 'Lien post-paiement invalide.';
} elseif (!empty($subscription->onboarding_token_expiry) && strtotime($subscription->onboarding_token_expiry) < time()) {
	$errors[] = 'Ce lien post-paiement a expire.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
	if (!lmdbwebsite_check_csrf_token((string) ($_POST['token'] ?? ''))) {
		$errors[] = 'La session a expire, merci de reessayer.';
	}
	if (!lmdbwebsite_rate_limit('onboarding', 6, 900)) {
		$errors[] = 'Trop de tentatives. Merci de reessayer dans quelques minutes.';
	}
	$siret = preg_replace('/\D/', '', (string) ($_POST['siret'] ?? ''));
	if (strlen($siret) !== 14) {
		$errors[] = 'Le SIRET doit contenir 14 chiffres.';
	}
	if (empty($_FILES['logo']['tmp_name'])) {
		$errors[] = 'Le logo est requis.';
	}
	if (empty($errors)) {
		$result = $subscription->submitOnboarding($siret, $_FILES['logo']);
		if ($result > 0) {
			$done = true;
		} else {
			$errors[] = $subscription->error ?: 'Impossible d enregistrer les informations.';
		}
	}
}

lmdbwebsite_public_header('Informations post-paiement', 'Saisie du SIRET et du logo apres paiement.');
print '<section class="panel">';
print '<div class="panel-header">';
print '<p class="eyebrow">Onboarding</p>';
print '<h1>SIRET et logo</h1>';
print '<p>Ces informations servent a preparer manuellement la creation de votre entite Dolibarr.</p>';
print '</div>';

lmdbwebsite_public_errors($errors);

if ($done) {
	print '<div class="notice success">Merci, vos informations ont ete transmises. Une tache interne a ete creee pour preparer votre entite Dolibarr.</div>';
} elseif (empty($errors) || $token !== '') {
	print '<form method="post" enctype="multipart/form-data" action="'.lmdbwebsite_escape($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.lmdbwebsite_escape(lmdbwebsite_csrf_token()).'">';
	print '<input type="hidden" name="token_onboarding" value="'.lmdbwebsite_escape($token).'">';
	print '<div class="form-grid">';
	print '<label>SIRET<input required type="text" name="siret" inputmode="numeric" pattern="[0-9 ]{14,17}" autocomplete="off"></label>';
	print '<label>Logo PNG, JPG ou WebP<input required type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>';
	print '</div>';
	print '<div class="actions"><button type="submit">Envoyer les informations</button></div>';
	print '</form>';
}
print '</section>';
lmdbwebsite_public_footer();
