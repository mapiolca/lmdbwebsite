<?php
define('NOLOGIN', '1');
define('NOCSRFCHECK', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');

require '../../../main.inc.php';

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once __DIR__.'/common.php';

global $conf, $db;

$errors = array();
$success = false;
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';

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
	'message' => '',
);

if ($posted) {
	foreach ($values as $key => $default) {
		$values[$key] = lmdbwebsite_public_clean($_POST[$key] ?? '');
	}
	$values['message'] = trim(strip_tags((string) ($_POST['message'] ?? '')));
	$values['siret'] = preg_replace('/\D/', '', $values['siret']);

	if (!lmdbwebsite_check_csrf_token((string) ($_POST['token'] ?? ''))) {
		$errors[] = 'La session a expire, merci de reessayer.';
	}
	if (!lmdbwebsite_rate_limit('contact', 4, 900)) {
		$errors[] = 'Trop de tentatives. Merci de reessayer dans quelques minutes.';
	}
	if (lmdbwebsite_public_clean($_POST['website'] ?? '') !== '') {
		$errors[] = 'La demande ne peut pas etre envoyee.';
	}
	if ($values['company_name'] === '') {
		$errors[] = 'Le nom de l entreprise est requis.';
	}
	if ($values['firstname'] === '' || $values['lastname'] === '') {
		$errors[] = 'Le prenom et le nom sont requis.';
	}
	if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
		$errors[] = 'L email est invalide.';
	}
	if ($values['phone'] === '') {
		$errors[] = 'Le telephone est requis.';
	}
	if ($values['message'] === '') {
		$errors[] = 'Le message est requis.';
	}
	if ($values['siret'] !== '' && strlen($values['siret']) !== 14) {
		$errors[] = 'Le SIRET doit contenir 14 chiffres.';
	}
	if (empty($_POST['accept_privacy'])) {
		$errors[] = 'Vous devez accepter le traitement de votre demande.';
	}

	if (empty($errors)) {
		$techUser = lmdbwebsite_contact_get_technical_user($db);
		if (!is_object($techUser) || empty($techUser->id)) {
			$errors[] = 'Utilisateur technique non configure.';
		} else {
			$db->begin();
			$soc = lmdbwebsite_contact_find_or_create_thirdparty($db, $values, $techUser);
			if (!is_object($soc) || empty($soc->id)) {
				$errors[] = 'Impossible de creer le tiers prospect.';
			}
			$contact = null;
			if (empty($errors)) {
				$contact = lmdbwebsite_contact_find_or_create_contact($db, $soc, $values, $techUser);
				if (!is_object($contact) || empty($contact->id)) {
					$errors[] = 'Impossible de creer le contact.';
				}
			}
			if (empty($errors)) {
				$result = lmdbwebsite_contact_create_action($db, $soc, $contact, $values, $techUser);
				if ($result <= 0) {
					$errors[] = 'Impossible de creer la demande interne.';
				}
			}
			if (empty($errors)) {
				$db->commit();
				$success = true;
				foreach ($values as $key => $default) {
					$values[$key] = '';
				}
			} else {
				$db->rollback();
			}
		}
	}
}

lmdbwebsite_public_header('Contact', 'Contactez Les Metiers du Batiment depuis un formulaire Dolibarr securise.');
print '<section class="panel compact-panel">';
print '<div class="panel-header">';
print '<p class="eyebrow">Demande de contact</p>';
print '<h1>Nous contacter</h1>';
print '<p>Decrivez votre besoin. La demande sera creee dans Dolibarr pour etre traitee proprement.</p>';
print '</div>';

if ($success) {
	print '<div class="notice success">Votre demande a bien ete envoyee. Nous revenons vers vous rapidement.</div>';
}

lmdbwebsite_public_errors($errors);

print '<form method="post" action="'.lmdbwebsite_escape($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.lmdbwebsite_escape(lmdbwebsite_csrf_token()).'">';
print '<label class="hidden-field">Site web<input type="text" name="website" autocomplete="off"></label>';
print '<div class="form-grid">';
print '<label>Entreprise<input required type="text" name="company_name" value="'.lmdbwebsite_escape($values['company_name']).'"></label>';
print '<label>Email<input required type="email" name="email" value="'.lmdbwebsite_escape($values['email']).'"></label>';
print '<label>Prenom<input required type="text" name="firstname" value="'.lmdbwebsite_escape($values['firstname']).'"></label>';
print '<label>Nom<input required type="text" name="lastname" value="'.lmdbwebsite_escape($values['lastname']).'"></label>';
print '<label>Telephone<input required type="tel" name="phone" value="'.lmdbwebsite_escape($values['phone']).'"></label>';
print '<label>SIRET<input type="text" name="siret" inputmode="numeric" pattern="[0-9 ]{14,17}" autocomplete="off" value="'.lmdbwebsite_escape($values['siret']).'"></label>';
print '<label class="full">Adresse<input type="text" name="address" value="'.lmdbwebsite_escape($values['address']).'"></label>';
print '<label>Code postal<input type="text" name="zip" value="'.lmdbwebsite_escape($values['zip']).'"></label>';
print '<label>Ville<input type="text" name="town" value="'.lmdbwebsite_escape($values['town']).'"></label>';
print '<label class="full">Message<textarea required name="message">'.lmdbwebsite_escape($values['message']).'</textarea></label>';
print '</div>';
print '<label class="checkbox"><input required type="checkbox" name="accept_privacy" value="1"><span>J accepte que ces informations soient utilisees pour traiter ma demande.</span></label>';
print '<div class="actions"><button type="submit">Envoyer la demande</button></div>';
print '</form>';
print '</section>';
lmdbwebsite_public_footer();

/**
 * Return the configured technical user.
 *
 * @param DoliDB $db Database handler
 * @return User|null
 */
function lmdbwebsite_contact_get_technical_user($db)
{
	$techUser = new User($db);
	$id = (int) lmdbwebsite_get_conf('USER_ID', 1);
	if ($id > 0 && $techUser->fetch($id) > 0) {
		return $techUser;
	}

	return null;
}

/**
 * Find or create a prospect thirdparty.
 *
 * @param DoliDB $db Database handler
 * @param array<string,string> $data Form data
 * @param User $user User
 * @return Societe|null
 */
function lmdbwebsite_contact_find_or_create_thirdparty($db, $data, User $user)
{
	global $conf;

	$socid = lmdbwebsite_contact_find_thirdparty_id($db, $data);
	$soc = new Societe($db);
	if ($socid > 0 && $soc->fetch($socid) > 0) {
		$changed = false;
		if (empty($soc->email) && !empty($data['email'])) {
			$soc->email = $data['email'];
			$changed = true;
		}
		if (empty($soc->phone) && !empty($data['phone'])) {
			$soc->phone = $data['phone'];
			$changed = true;
		}
		if (empty($soc->idprof1) && !empty($data['siret'])) {
			$soc->idprof1 = $data['siret'];
			$changed = true;
		}
		if ($changed) {
			$soc->update($soc->id, $user);
		}
		return $soc;
	}

	$soc->name = $data['company_name'];
	$soc->client = 2;
	$soc->fournisseur = 0;
	$soc->status = 1;
	$soc->email = $data['email'];
	$soc->phone = $data['phone'];
	$soc->idprof1 = $data['siret'];
	$soc->address = $data['address'];
	$soc->zip = $data['zip'];
	$soc->town = $data['town'];
	$soc->country_id = dol_getIdFromCode($db, 'FR', 'c_country', 'code', 'rowid');
	$soc->note_private = 'Created by LMDB Website contact form.';
	if (isset($conf->entity)) {
		$soc->entity = (int) $conf->entity;
	}
	$result = $soc->create($user);
	return $result > 0 ? $soc : null;
}

/**
 * Find an existing thirdparty id.
 *
 * @param DoliDB $db Database handler
 * @param array<string,string> $data Form data
 * @return int
 */
function lmdbwebsite_contact_find_thirdparty_id($db, $data)
{
	global $conf;

	$entities = array((int) $conf->entity);
	if (function_exists('getEntity')) {
		$entityList = getEntity('societe');
		if (is_array($entityList)) {
			$entities = array_map('intval', $entityList);
		} else {
			$entities = array_map('intval', explode(',', (string) $entityList));
		}
	}
	$entities = array_values(array_unique(array_filter($entities)));
	if (empty($entities)) {
		$entities = array((int) $conf->entity);
	}

	$where = array();
	if (!empty($data['siret'])) {
		$where[] = "idprof1 = '".$db->escape($data['siret'])."'";
	}
	if (!empty($data['email'])) {
		$where[] = "email = '".$db->escape($data['email'])."'";
	}
	if (empty($where)) {
		return 0;
	}

	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'societe';
	$sql .= ' WHERE entity IN ('.implode(',', array_map('intval', $entities)).')';
	$sql .= ' AND ('.implode(' OR ', $where).')';
	$sql .= ' ORDER BY rowid DESC';
	$resql = $db->query($sql);
	if (!$resql) {
		return 0;
	}
	$obj = $db->fetch_object($resql);
	return $obj ? (int) $obj->rowid : 0;
}

/**
 * Find or create a contact for the thirdparty.
 *
 * @param DoliDB $db Database handler
 * @param Societe $soc Thirdparty
 * @param array<string,string> $data Form data
 * @param User $user User
 * @return Contact|null
 */
function lmdbwebsite_contact_find_or_create_contact($db, Societe $soc, $data, User $user)
{
	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'socpeople';
	$sql .= ' WHERE fk_soc = '.((int) $soc->id);
	$sql .= " AND email = '".$db->escape($data['email'])."'";
	$sql .= ' ORDER BY rowid DESC';
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj) {
			$contact = new Contact($db);
			if ($contact->fetch((int) $obj->rowid) > 0) {
				return $contact;
			}
		}
	}

	$contact = new Contact($db);
	$contact->socid = $soc->id;
	$contact->firstname = $data['firstname'];
	$contact->lastname = $data['lastname'];
	$contact->email = $data['email'];
	$contact->phone_pro = $data['phone'];
	$contact->statut = 1;
	$result = $contact->create($user);
	return $result > 0 ? $contact : null;
}

/**
 * Create an internal action for the contact request.
 *
 * @param DoliDB $db Database handler
 * @param Societe $soc Thirdparty
 * @param Contact|null $contact Contact
 * @param array<string,string> $data Form data
 * @param User $user User
 * @return int
 */
function lmdbwebsite_contact_create_action($db, Societe $soc, $contact, $data, User $user)
{
	$note = "Demande envoyee depuis le site public.\n\n";
	$note .= 'Entreprise: '.$data['company_name']."\n";
	$note .= 'Contact: '.$data['firstname'].' '.$data['lastname']."\n";
	$note .= 'Email: '.$data['email']."\n";
	$note .= 'Telephone: '.$data['phone']."\n";
	if (!empty($data['siret'])) {
		$note .= 'SIRET: '.$data['siret']."\n";
	}
	if (!empty($data['address']) || !empty($data['zip']) || !empty($data['town'])) {
		$note .= 'Adresse: '.trim($data['address'].' '.$data['zip'].' '.$data['town'])."\n";
	}
	$note .= "\nMessage:\n".$data['message'];

	$action = new ActionComm($db);
	$action->type_code = 'AC_OTH_AUTO';
	$action->code = 'AC_LMDBWEBSITE_CONTACT';
	$action->label = 'Demande de contact site web';
	$action->datep = dol_now();
	$action->datef = dol_now();
	$action->percentage = 0;
	$action->socid = $soc->id;
	if (is_object($contact) && !empty($contact->id)) {
		$action->contactid = $contact->id;
	}
	$action->authorid = $user->id;
	$action->userownerid = $user->id;
	$action->note_private = $note;
	return $action->create($user);
}
