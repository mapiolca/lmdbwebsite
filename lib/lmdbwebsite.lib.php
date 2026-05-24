<?php
/* Copyright (C) 2026 Pierre Ardoin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/lmdbwebsite.lib.php
 * \ingroup lmdbwebsite
 * \brief   Helpers for LMDB Website.
 */

/**
 * Prepare admin tabs.
 *
 * @return array<int,array<int,string>>
 */
function lmdbwebsiteAdminPrepareHead()
{
	global $langs;

	$langs->load('lmdbwebsite@lmdbwebsite');

	return array(
		array(dol_buildpath('/lmdbwebsite/admin/setup.php', 1), $langs->trans('LmdbwebsiteSetup'), 'setup'),
	);
}

/**
 * Return a module setting.
 *
 * @param string $key     Key without LMDBWEBSITE_ prefix
 * @param string $default Default value
 * @return string
 */
function lmdbwebsite_get_conf($key, $default = '')
{
	return getDolGlobalString('LMDBWEBSITE_'.$key, $default);
}

/**
 * Build an absolute public module URL.
 *
 * @param string $path Relative public path
 * @param array<string,string> $params Query params
 * @return string
 */
function lmdbwebsite_public_url($path, $params = array())
{
	$base = trim(lmdbwebsite_get_conf('DOLIBARR_URL', ''));
	if ($base === '') {
		$base = trim(getDolGlobalString('MAIN_URL_ROOT', ''));
	}
	if ($base === '') {
		$base = trim(lmdbwebsite_get_conf('SITE_URL', ''));
	}
	$base = rtrim($base, '/');
	$url = $base.'/custom/lmdbwebsite/public/'.ltrim($path, '/');
	if (!empty($params)) {
		$url .= '?'.http_build_query($params);
	}
	return $url;
}

/**
 * Build an absolute public contact endpoint URL.
 *
 * @param string $path Relative public path
 * @param array<string,string> $params Query params
 * @return string
 */
function lmdbwebsite_contact_public_url($path, $params = array())
{
	$base = trim(lmdbwebsite_get_conf('CONTACT_DOLIBARR_URL', ''));
	if ($base === '') {
		$base = trim(lmdbwebsite_get_conf('DOLIBARR_URL', ''));
	}
	if ($base === '') {
		$base = trim(getDolGlobalString('MAIN_URL_ROOT', ''));
	}
	if ($base === '') {
		$base = trim(lmdbwebsite_get_conf('SITE_URL', ''));
	}
	$base = rtrim($base, '/');
	$url = $base.'/custom/lmdbwebsite/public/'.ltrim($path, '/');
	if (!empty($params)) {
		$url .= '?'.http_build_query($params);
	}
	return $url;
}

/**
 * Create a per-session CSRF token for public forms.
 *
 * @return string
 */
function lmdbwebsite_csrf_token()
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		@session_start();
	}
	if (empty($_SESSION['lmdbwebsite_csrf'])) {
		$_SESSION['lmdbwebsite_csrf'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['lmdbwebsite_csrf'];
}

/**
 * Check a public CSRF token.
 *
 * @param string $token Token
 * @return bool
 */
function lmdbwebsite_check_csrf_token($token)
{
	return hash_equals(lmdbwebsite_csrf_token(), (string) $token);
}

/**
 * Basic session rate limiter for public forms.
 *
 * @param string $key    Bucket key
 * @param int    $limit  Max submissions
 * @param int    $window Window in seconds
 * @return bool
 */
function lmdbwebsite_rate_limit($key, $limit = 8, $window = 900)
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		@session_start();
	}
	$now = time();
	if (empty($_SESSION['lmdbwebsite_rate'][$key])) {
		$_SESSION['lmdbwebsite_rate'][$key] = array('start' => $now, 'count' => 0);
	}
	$bucket = &$_SESSION['lmdbwebsite_rate'][$key];
	if (($now - $bucket['start']) > $window) {
		$bucket = array('start' => $now, 'count' => 0);
	}
	$bucket['count']++;

	return $bucket['count'] <= $limit;
}

/**
 * Hash an IP address without storing it in clear text.
 *
 * @return string
 */
function lmdbwebsite_ip_hash()
{
	$ip = empty($_SERVER['REMOTE_ADDR']) ? '' : $_SERVER['REMOTE_ADDR'];
	return hash('sha256', $ip.'|'.getDolGlobalString('MAIN_SECURITY_SALT', 'lmdbwebsite'));
}

/**
 * Escape HTML for public pages.
 *
 * @param mixed $value Value
 * @return string
 */
function lmdbwebsite_escape($value)
{
	return dol_escape_htmltag((string) $value);
}
