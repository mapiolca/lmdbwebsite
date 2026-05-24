<?php
/* Copyright (C) 2026 Pierre Ardoin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modLmdbwebsite.class.php
 * \ingroup lmdbwebsite
 * \brief   Module descriptor.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module LMDB Website.
 */
class modLmdbwebsite extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 471060;
		$this->rights_class = 'lmdbwebsite';
		$this->family = 'interface';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleLmdbwebsiteDesc';
		$this->descriptionlong = 'ModuleLmdbwebsiteDesc';
		$this->editor_name = 'Les Metiers du Batiment';
		$this->editor_url = 'https://lesmetiersdubatiment.fr';
		$this->version = '0.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'globe';
		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'models' => 0,
			'hooks' => array(),
			'moduleforexternal' => 1,
		);
		$this->dirs = array('/lmdbwebsite/temp', '/lmdbwebsite/onboarding');
		$this->config_page_url = array('setup.php@lmdbwebsite');
		$this->hidden = false;
		$this->depends = array(
			'always1' => 'modSociete',
			'always2' => 'modProduct',
			'always3' => 'modContrat',
			'always4' => 'modFacture',
			'always5' => 'modStancer',
			'always6' => 'modWebsite',
		);
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('lmdbwebsite@lmdbwebsite');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(15, -3);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();
		$this->const = array(
			1 => array('LMDBWEBSITE_SITE_URL', 'chaine', 'https://lesmetiersdubatiment.fr', 'Public website URL', 1, 'current', 0),
			2 => array('LMDBWEBSITE_DOLIBARR_URL', 'chaine', '', 'Public Dolibarr URL for module endpoints', 1, 'current', 0),
			3 => array('LMDBWEBSITE_CONTACT_DOLIBARR_URL', 'chaine', '', 'Public Dolibarr URL for contact endpoint', 1, 'current', 0),
			4 => array('LMDBWEBSITE_PRODUCT_REF_BASE', 'chaine', 'LMDB-BASE', 'Base offer product ref', 1, 'current', 0),
			5 => array('LMDBWEBSITE_PRODUCT_REF_STANDARD', 'chaine', 'LMDB-STANDARD', 'Standard offer product ref', 1, 'current', 0),
			6 => array('LMDBWEBSITE_PRODUCT_REF_PRO', 'chaine', 'LMDB-PRO', 'Pro offer product ref', 1, 'current', 0),
			7 => array('LMDBWEBSITE_PRODUCT_REF_BASE_ANNUAL', 'chaine', '', 'Optional annual Base offer product ref', 1, 'current', 0),
			8 => array('LMDBWEBSITE_PRODUCT_REF_STANDARD_ANNUAL', 'chaine', '', 'Optional annual Standard offer product ref', 1, 'current', 0),
			9 => array('LMDBWEBSITE_PRODUCT_REF_PRO_ANNUAL', 'chaine', '', 'Optional annual Pro offer product ref', 1, 'current', 0),
			10 => array('LMDBWEBSITE_USER_ID', 'chaine', '1', 'Technical user id', 1, 'current', 0),
			11 => array('LMDBWEBSITE_BANK_ACCOUNT_ID', 'chaine', '', 'Bank account for payments', 1, 'current', 0),
		);

		if (!isset($conf->lmdbwebsite) || !isset($conf->lmdbwebsite->enabled)) {
			$conf->lmdbwebsite = new stdClass();
			$conf->lmdbwebsite->enabled = 0;
		}

		$arraydate = dol_getdate(dol_now());
		$datestart = dol_mktime(2, rand(0, 59), 0, $arraydate['mon'], $arraydate['mday'], $arraydate['year']);
		$this->cronjobs = array(
			0 => array(
				'label' => 'LmdbwebsiteReconcilePayments',
				'jobtype' => 'method',
				'class' => '/lmdbwebsite/class/lmdbwebsitesubscription.class.php',
				'objectname' => 'LmdbwebsiteSubscription',
				'method' => 'doReconcilePayments',
				'parameters' => '',
				'comment' => 'Reconcile pending Stancer payments and activate onboarding links',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 0,
				'test' => '$conf->lmdbwebsite->enabled',
				'priority' => 50,
				'datestart' => $datestart,
			),
			1 => array(
				'label' => 'LmdbwebsiteCreateDueInvoices',
				'jobtype' => 'method',
				'class' => '/lmdbwebsite/class/lmdbwebsitesubscription.class.php',
				'objectname' => 'LmdbwebsiteSubscription',
				'method' => 'doCreateDueInvoices',
				'parameters' => '',
				'comment' => 'Create subscription invoices when due',
				'frequency' => 1,
				'unitfrequency' => 3600 * 24,
				'status' => 0,
				'test' => '$conf->lmdbwebsite->enabled',
				'priority' => 50,
				'datestart' => $datestart,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'Read LMDB Website subscriptions';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'Manage LMDB Website subscriptions';
		$this->rights[$r][4] = 'write';
		$r++;

		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=commercial',
			'type' => 'left',
			'titre' => 'LMDB Website',
			'mainmenu' => 'commercial',
			'leftmenu' => 'lmdbwebsite',
			'url' => '/lmdbwebsite/subscription_list.php',
			'langs' => 'lmdbwebsite@lmdbwebsite',
			'position' => 1000 + $r,
			'enabled' => '$conf->lmdbwebsite->enabled',
			'perms' => '$user->hasRight("lmdbwebsite", "read")',
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param string $options Options
	 * @return int
	 */
	public function init($options = '')
	{
		$sql = array();
		$result = $this->_load_tables('/lmdbwebsite/sql/');
		if ($result < 0) {
			return -1;
		}

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param string $options Options
	 * @return int
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
