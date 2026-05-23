<?php
/* Copyright (C) 2026 Pierre Ardoin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/lmdbwebsitesubscription.class.php
 * \ingroup lmdbwebsite
 * \brief   Subscription workflow for LMDB Website.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/contrat/class/contrat.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture-rec.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
dol_include_once('/lmdbwebsite/lib/lmdbwebsite.lib.php');
dol_include_once('/stancer/lib/stancer.lib.php');

/**
 * Subscription object and workflow service.
 */
class LmdbwebsiteSubscription extends CommonObject
{
	const STATUS_DRAFT = 'draft';
	const STATUS_PENDING_PAYMENT = 'pending_payment';
	const STATUS_ACTIVE = 'active';
	const STATUS_PAYMENT_FAILED = 'payment_failed';
	const STATUS_ONBOARDING_PENDING = 'onboarding_pending';
	const STATUS_ONBOARDING_DONE = 'onboarding_done';
	const STATUS_CANCELLED = 'cancelled';

	const RECURRENCE_MANUAL_CARD = 'manual_card';
	const RECURRENCE_CARD_AUTO = 'card_auto';
	const RECURRENCE_SEPA_AUTO = 'sepa_auto';

	public $element = 'lmdbwebsite_subscription';
	public $table_element = 'lmdbwebsite_subscription';
	public $picto = 'globe';
	public $ismultientitymanaged = 1;

	public $ref;
	public $entity;
	public $fk_soc;
	public $fk_contact;
	public $fk_product;
	public $fk_contrat;
	public $fk_facture_initiale;
	public $fk_facture_rec;
	public $fk_last_facture;
	public $product_ref;
	public $offer_code;
	public $frequency;
	public $recurrence_mode;
	public $amount_ht;
	public $amount_ttc;
	public $currency;
	public $stancer_customer_id;
	public $stancer_initial_payment_id;
	public $stancer_initial_payment_url;
	public $stancer_last_payment_id;
	public $stancer_card_id;
	public $stancer_sepa_id;
	public $stancer_mandate_id;
	public $payment_status;
	public $status;
	public $onboarding_token;
	public $onboarding_token_expiry;
	public $onboarding_siret;
	public $onboarding_logo;
	public $next_invoice_date;
	public $last_invoice_date;
	public $ip_hash;
	public $user_agent;
	public $utm_source;
	public $utm_medium;
	public $utm_campaign;
	public $note_private;
	public $fk_user_creat;
	public $fk_user_modif;
	public $datec;
	public $tms;

	private $usesAnnualProduct = false;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Fetch by id, ref, payment id or onboarding token.
	 *
	 * @param int    $id    Row id
	 * @param string $ref   Reference
	 * @param string $field Alternative field
	 * @param string $value Alternative value
	 * @return int
	 */
	public function fetch($id, $ref = '', $field = '', $value = '')
	{
		global $conf;

		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.$this->table_element.' WHERE entity = '.((int) $conf->entity);
		if ($id > 0) {
			$sql .= ' AND rowid = '.((int) $id);
		} elseif ($ref !== '') {
			$sql .= " AND ref = '".$this->db->escape($ref)."'";
		} elseif ($field !== '' && $value !== '') {
			$allowed = array('stancer_initial_payment_id', 'stancer_last_payment_id', 'onboarding_token');
			if (!in_array($field, $allowed, true)) {
				$this->error = 'Bad fetch field';
				return -1;
			}
			$sql .= ' AND '.$field." = '".$this->db->escape($value)."'";
		} else {
			return 0;
		}
		$sql .= ' LIMIT 1';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}
		$this->hydrate($obj);
		return 1;
	}

	/**
	 * Populate the object from a DB row.
	 *
	 * @param object $obj DB row
	 * @return void
	 */
	private function hydrate($obj)
	{
		foreach (get_object_vars($obj) as $key => $value) {
			$this->{$key} = $value;
		}
		$this->id = (int) $obj->rowid;
	}

	/**
	 * Insert the subscription row.
	 *
	 * @param User $user User
	 * @return int
	 */
	public function create(User $user)
	{
		global $conf;

		$now = dol_now();
		if (empty($this->ref)) {
			$this->ref = $this->buildRef();
		}
		$this->entity = $conf->entity;
		$this->datec = $now;
		$this->fk_user_creat = $user->id;

		$fields = $this->dbFields();
		$columns = array();
		$values = array();
		foreach ($fields as $field) {
			if ($field === 'rowid' || $field === 'tms') {
				continue;
			}
			$columns[] = $field;
			$values[] = $this->sqlValue($this->{$field});
		}

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.$this->table_element.' ('.implode(', ', $columns).') VALUES ('.implode(', ', $values).')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		return $this->id;
	}

	/**
	 * Update the subscription row.
	 *
	 * @param User $user User
	 * @return int
	 */
	public function update(User $user)
	{
		if (empty($this->id)) {
			$this->error = 'Missing subscription id';
			return -1;
		}
		$this->fk_user_modif = $user->id;

		$assignments = array();
		foreach ($this->dbFields() as $field) {
			if (in_array($field, array('rowid', 'ref', 'entity', 'datec', 'tms'), true)) {
				continue;
			}
			$assignments[] = $field.' = '.$this->sqlValue($this->{$field});
		}

		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element.' SET '.implode(', ', $assignments);
		$sql .= ' WHERE rowid = '.((int) $this->id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Create a complete public subscription and return the payment URL.
	 *
	 * @param array<string,mixed> $data Public form data
	 * @return array<string,mixed>|int
	 */
	public function createFromPublicRequest($data)
	{
		global $db;

		$techUser = $this->getTechnicalUser();
		$offer = $this->normalizeOffer($data['offer'] ?? '');
		$frequency = $this->normalizeFrequency($data['frequency'] ?? '');
		$recurrenceMode = $this->normalizeRecurrenceMode($data['recurrence_mode'] ?? '');

		$product = $this->fetchProductForOffer($offer, $frequency);
		if ($product->id <= 0) {
			return -1;
		}

		$db->begin();
		$error = 0;

		$soc = $this->createThirdparty($data, $techUser);
		if ($soc->id <= 0) {
			$error++;
		}

		$contact = null;
		if (!$error) {
			$contact = $this->createContact($soc, $data, $techUser);
			if ($contact->id <= 0) {
				$error++;
			}
		}

		$contract = null;
		if (!$error) {
			$contract = $this->createContract($soc, $product, $frequency, $techUser);
			if ($contract->id <= 0) {
				$error++;
			}
		}

		$invoice = null;
		if (!$error) {
			$invoice = $this->createInitialInvoice($soc, $product, $frequency, $recurrenceMode, $contract, $techUser);
			if ($invoice->id <= 0) {
				$error++;
			}
		}

		$invoiceRec = null;
		if (!$error) {
			$invoiceRec = $this->createInvoiceTemplate($invoice, $frequency, $techUser);
			if ($invoiceRec->id <= 0) {
				$error++;
			}
		}

		if ($error) {
			$db->rollback();
			return -1;
		}

		$this->ref = $this->buildRef();
		$this->fk_soc = $soc->id;
		$this->fk_contact = $contact->id;
		$this->fk_product = $product->id;
		$this->fk_contrat = $contract->id;
		$this->fk_facture_initiale = $invoice->id;
		$this->fk_facture_rec = $invoiceRec->id;
		$this->fk_last_facture = $invoice->id;
		$this->product_ref = $product->ref;
		$this->offer_code = $offer;
		$this->frequency = $frequency;
		$this->recurrence_mode = $recurrenceMode;
		$this->amount_ht = $invoice->total_ht;
		$this->amount_ttc = $invoice->total_ttc;
		$this->currency = 'EUR';
		$this->status = self::STATUS_DRAFT;
		$this->payment_status = 'created';
		$this->onboarding_token = bin2hex(random_bytes(32));
		$this->onboarding_token_expiry = $this->db->idate(dol_time_plus_duree(dol_now(), 30, 'd'));
		$this->next_invoice_date = $this->db->idate($this->nextDateForFrequency($frequency));
		$this->last_invoice_date = $this->db->idate(dol_now());
		$this->ip_hash = lmdbwebsite_ip_hash();
		$this->user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
		$this->utm_source = substr((string) ($data['utm_source'] ?? ''), 0, 255);
		$this->utm_medium = substr((string) ($data['utm_medium'] ?? ''), 0, 255);
		$this->utm_campaign = substr((string) ($data['utm_campaign'] ?? ''), 0, 255);
		$this->note_private = 'Subscription started from public website';

		$result = $this->create($techUser);
		if ($result <= 0) {
			$db->rollback();
			return -1;
		}

		$payment = $this->createStancerInitialPayment($invoice, $soc, $recurrenceMode);
		if ($payment === false || empty($payment['url'])) {
			$db->rollback();
			return -1;
		}

		$this->stancer_customer_id = $payment['customer_id'];
		$this->stancer_initial_payment_id = $payment['id'];
		$this->stancer_initial_payment_url = $payment['url'];
		$this->stancer_last_payment_id = $payment['id'];
		$this->status = self::STATUS_PENDING_PAYMENT;
		$this->payment_status = 'pending';
		$this->update($techUser);

		$db->commit();

		return array('subscription' => $this, 'payment_url' => $payment['url']);
	}

	/**
	 * Reconcile one payment after public return.
	 *
	 * @return int
	 */
	public function reconcileInitialPayment()
	{
		if (empty($this->id) || empty($this->stancer_initial_payment_id)) {
			$this->error = 'Missing subscription or payment id';
			return -1;
		}

		$api = StancerApi::getInstance();
		$payment = $api->getPayment($this->stancer_initial_payment_id);
		if ($payment === false) {
			$this->error = $api->error;
			return -1;
		}

		return $this->applyPaymentStatus($payment);
	}

	/**
	 * Apply a Stancer payment status to the subscription.
	 *
	 * @param array<string,mixed> $payment Stancer payment payload
	 * @return int
	 */
	public function applyPaymentStatus($payment)
	{
		global $db;

		$techUser = $this->getTechnicalUser();
		$status = (string) ($payment['status'] ?? '');
		$this->payment_status = $status;
		$this->stancer_last_payment_id = (string) ($payment['id'] ?? $this->stancer_last_payment_id);

		if (!empty($payment['card']) && is_string($payment['card'])) {
			$this->stancer_card_id = $payment['card'];
		} elseif (!empty($payment['payment_method']) && is_array($payment['payment_method']) && !empty($payment['payment_method']['card'])) {
			$this->stancer_card_id = $payment['payment_method']['card'];
		}
		if (!empty($payment['sepa']) && is_string($payment['sepa'])) {
			$this->stancer_sepa_id = $payment['sepa'];
		}
		if (!empty($payment['mandate']) && is_string($payment['mandate'])) {
			$this->stancer_mandate_id = $payment['mandate'];
		}

		if ($status === 'authorized') {
			$api = StancerApi::getInstance();
			$capture = $api->capturePayment($this->stancer_last_payment_id);
			if ($capture !== false) {
				$status = (string) ($capture['status'] ?? 'to_capture');
				$this->payment_status = $status;
			}
		}

		if ($this->isPaidStancerStatus($status)) {
			$db->begin();
			$this->recordDolibarrPayment($this->fk_facture_initiale, $this->stancer_initial_payment_id, $techUser);
			$this->activateContract($techUser);
			$this->status = self::STATUS_ONBOARDING_PENDING;
			$result = $this->update($techUser);
			if ($result > 0) {
				$db->commit();
				return 1;
			}
			$db->rollback();
			return -1;
		}

		if ($this->isFailedStancerStatus($status)) {
			$this->status = self::STATUS_PAYMENT_FAILED;
		}

		return $this->update($techUser);
	}

	/**
	 * Save onboarding data and create an internal task.
	 *
	 * @param string $siret    SIRET
	 * @param array<string,mixed> $file Uploaded logo
	 * @return int
	 */
	public function submitOnboarding($siret, $file)
	{
		global $conf;

		if ($this->status !== self::STATUS_ONBOARDING_PENDING && $this->status !== self::STATUS_ACTIVE) {
			$this->error = 'Subscription is not ready for onboarding';
			return -1;
		}
		if (empty($this->onboarding_token_expiry) || strtotime($this->onboarding_token_expiry) < time()) {
			$this->error = 'Onboarding token expired';
			return -1;
		}

		$techUser = $this->getTechnicalUser();
		$logoPath = $this->storeLogo($file);
		if ($logoPath === false) {
			return -1;
		}

		$soc = new Societe($this->db);
		if ($soc->fetch($this->fk_soc) <= 0) {
			$this->error = 'Thirdparty not found';
			return -1;
		}
		$soc->idprof1 = preg_replace('/\D/', '', $siret);
		$soc->update($this->fk_soc, $techUser);

		$this->onboarding_siret = $soc->idprof1;
		$this->onboarding_logo = $logoPath;
		$this->status = self::STATUS_ONBOARDING_DONE;
		$this->update($techUser);

		$action = new ActionComm($this->db);
		$action->type_code = 'AC_OTH_AUTO';
		$action->code = 'AC_LMDBWEBSITE_ENTITY';
		$action->label = 'Creer l entite Dolibarr - '.$soc->name;
		$action->datep = dol_now();
		$action->datef = dol_now();
		$action->percentage = 0;
		$action->socid = $soc->id;
		$action->authorid = $techUser->id;
		$action->userownerid = $techUser->id;
		$action->note_private = "SIRET: ".$soc->idprof1."\nLogo: ".$logoPath."\nContrat: ".$this->fk_contrat;
		$action->create($techUser);

		$this->status = self::STATUS_ACTIVE;
		return $this->update($techUser);
	}

	/**
	 * Cron: reconcile pending payments.
	 *
	 * @return int
	 */
	public function doReconcilePayments()
	{
		global $conf;

		$error = 0;
		$this->output = '';

		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE status IN ('".$this->db->escape(self::STATUS_PENDING_PAYMENT)."', '".$this->db->escape(self::STATUS_PAYMENT_FAILED)."')";
		$sql .= ' AND entity = '.((int) $conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$subscription = new self($this->db);
			if ($subscription->fetch((int) $obj->rowid) > 0) {
				$result = $subscription->reconcileInitialPayment();
				if ($result < 0) {
					$error++;
					$this->output .= 'Error subscription '.$subscription->ref.': '.$subscription->error."\n";
				}
			}
		}

		return $error ? 1 : 0;
	}

	/**
	 * Cron: create due recurring invoices and trigger payment flow.
	 *
	 * @return int
	 */
	public function doCreateDueInvoices()
	{
		global $conf;

		$error = 0;
		$this->output = '';
		$today = dol_print_date(dol_now(), '%Y-%m-%d');

		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE status = '".$this->db->escape(self::STATUS_ACTIVE)."'";
		$sql .= " AND next_invoice_date IS NOT NULL AND next_invoice_date <= '".$this->db->escape($today)."'";
		$sql .= ' AND entity = '.((int) $conf->entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$subscription = new self($this->db);
			if ($subscription->fetch((int) $obj->rowid) <= 0) {
				continue;
			}
			$result = $subscription->createDueInvoice();
			if ($result < 0) {
				$error++;
				$this->output .= 'Error subscription '.$subscription->ref.': '.$subscription->error."\n";
			}
		}

		return $error ? 1 : 0;
	}

	/**
	 * Create one due invoice and payment.
	 *
	 * @return int
	 */
	public function createDueInvoice()
	{
		$techUser = $this->getTechnicalUser();
		$invoiceRec = new FactureRec($this->db);
		if ($invoiceRec->fetch($this->fk_facture_rec) <= 0) {
			$this->error = 'Recurring invoice template not found';
			return -1;
		}

		$result = $invoiceRec->createRecurringInvoices($this->fk_facture_rec, 1);
		if ($result > 0) {
			$this->error = $invoiceRec->error;
			return -1;
		}

		$invoiceId = $this->findLatestInvoiceFromTemplate($this->fk_facture_rec);
		if ($invoiceId <= 0) {
			$this->error = 'Generated invoice not found';
			return -1;
		}

		$invoice = new Facture($this->db);
		$invoice->fetch($invoiceId);
		$this->fk_last_facture = $invoice->id;
		$this->last_invoice_date = $this->db->idate(dol_now());
		$this->next_invoice_date = $this->db->idate($this->nextDateForFrequency($this->frequency));

		$payment = $this->createStancerRecurringPayment($invoice);
		if ($payment !== false) {
			$this->rememberStancerPayment($payment);
			$this->applyRecurringPaymentStatus($invoice, $payment, $techUser);
		} else {
			$this->payment_status = 'error';
			if ($this->recurrence_mode !== self::RECURRENCE_MANUAL_CARD) {
				$linkPayment = $this->createStancerManualPaymentLink($invoice);
				if ($linkPayment !== false) {
					$this->rememberStancerPayment($linkPayment);
					$this->sendPaymentLink($invoice, (string) ($linkPayment['url'] ?? ''), $techUser);
				}
			}
			$this->createInternalAlert(
				'Echec creation paiement Stancer - '.$this->ref,
				"Facture: ".$invoice->ref."\nErreur: ".$this->error,
				$techUser
			);
		}

		return $this->update($techUser);
	}

	/**
	 * Apply a recurring Stancer payment to a generated invoice.
	 *
	 * @param Facture $invoice Invoice
	 * @param array<string,mixed> $payment Stancer payment payload
	 * @param User $user User
	 * @return int
	 */
	private function applyRecurringPaymentStatus(Facture $invoice, $payment, User $user)
	{
		$status = (string) ($payment['status'] ?? 'created');
		$this->payment_status = $status;

		if ($status === 'authorized') {
			$api = StancerApi::getInstance();
			$capture = $api->capturePayment((string) $payment['id']);
			if ($capture !== false) {
				$status = (string) ($capture['status'] ?? 'to_capture');
				$this->payment_status = $status;
			}
		}

		if ($this->isPaidStancerStatus($status)) {
			return $this->recordDolibarrPayment($invoice->id, (string) ($payment['id'] ?? $this->stancer_last_payment_id), $user);
		}

		if ($this->recurrence_mode === self::RECURRENCE_MANUAL_CARD || $this->isFailedStancerStatus($status)) {
			$linkPayment = empty($payment['url']) ? $this->createStancerManualPaymentLink($invoice) : $payment;
			if ($linkPayment !== false) {
				$this->rememberStancerPayment($linkPayment);
				$this->sendPaymentLink($invoice, (string) ($linkPayment['url'] ?? ''), $user);
			}
			if ($this->isFailedStancerStatus($status)) {
				$this->createInternalAlert(
					'Echec paiement automatique - '.$this->ref,
					"Facture: ".$invoice->ref."\nStatut Stancer: ".$status."\nUn lien de paiement manuel a ete genere si possible.",
					$user
				);
			}
		}

		return 1;
	}

	/**
	 * Create initial Stancer checkout through mapiolca/stancer.
	 *
	 * @param Facture $invoice Invoice
	 * @param Societe $soc     Thirdparty
	 * @param string  $mode    Recurrence mode
	 * @return array<string,mixed>|false
	 */
	private function createStancerInitialPayment(Facture $invoice, Societe $soc, $mode)
	{
		$customerId = '';
		if (function_exists('stancerAddCustomerIfNeeded')) {
			$customerId = stancerAddCustomerIfNeeded($soc);
		}
		if (empty($customerId) || (is_numeric($customerId) && $customerId < 0)) {
			$api = StancerApi::getInstance();
			$customer = $api->createCustomer(array('name' => $soc->name, 'email' => $soc->email));
			if ($customer === false || empty($customer['id'])) {
				$this->error = $api->error;
				return false;
			}
			$customerId = $customer['id'];
		}

		$api = StancerApi::getInstance();
		$data = array(
			'amount' => (int) round($invoice->total_ttc * 100),
			'currency' => 'eur',
			'customer' => $customerId,
			'description' => 'Abonnement '.$this->offer_code.' - facture '.$invoice->ref,
			'order_id' => $invoice->ref,
			'unique_id' => 'facture:'.$invoice->id,
			'return_url' => lmdbwebsite_public_url('return.php', array('sid' => $this->id, 'token' => $this->onboarding_token)),
		);

		if ($mode === self::RECURRENCE_CARD_AUTO) {
			$data['methods_allowed'] = array('card');
			$data['tokenize'] = true;
		} elseif ($mode === self::RECURRENCE_SEPA_AUTO) {
			$data['methods_allowed'] = array('sepa');
		} else {
			$data['methods_allowed'] = array('card');
		}

		$payment = $api->createPayment($data);
		if ($payment === false || empty($payment['id'])) {
			$this->error = $api->error;
			return false;
		}

		$url = '';
		foreach (array('url', 'payment_page_url', 'redirect_url') as $key) {
			if (!empty($payment[$key])) {
				$url = $payment[$key];
				break;
			}
		}
		if (empty($url)) {
			$this->error = 'Missing Stancer payment URL';
			return false;
		}

		return array(
			'id' => $payment['id'],
			'url' => $url,
			'customer_id' => $customerId,
			'status' => $payment['status'] ?? 'created',
		);
	}

	/**
	 * Create a recurring Stancer payment.
	 *
	 * @param Facture $invoice Invoice
	 * @return array<string,mixed>|false
	 */
	private function createStancerRecurringPayment(Facture $invoice)
	{
		$api = StancerApi::getInstance();
		$data = $this->stancerInvoicePayload($invoice);

		if ($this->recurrence_mode === self::RECURRENCE_CARD_AUTO && !empty($this->stancer_card_id)) {
			$data['card'] = $this->stancer_card_id;
		} elseif ($this->recurrence_mode === self::RECURRENCE_SEPA_AUTO && !empty($this->stancer_sepa_id)) {
			$data['sepa'] = $this->stancer_sepa_id;
		} else {
			$data['methods_allowed'] = array('card');
		}

		$payment = $api->createPayment($data);
		if ($payment === false) {
			$this->error = $api->error;
			return false;
		}

		return array(
			'id' => $payment['id'] ?? '',
			'url' => $payment['url'] ?? '',
			'status' => $payment['status'] ?? 'created',
		);
	}

	/**
	 * Create a manual card payment link for an invoice.
	 *
	 * @param Facture $invoice Invoice
	 * @return array<string,mixed>|false
	 */
	private function createStancerManualPaymentLink(Facture $invoice)
	{
		$api = StancerApi::getInstance();
		$data = $this->stancerInvoicePayload($invoice);
		$data['methods_allowed'] = array('card');

		$payment = $api->createPayment($data);
		if ($payment === false) {
			$this->error = $api->error;
			return false;
		}

		return array(
			'id' => $payment['id'] ?? '',
			'url' => $this->extractStancerUrl($payment),
			'status' => $payment['status'] ?? 'created',
		);
	}

	/**
	 * Base Stancer payment payload for an invoice.
	 *
	 * @param Facture $invoice Invoice
	 * @return array<string,mixed>
	 */
	private function stancerInvoicePayload(Facture $invoice)
	{
		return array(
			'amount' => (int) round($invoice->total_ttc * 100),
			'currency' => 'eur',
			'customer' => $this->stancer_customer_id,
			'description' => 'Echeance abonnement '.$this->ref.' - '.$invoice->ref,
			'order_id' => $invoice->ref,
			'unique_id' => 'facture:'.$invoice->id,
			'return_url' => lmdbwebsite_public_url('return.php', array('sid' => $this->id, 'token' => $this->onboarding_token)),
		);
	}

	/**
	 * Store common Stancer identifiers from a payment payload.
	 *
	 * @param array<string,mixed> $payment Stancer payment payload
	 * @return void
	 */
	private function rememberStancerPayment($payment)
	{
		if (!empty($payment['id'])) {
			$this->stancer_last_payment_id = (string) $payment['id'];
		}
		$url = $this->extractStancerUrl($payment);
		if ($url !== '') {
			$this->stancer_initial_payment_url = $url;
		}
		$this->payment_status = (string) ($payment['status'] ?? 'pending');
	}

	/**
	 * Extract a hosted payment URL from Stancer payload.
	 *
	 * @param array<string,mixed> $payment Stancer payment payload
	 * @return string
	 */
	private function extractStancerUrl($payment)
	{
		foreach (array('url', 'payment_page_url', 'redirect_url') as $key) {
			if (!empty($payment[$key])) {
				return (string) $payment[$key];
			}
		}
		return '';
	}

	/**
	 * Check if a Stancer status can close a Dolibarr invoice.
	 *
	 * @param string $status Stancer status
	 * @return bool
	 */
	private function isPaidStancerStatus($status)
	{
		return in_array($status, array('to_capture', 'capture_sent', 'captured', 'paid', 'success', 'succeeded'), true);
	}

	/**
	 * Check if a Stancer status requires manual follow-up.
	 *
	 * @param string $status Stancer status
	 * @return bool
	 */
	private function isFailedStancerStatus($status)
	{
		return in_array($status, array('failed', 'refused', 'expired', 'canceled', 'cancelled'), true);
	}

	/**
	 * Create thirdparty.
	 *
	 * @param array<string,mixed> $data Form data
	 * @param User $user User
	 * @return Societe
	 */
	private function createThirdparty($data, User $user)
	{
		$soc = new Societe($this->db);
		$soc->name = trim((string) ($data['company_name'] ?? ''));
		if ($soc->name === '') {
			$soc->name = trim((string) ($data['firstname'] ?? '').' '.(string) ($data['lastname'] ?? ''));
		}
		$soc->client = 1;
		$soc->email = trim((string) ($data['email'] ?? ''));
		$soc->phone = trim((string) ($data['phone'] ?? ''));
		$soc->address = trim((string) ($data['address'] ?? ''));
		$soc->zip = trim((string) ($data['zip'] ?? ''));
		$soc->town = trim((string) ($data['town'] ?? ''));
		$soc->country_id = dol_getIdFromCode($this->db, 'FR', 'c_country', 'code', 'rowid');
		$soc->note_private = 'Created by LMDB Website subscription funnel.';
		$result = $soc->create($user);
		if ($result <= 0) {
			$this->error = $soc->error;
		}
		return $soc;
	}

	/**
	 * Create contact.
	 *
	 * @param Societe $soc  Thirdparty
	 * @param array<string,mixed> $data Form data
	 * @param User $user User
	 * @return Contact
	 */
	private function createContact(Societe $soc, $data, User $user)
	{
		$contact = new Contact($this->db);
		$contact->socid = $soc->id;
		$contact->firstname = trim((string) ($data['firstname'] ?? ''));
		$contact->lastname = trim((string) ($data['lastname'] ?? 'Client'));
		$contact->email = trim((string) ($data['email'] ?? ''));
		$contact->phone_pro = trim((string) ($data['phone'] ?? ''));
		$contact->statut = 1;
		$result = $contact->create($user);
		if ($result <= 0) {
			$this->error = $contact->error;
		}
		return $contact;
	}

	/**
	 * Create contract draft.
	 *
	 * @param Societe $soc Thirdparty
	 * @param Product $product Product
	 * @param string $frequency Frequency
	 * @param User $user User
	 * @return Contrat
	 */
	private function createContract(Societe $soc, Product $product, $frequency, User $user)
	{
		$contract = new Contrat($this->db);
		$contract->socid = $soc->id;
		$contract->date_contrat = dol_now();
		$contract->note_private = 'Created from LMDB Website subscription funnel.';
		$result = $contract->create($user);
		if ($result <= 0) {
			$this->error = $contract->error;
			return $contract;
		}
		$durationEnd = $this->nextDateForFrequency($frequency);
		$lineResult = $contract->addline(
			$product->description ?: $product->label,
			$product->price,
			$this->productQuantityForFrequency($frequency),
			$product->tva_tx,
			0,
			0,
			$product->id,
			0,
			dol_now(),
			$durationEnd,
			'HT'
		);
		if ($lineResult < 0) {
			$this->error = $contract->error;
		}
		return $contract;
	}

	/**
	 * Create and validate the first invoice.
	 *
	 * @param Societe $soc Thirdparty
	 * @param Product $product Product
	 * @param string $frequency Frequency
	 * @param string $mode Recurrence mode
	 * @param Contrat $contract Contract
	 * @param User $user User
	 * @return Facture
	 */
	private function createInitialInvoice(Societe $soc, Product $product, $frequency, $mode, Contrat $contract, User $user)
	{
		$invoice = new Facture($this->db);
		$invoice->socid = $soc->id;
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->date = dol_now();
		$invoice->note_private = 'Initial invoice from LMDB Website subscription funnel.';
		$paymentCode = $mode === self::RECURRENCE_SEPA_AUTO ? 'PRE' : 'CB';
		$invoice->mode_reglement_id = dol_getIdFromCode($this->db, $paymentCode, 'c_paiement', 'code', 'id', 1);
		$result = $invoice->create($user);
		if ($result <= 0) {
			$this->error = $invoice->error;
			return $invoice;
		}
		$invoice->fetch_thirdparty();
		$qty = $this->productQuantityForFrequency($frequency);
		$lineResult = $invoice->addline(
			$product->description ?: $product->label,
			$product->price,
			$qty,
			$product->tva_tx,
			0,
			0,
			$product->id,
			0,
			dol_now(),
			$this->nextDateForFrequency($frequency),
			0,
			0,
			0,
			'HT',
			0,
			1
		);
		if ($lineResult < 0) {
			$this->error = $invoice->error;
			return $invoice;
		}
		$invoice->add_object_linked('contrat', $contract->id);
		$validate = $invoice->validate($user);
		if ($validate <= 0) {
			$this->error = $invoice->error;
		}
		return $invoice;
	}

	/**
	 * Create recurring invoice template from first invoice.
	 *
	 * @param Facture $invoice Invoice
	 * @param string $frequency Frequency
	 * @param User $user User
	 * @return FactureRec
	 */
	private function createInvoiceTemplate(Facture $invoice, $frequency, User $user)
	{
		$rec = new FactureRec($this->db);
		$rec->title = 'Abonnement '.$invoice->ref;
		$rec->titre = $rec->title;
		$rec->frequency = 1;
		$rec->unit_frequency = $frequency === 'annual' ? 'y' : 'm';
		$rec->date_when = $this->nextDateForFrequency($frequency);
		$rec->auto_validate = 1;
		$rec->generate_pdf = 1;
		$rec->cond_reglement_id = $invoice->cond_reglement_id;
		$rec->mode_reglement_id = $invoice->mode_reglement_id;
		$result = $rec->create($user, $invoice->id);
		if ($result <= 0) {
			$this->error = $rec->error;
		} else {
			$rec->id = $result;
		}
		return $rec;
	}

	/**
	 * Activate contract after first payment.
	 *
	 * @param User $user User
	 * @return int
	 */
	private function activateContract(User $user)
	{
		$contract = new Contrat($this->db);
		if ($contract->fetch($this->fk_contrat) <= 0) {
			return -1;
		}
		if ((int) $contract->status === Contrat::STATUS_DRAFT) {
			$contract->validate($user);
		}
		return $contract->activateAll($user, dol_now(), 0, 'Activated by paid LMDB Website subscription', $this->nextDateForFrequency($this->frequency));
	}

	/**
	 * Record payment in Dolibarr and close invoice.
	 *
	 * @param int $invoiceId Invoice id
	 * @param string $paymentRef Stancer payment id
	 * @param User $user User
	 * @return int
	 */
	private function recordDolibarrPayment($invoiceId, $paymentRef, User $user)
	{
		$invoice = new Facture($this->db);
		if ($invoice->fetch($invoiceId) <= 0) {
			return -1;
		}
		if (!empty($invoice->paye)) {
			return 1;
		}

		$payment = new Paiement($this->db);
		$payment->datepaye = dol_now();
		$payment->amounts = array($invoice->id => $invoice->total_ttc);
		$paymentCode = $this->recurrence_mode === self::RECURRENCE_SEPA_AUTO ? 'PRE' : 'CB';
		$payment->paiementid = dol_getIdFromCode($this->db, $paymentCode, 'c_paiement', 'code', 'id', 1);
		$payment->num_paiement = $paymentRef;
		$result = $payment->create($user, 1);
		if ($result > 0 && (int) lmdbwebsite_get_conf('BANK_ACCOUNT_ID', 0) > 0) {
			$payment->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', (int) lmdbwebsite_get_conf('BANK_ACCOUNT_ID', 0), '', '');
		}

		return $invoice->setPaid($user);
	}

	/**
	 * Send a manual payment link to the customer when mail is configured.
	 *
	 * @param Facture $invoice Invoice
	 * @param string $url Payment URL
	 * @param User $user User
	 * @return int
	 */
	private function sendPaymentLink(Facture $invoice, $url, User $user)
	{
		if ($url === '') {
			return 0;
		}

		$soc = new Societe($this->db);
		if ($soc->fetch($this->fk_soc) <= 0 || empty($soc->email)) {
			$this->createInternalAlert(
				'Lien paiement a envoyer - '.$this->ref,
				"Facture: ".$invoice->ref."\nLien: ".$url."\nAucun email tiers disponible.",
				$user
			);
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', getDolGlobalString('MAIN_INFO_SOCIETE_MAIL', ''));
		if ($from === '') {
			$this->createInternalAlert(
				'Lien paiement a envoyer - '.$this->ref,
				"Facture: ".$invoice->ref."\nDestinataire: ".$soc->email."\nLien: ".$url."\nEmail expediteur Dolibarr non configure.",
				$user
			);
			return 0;
		}

		$subject = 'Votre facture Les Metiers du Batiment '.$invoice->ref;
		$message = "Bonjour,\n\nVotre facture ".$invoice->ref." est disponible. Vous pouvez la regler par carte depuis ce lien securise Stancer :\n".$url."\n\nMerci.";
		$mail = new CMailFile($subject, $soc->email, $from, $message);
		$result = $mail->sendfile();
		if (!$result) {
			$this->createInternalAlert(
				'Echec envoi lien paiement - '.$this->ref,
				"Facture: ".$invoice->ref."\nDestinataire: ".$soc->email."\nLien: ".$url."\nErreur: ".$mail->error,
				$user
			);
			return -1;
		}

		return 1;
	}

	/**
	 * Create an internal action for follow-up.
	 *
	 * @param string $label Label
	 * @param string $note Private note
	 * @param User $user User
	 * @return int
	 */
	private function createInternalAlert($label, $note, User $user)
	{
		$action = new ActionComm($this->db);
		$action->type_code = 'AC_OTH_AUTO';
		$action->code = 'AC_LMDBWEBSITE_ALERT';
		$action->label = $label;
		$action->datep = dol_now();
		$action->datef = dol_now();
		$action->percentage = 0;
		$action->socid = $this->fk_soc;
		$action->authorid = $user->id;
		$action->userownerid = $user->id;
		$action->note_private = $note;
		return $action->create($user);
	}

	/**
	 * Store the onboarding logo.
	 *
	 * @param array<string,mixed> $file Uploaded file
	 * @return string|false
	 */
	private function storeLogo($file)
	{
		global $conf;

		if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
			$this->error = 'Logo file is required';
			return false;
		}
		if ((int) $file['size'] > 2 * 1024 * 1024) {
			$this->error = 'Logo file is too large';
			return false;
		}
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->file($file['tmp_name']);
		$extensions = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp');
		if (empty($extensions[$mime])) {
			$this->error = 'Logo file type is not allowed';
			return false;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$dir = $conf->lmdbwebsite->dir_output.'/onboarding/'.$this->ref;
		dol_mkdir($dir);
		$filename = 'logo.'.$extensions[$mime];
		$dest = $dir.'/'.$filename;
		if (!dol_move_uploaded_file($file['tmp_name'], $dest, 1, 0, $file['error'])) {
			$this->error = 'Unable to store logo';
			return false;
		}

		return $dest;
	}

	/**
	 * Find latest generated invoice from a template.
	 *
	 * @param int $templateId Template id
	 * @return int
	 */
	private function findLatestInvoiceFromTemplate($templateId)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'facture';
		$sql .= ' WHERE fk_fac_rec_source = '.((int) $templateId);
		$sql .= ' ORDER BY rowid DESC LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Fetch product for offer and frequency.
	 *
	 * @param string $offer Offer code
	 * @param string $frequency Frequency
	 * @return Product
	 */
	private function fetchProductForOffer($offer, $frequency)
	{
		$key = 'PRODUCT_REF_'.strtoupper($offer);
		$this->usesAnnualProduct = false;
		if ($frequency === 'annual') {
			$annualRef = lmdbwebsite_get_conf($key.'_ANNUAL', '');
			if ($annualRef !== '') {
				$key .= '_ANNUAL';
				$this->usesAnnualProduct = true;
			}
		}
		$ref = lmdbwebsite_get_conf($key, '');
		$product = new Product($this->db);
		$product->fetch(0, $ref);
		$this->product_ref = $ref;
		if ($product->id <= 0) {
			$this->error = 'Product not found for ref '.$ref;
		}
		return $product;
	}

	/**
	 * Get technical user.
	 *
	 * @return User
	 */
	private function getTechnicalUser()
	{
		$techUser = new User($this->db);
		$id = (int) lmdbwebsite_get_conf('USER_ID', 1);
		if ($techUser->fetch($id) <= 0) {
			global $user;
			return $user;
		}
		return $techUser;
	}

	/**
	 * Product quantity for the selected frequency.
	 *
	 * @param string $frequency Frequency
	 * @return int
	 */
	private function productQuantityForFrequency($frequency)
	{
		return ($frequency === 'annual' && !$this->usesAnnualProduct) ? 12 : 1;
	}

	/**
	 * Next invoice date for frequency.
	 *
	 * @param string $frequency Frequency
	 * @return int
	 */
	private function nextDateForFrequency($frequency)
	{
		return dol_time_plus_duree(dol_now(), 1, $frequency === 'annual' ? 'y' : 'm');
	}

	/**
	 * Build subscription reference.
	 *
	 * @return string
	 */
	private function buildRef()
	{
		return 'LMDB-'.date('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
	}

	/**
	 * Normalize offer.
	 *
	 * @param string $offer Offer
	 * @return string
	 */
	private function normalizeOffer($offer)
	{
		$offer = strtolower(trim($offer));
		if (!in_array($offer, array('base', 'standard', 'pro'), true)) {
			$this->error = 'Invalid offer';
			return 'base';
		}
		return $offer;
	}

	/**
	 * Normalize frequency.
	 *
	 * @param string $frequency Frequency
	 * @return string
	 */
	private function normalizeFrequency($frequency)
	{
		$frequency = strtolower(trim($frequency));
		return $frequency === 'annual' ? 'annual' : 'monthly';
	}

	/**
	 * Normalize recurrence mode.
	 *
	 * @param string $mode Recurrence mode
	 * @return string
	 */
	private function normalizeRecurrenceMode($mode)
	{
		$mode = strtolower(trim($mode));
		if (in_array($mode, array(self::RECURRENCE_MANUAL_CARD, self::RECURRENCE_CARD_AUTO, self::RECURRENCE_SEPA_AUTO), true)) {
			return $mode;
		}
		return self::RECURRENCE_MANUAL_CARD;
	}

	/**
	 * SQL fields.
	 *
	 * @return array<int,string>
	 */
	private function dbFields()
	{
		return array(
			'rowid', 'ref', 'entity', 'fk_soc', 'fk_contact', 'fk_product', 'fk_contrat',
			'fk_facture_initiale', 'fk_facture_rec', 'fk_last_facture', 'product_ref',
			'offer_code', 'frequency', 'recurrence_mode', 'amount_ht', 'amount_ttc',
			'currency', 'stancer_customer_id', 'stancer_initial_payment_id',
			'stancer_initial_payment_url', 'stancer_last_payment_id', 'stancer_card_id',
			'stancer_sepa_id', 'stancer_mandate_id', 'payment_status', 'status',
			'onboarding_token', 'onboarding_token_expiry', 'onboarding_siret',
			'onboarding_logo', 'next_invoice_date', 'last_invoice_date', 'ip_hash',
			'user_agent', 'utm_source', 'utm_medium', 'utm_campaign', 'note_private',
			'fk_user_creat', 'fk_user_modif', 'datec', 'tms'
		);
	}

	/**
	 * SQL value formatter.
	 *
	 * @param mixed $value Value
	 * @return string
	 */
	private function sqlValue($value)
	{
		if ($value === null || $value === '') {
			return 'NULL';
		}
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}
		return "'".$this->db->escape((string) $value)."'";
	}
}
