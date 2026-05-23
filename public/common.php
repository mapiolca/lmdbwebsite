<?php
/**
 * Public rendering helpers for LMDB Website.
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

dol_include_once('/lmdbwebsite/lib/lmdbwebsite.lib.php');

/**
 * Clean a public string value.
 *
 * @param mixed $value Value
 * @return string
 */
function lmdbwebsite_public_clean($value)
{
	return trim(strip_tags((string) $value));
}

/**
 * Load site content from the module.
 *
 * @return array<string,mixed>
 */
function lmdbwebsite_public_content()
{
	static $content = null;
	if ($content !== null) {
		return $content;
	}

	$path = dol_buildpath('/lmdbwebsite/content/site.json', 0);
	if (is_readable($path)) {
		$decoded = json_decode((string) file_get_contents($path), true);
		if (is_array($decoded)) {
			$content = $decoded;
			return $content;
		}
	}

	$content = array(
		'site' => array('name' => 'Les Metiers du Batiment', 'email' => 'contact@lesmetiersdubatiment.fr'),
		'offers' => array(
			array('id' => 'base', 'name' => 'Base', 'monthly' => 12, 'annual' => 108, 'summary' => 'ERP/CRM batiment simple.'),
			array('id' => 'standard', 'name' => 'Standard', 'monthly' => 39, 'annual' => 348, 'summary' => 'ERP/CRM pour equipes.'),
			array('id' => 'pro', 'name' => 'Pro', 'monthly' => 129, 'annual' => 1188, 'summary' => 'ERP/CRM avance.'),
		),
	);
	return $content;
}

/**
 * Return all public offers.
 *
 * @return array<int,array<string,mixed>>
 */
function lmdbwebsite_public_offers()
{
	$content = lmdbwebsite_public_content();
	return empty($content['offers']) || !is_array($content['offers']) ? array() : $content['offers'];
}

/**
 * Return one offer.
 *
 * @param string $offerId Offer id
 * @return array<string,mixed>
 */
function lmdbwebsite_public_offer($offerId)
{
	foreach (lmdbwebsite_public_offers() as $offer) {
		if (!empty($offer['id']) && $offer['id'] === $offerId) {
			return $offer;
		}
	}
	$offers = lmdbwebsite_public_offers();
	return empty($offers[0]) ? array() : $offers[0];
}

/**
 * Render public page start.
 *
 * @param string $title Page title
 * @param string $description Description
 * @return void
 */
function lmdbwebsite_public_header($title, $description = '')
{
	$content = lmdbwebsite_public_content();
	$name = empty($content['site']['name']) ? 'Les Metiers du Batiment' : $content['site']['name'];
	header('Content-Type: text/html; charset=UTF-8');
	print '<!doctype html><html lang="fr"><head><meta charset="utf-8">';
	print '<meta name="viewport" content="width=device-width, initial-scale=1">';
	print '<title>'.lmdbwebsite_escape($title).' | '.lmdbwebsite_escape($name).'</title>';
	if ($description !== '') {
		print '<meta name="description" content="'.lmdbwebsite_escape($description).'">';
	}
	print '<meta name="robots" content="noindex,follow">';
	print '<link rel="stylesheet" href="'.dol_buildpath('/lmdbwebsite/public/assets/public.css', 1).'">';
	print '</head><body><main class="lmdb-public"><a class="brand" href="/">LMDB</a>';
}

/**
 * Render public page end.
 *
 * @return void
 */
function lmdbwebsite_public_footer()
{
	print '</main></body></html>';
}

/**
 * Render errors.
 *
 * @param array<int,string> $errors Errors
 * @return void
 */
function lmdbwebsite_public_errors($errors)
{
	if (empty($errors)) {
		return;
	}
	print '<div class="notice error"><ul>';
	foreach ($errors as $error) {
		print '<li>'.lmdbwebsite_escape($error).'</li>';
	}
	print '</ul></div>';
}

/**
 * Render a selected attribute.
 *
 * @param string $value Current value
 * @param string $selected Selected value
 * @return string
 */
function lmdbwebsite_public_selected($value, $selected)
{
	return $value === $selected ? ' selected' : '';
}

/**
 * Return frequency label.
 *
 * @param string $frequency Frequency
 * @return string
 */
function lmdbwebsite_public_frequency_label($frequency)
{
	return $frequency === 'annual' ? 'annuelle' : 'mensuelle';
}

/**
 * Return recurrence label.
 *
 * @param string $mode Recurrence mode
 * @return string
 */
function lmdbwebsite_public_recurrence_label($mode)
{
	$labels = array(
		'manual_card' => 'Lien de paiement carte a chaque facture',
		'card_auto' => 'Carte automatique',
		'sepa_auto' => 'Prelevement SEPA automatique',
	);
	return empty($labels[$mode]) ? $labels['manual_card'] : $labels[$mode];
}
