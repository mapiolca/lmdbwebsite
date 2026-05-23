<?php
define('NOLOGIN', '1');
define('NOCSRFCHECK', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');

require '../../../main.inc.php';

dol_include_once('/lmdbwebsite/class/lmdbwebsitesubscription.class.php');
require_once __DIR__.'/common.php';

global $db;

$sid = (int) ($_GET['sid'] ?? 0);
$token = lmdbwebsite_public_clean($_GET['token'] ?? '');
$errors = array();
$subscription = new LmdbwebsiteSubscription($db);

if ($sid <= 0 || $subscription->fetch($sid) <= 0 || empty($subscription->onboarding_token) || !hash_equals($subscription->onboarding_token, $token)) {
	$errors[] = 'Lien de retour invalide.';
} else {
	$result = $subscription->reconcileInitialPayment();
	if ($result < 0) {
		$errors[] = $subscription->error ?: 'Le paiement ne peut pas encore etre confirme.';
	}
	if (empty($errors) && in_array($subscription->status, array(LmdbwebsiteSubscription::STATUS_ONBOARDING_PENDING, LmdbwebsiteSubscription::STATUS_ACTIVE, LmdbwebsiteSubscription::STATUS_ONBOARDING_DONE), true)) {
		header('Location: '.lmdbwebsite_public_url('onboarding.php', array('token' => $subscription->onboarding_token)));
		exit;
	}
}

lmdbwebsite_public_header('Retour paiement', 'Retour de paiement securise Stancer.');
print '<section class="panel">';
print '<div class="panel-header">';
print '<p class="eyebrow">Paiement Stancer</p>';
print '<h1>Verification du paiement</h1>';
print '</div>';
lmdbwebsite_public_errors($errors);
if (empty($errors)) {
	print '<div class="notice warning">Le paiement est encore en cours de confirmation. Vous pouvez actualiser cette page dans quelques instants.</div>';
	if (!empty($subscription->stancer_initial_payment_url)) {
		print '<div class="actions"><a class="button" href="'.lmdbwebsite_escape($subscription->stancer_initial_payment_url).'">Reprendre le paiement</a></div>';
	}
} else {
	print '<div class="actions"><a class="button secondary" href="/contact/">Contacter le support</a></div>';
}
print '</section>';
lmdbwebsite_public_footer();
