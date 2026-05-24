<?php
/* Copyright (C) 2026 Pierre Ardoin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/lmdbwebsiteinstaller.class.php
 * \ingroup lmdbwebsite
 * \brief   Synchronize bundled LMDB website resources into Dolibarr Website.
 */

require_once DOL_DOCUMENT_ROOT.'/website/class/website.class.php';
require_once DOL_DOCUMENT_ROOT.'/website/class/websitepage.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/website.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/website2.lib.php';
dol_include_once('/lmdbwebsite/lib/lmdbwebsite.lib.php');

/**
 * Installer for the native Dolibarr Website site.
 */
class LmdbwebsiteInstaller
{
	const WEBSITE_REF = 'lmdbwebsite';

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var array<int,string> */
	public $errors = array();

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
	 * Synchronize the bundled site into Dolibarr Website.
	 *
	 * @param User $user User running the import
	 * @return array<string,mixed>|int
	 */
	public function syncWebsite(User $user)
	{
		global $conf;

		if (empty($conf->website) || empty($conf->website->enabled)) {
			return $this->fail('LmdbwebsiteMissingWebsiteModule');
		}

		$resourceDir = $this->getResourceDir();
		if (!is_dir($resourceDir.'/pages') || !is_dir($resourceDir.'/assets')) {
			return $this->fail('LMDB Website resources not found in resources/dolibarr-website');
		}

		$summary = array(
			'site_created' => 0,
			'pages_created' => 0,
			'pages_updated' => 0,
			'assets_copied' => 0,
			'home_set' => 0,
			'target_dir' => $this->getTargetDir(),
		);

		if ($this->ensureWebsiteWritableDirectory($summary['target_dir']) < 0) {
			return -1;
		}

		$website = $this->createOrUpdateWebsite($user, $summary);
		if (!is_object($website) || empty($website->id)) {
			return -1;
		}

		$assetsResult = $this->syncAssets($resourceDir.'/assets', $summary['target_dir'].'/assets');
		if ($assetsResult < 0) {
			return -1;
		}
		$summary['assets_copied'] += $assetsResult;

		$runtimeResult = $this->syncRuntimeAssets($resourceDir.'/assets', $summary['target_dir']);
		if ($runtimeResult < 0) {
			return -1;
		}
		$summary['assets_copied'] += $runtimeResult;

		$this->ensureBaseRuntimeFiles($summary['target_dir'], $website);

		$homePageId = 0;
		foreach ($this->getManagedPages() as $definition) {
			$result = $this->syncPage($website, $definition, $user, $summary['target_dir'], $summary);
			if ($result < 0) {
				return -1;
			}
			if (!empty($definition['is_home'])) {
				$homePageId = $result;
			}
		}

		if (empty($website->fk_default_home) && $homePageId > 0) {
			$website->fk_default_home = $homePageId;
			$res = $website->update($user);
			if ($res <= 0) {
				$this->error = $website->error;
				$this->errors = $website->errors;
				return -1;
			}
			$summary['home_set'] = 1;
		}

		$this->saveIndexForDefaultHome($website, $summary['target_dir']);

		return $summary;
	}

	/**
	 * Return information about the managed website.
	 *
	 * @return array<string,mixed>
	 */
	public function getWebsiteInfo()
	{
		global $conf;

		$info = array(
			'found' => 0,
			'id' => 0,
			'status' => '',
			'pages' => 0,
			'target_dir' => $this->getTargetDir(),
			'website_enabled' => (empty($conf->website) || empty($conf->website->enabled)) ? 0 : 1,
		);

		if (!$info['website_enabled']) {
			return $info;
		}

		$website = new Website($this->db);
		$res = $website->fetch(0, self::WEBSITE_REF);
		if ($res <= 0) {
			return $info;
		}

		$info['found'] = 1;
		$info['id'] = (int) $website->id;
		$info['status'] = (int) $website->status;
		$info['pages'] = $this->countPages((int) $website->id);

		return $info;
	}

	/**
	 * Return filesystem diagnostics for the managed Website directory.
	 *
	 * @return array<string,mixed>
	 */
	public function getWebsiteDirectoryDiagnostics()
	{
		global $conf, $dolibarr_main_data_root;

		$targetDir = $this->getTargetDir();
		$parentDir = dirname($targetDir);
		$closestExistingParent = $this->findClosestExistingParent($parentDir);
		$openBaseDir = ini_get('open_basedir');

		return array(
			'target_dir' => $targetDir,
			'target_exists' => @is_dir($targetDir) ? 1 : 0,
			'target_writable' => (@is_dir($targetDir) && @is_writable($targetDir)) ? 1 : 0,
			'parent_dir' => $parentDir,
			'parent_exists' => @is_dir($parentDir) ? 1 : 0,
			'parent_writable' => (@is_dir($parentDir) && @is_writable($parentDir)) ? 1 : 0,
			'closest_existing_parent' => $closestExistingParent,
			'closest_existing_parent_writable' => ($closestExistingParent !== '' && @is_writable($closestExistingParent)) ? 1 : 0,
			'dolibarr_main_data_root' => empty($dolibarr_main_data_root) ? '' : (string) $dolibarr_main_data_root,
			'dol_data_root' => defined('DOL_DATA_ROOT') ? DOL_DATA_ROOT : '',
			'website_dir_output' => empty($conf->website->dir_output) ? '' : (string) $conf->website->dir_output,
			'lmdbwebsite_dir_output' => empty($conf->lmdbwebsite->dir_output) ? '' : (string) $conf->lmdbwebsite->dir_output,
			'open_basedir' => empty($openBaseDir) ? '' : (string) $openBaseDir,
		);
	}

	/**
	 * Get target Website directory.
	 *
	 * @return string
	 */
	public function getTargetDir()
	{
		return $this->getWebsiteOutputBase().'/'.self::WEBSITE_REF;
	}

	/**
	 * Create and validate writable Website directories.
	 *
	 * @param string $targetDir Target directory
	 * @return int 1 if OK, <0 if KO
	 */
	private function ensureWebsiteWritableDirectory($targetDir)
	{
		$parentDir = dirname($targetDir);
		if ($this->ensureWebsiteDirectoryReady($parentDir, 'LmdbwebsiteWebsiteParentDirectoryNotWritable') < 0) {
			return -1;
		}
		if ($this->ensureWebsiteDirectoryReady($targetDir, 'LmdbwebsiteWebsiteTargetDirectoryNotWritable') < 0) {
			return -1;
		}

		return 1;
	}

	/**
	 * Create and validate a Website directory with diagnostic errors.
	 *
	 * @param string $dir Directory path
	 * @param string $messageKey Translation key for diagnostics
	 * @return int 1 if OK, <0 if KO
	 */
	private function ensureWebsiteDirectoryReady($dir, $messageKey)
	{
		if (!@is_dir($dir)) {
			dol_mkdir($dir);
			clearstatcache(true, $dir);
		}
		if (!@is_dir($dir) || !@is_writable($dir)) {
			return $this->failWebsiteDirectoryDiagnostics($messageKey);
		}

		return 1;
	}

	/**
	 * Register a directory diagnostic error.
	 *
	 * @param string $messageKey Translation key
	 * @return int
	 */
	private function failWebsiteDirectoryDiagnostics($messageKey)
	{
		global $langs;

		$diagnostics = $this->getWebsiteDirectoryDiagnostics();
		$message = is_object($langs) ? $langs->trans($messageKey, $diagnostics['parent_dir']) : $messageKey;
		if ($messageKey === 'LmdbwebsiteWebsiteTargetDirectoryNotWritable') {
			$message = is_object($langs) ? $langs->trans($messageKey, $diagnostics['target_dir']) : $messageKey;
		}

		$this->error = $message;
		$this->errors = $this->formatWebsiteDirectoryDiagnostics($diagnostics);

		return -1;
	}

	/**
	 * Format directory diagnostics for event messages.
	 *
	 * @param array<string,mixed> $diagnostics Diagnostics
	 * @return array<int,string>
	 */
	private function formatWebsiteDirectoryDiagnostics($diagnostics)
	{
		global $langs;

		$items = array(
			'LmdbwebsiteWebsiteDiagnosticTargetDir' => $diagnostics['target_dir'],
			'LmdbwebsiteWebsiteDiagnosticTargetExists' => $this->formatBoolean($diagnostics['target_exists']),
			'LmdbwebsiteWebsiteDiagnosticTargetWritable' => $this->formatBoolean($diagnostics['target_writable']),
			'LmdbwebsiteWebsiteDiagnosticParentDir' => $diagnostics['parent_dir'],
			'LmdbwebsiteWebsiteDiagnosticParentExists' => $this->formatBoolean($diagnostics['parent_exists']),
			'LmdbwebsiteWebsiteDiagnosticParentWritable' => $this->formatBoolean($diagnostics['parent_writable']),
			'LmdbwebsiteWebsiteDiagnosticClosestParent' => $diagnostics['closest_existing_parent'],
			'LmdbwebsiteWebsiteDiagnosticClosestParentWritable' => $this->formatBoolean($diagnostics['closest_existing_parent_writable']),
			'LmdbwebsiteWebsiteDiagnosticDolibarrMainDataRoot' => $diagnostics['dolibarr_main_data_root'],
			'LmdbwebsiteWebsiteDiagnosticDolDataRoot' => $diagnostics['dol_data_root'],
			'LmdbwebsiteWebsiteDiagnosticWebsiteDirOutput' => $diagnostics['website_dir_output'],
			'LmdbwebsiteWebsiteDiagnosticLmdbwebsiteDirOutput' => $diagnostics['lmdbwebsite_dir_output'],
			'LmdbwebsiteWebsiteDiagnosticOpenBaseDir' => $diagnostics['open_basedir'],
		);

		$lines = array();
		foreach ($items as $labelKey => $value) {
			$label = is_object($langs) ? $langs->trans($labelKey) : $labelKey;
			$lines[] = $label.': '.($value === '' ? '-' : $value);
		}

		return $lines;
	}

	/**
	 * Format boolean diagnostic values.
	 *
	 * @param mixed $value Value
	 * @return string
	 */
	private function formatBoolean($value)
	{
		global $langs;

		if (is_object($langs)) {
			return $langs->trans($value ? 'Yes' : 'No');
		}

		return $value ? 'yes' : 'no';
	}

	/**
	 * Create the Website record or update its managed metadata.
	 *
	 * @param User $user User
	 * @param array<string,mixed> $summary Summary
	 * @return Website|false
	 */
	private function createOrUpdateWebsite(User $user, &$summary)
	{
		$website = new Website($this->db);
		$res = $website->fetch(0, self::WEBSITE_REF);
		if ($res < 0) {
			$this->error = $website->error;
			$this->errors = $website->errors;
			return false;
		}

		$virtualhost = rtrim((string) lmdbwebsite_get_conf('SITE_URL', getDolGlobalString('MAIN_URL_ROOT', '')), '/');

		if ($res === 0) {
			$website->ref = self::WEBSITE_REF;
			$website->description = 'Les Metiers du Batiment';
			$website->lang = 'fr';
			$website->otherlang = '';
			$website->virtualhost = $virtualhost;
			$website->status = Website::STATUS_DRAFT;
			$website->position = 0;
			$id = $website->create($user);
			if ($id <= 0) {
				$this->error = $website->error;
				$this->errors = $website->errors;
				return false;
			}
			$summary['site_created'] = 1;
			$website->fetch($id);
			return $website;
		}

		$changed = false;
		if (empty($website->description)) {
			$website->description = 'Les Metiers du Batiment';
			$changed = true;
		}
		if (empty($website->lang)) {
			$website->lang = 'fr';
			$changed = true;
		}
		if (!isset($website->otherlang)) {
			$website->otherlang = '';
			$changed = true;
		}
		if ($virtualhost !== '' && $website->virtualhost !== $virtualhost) {
			$website->virtualhost = $virtualhost;
			$changed = true;
		}
		if ($changed) {
			$result = $website->update($user);
			if ($result <= 0) {
				$this->error = $website->error;
				$this->errors = $website->errors;
				return false;
			}
		}

		return $website;
	}

	/**
	 * Synchronize one managed page.
	 *
	 * @param Website $website Website object
	 * @param array<string,mixed> $definition Page definition
	 * @param User $user User
	 * @param string $targetDir Website target directory
	 * @param array<string,mixed> $summary Summary
	 * @return int Page id, <0 if error
	 */
	private function syncPage(Website $website, $definition, User $user, $targetDir, &$summary)
	{
		$sourceFile = $this->getResourceDir().'/pages/'.$definition['source'];
		if (!is_readable($sourceFile)) {
			return $this->fail('Missing Website page resource '.$definition['source']);
		}

		$content = (string) file_get_contents($sourceFile);
		$content = $this->wrapPageContent($content, $definition);
		$content = $this->normalizeSubscriptionLinks($content);
		$content = $this->normalizeContactLinks($content);
		$content = $this->normalizeInternalLinks($content);

		$page = new WebsitePage($this->db);
		$res = $page->fetch(0, (string) $website->id, $definition['pageurl']);
		if ($res < 0) {
			$this->error = $page->error;
			$this->errors = $page->errors;
			return -1;
		}

		$created = false;
		if ($res === 0) {
			$page = new WebsitePage($this->db);
			$page->fk_website = $website->id;
			$page->status = WebsitePage::STATUS_DRAFT;
			$created = true;
		}

		$page->type_container = 'page';
		$page->pageurl = $definition['pageurl'];
		$page->ref = $definition['pageurl'];
		if ($created || trim((string) $page->aliasalt) === '') {
			$page->aliasalt = $definition['pageurl'];
		}
		$page->title = $definition['title'];
		$page->description = $definition['description'];
		$page->keywords = $definition['keywords'];
		$page->lang = 'fr';
		$page->htmlheader = $this->buildPageHtmlHeader($definition);
		$page->content = $content;
		$page->grabbed_from = 'lmdbwebsite/resources/dolibarr-website/pages/'.$definition['source'];
		$page->author_alias = 'LMDB Website';

		if ($created) {
			$pageId = $page->create($user);
			if ($pageId <= 0) {
				$this->error = $page->error;
				$this->errors = $page->errors;
				return -1;
			}
			$page->fetch($pageId);
		} else {
			$result = $page->update($user);
			if ($result <= 0) {
				$this->error = $page->error;
				$this->errors = $page->errors;
				return -1;
			}
		}

		$fileAlias = $targetDir.'/'.$page->pageurl.'.php';
		$fileTpl = $targetDir.'/page'.$page->id.'.tpl.php';
		if (!dolSavePageAlias($fileAlias, $website, $page)) {
			return $this->fail('Failed to write page alias '.$fileAlias);
		}
		if (!dolSavePageContent($fileTpl, $website, $page, 1)) {
			return $this->fail('Failed to write page template '.$fileTpl);
		}

		if ($created) {
			$summary['pages_created']++;
		} else {
			$summary['pages_updated']++;
		}

		return (int) $page->id;
	}

	/**
	 * Return managed page definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function getManagedPages()
	{
		$meta = $this->getContentMeta();
		$definitions = array(
			array('source' => 'home.html', 'pageurl' => 'home', 'meta' => 'home', 'public_path' => '/', 'is_home' => true, 'fallback_title' => 'Accueil'),
			array('source' => 'fonctionnement.html', 'pageurl' => 'fonctionnement', 'meta' => 'fonctionnement', 'public_path' => '/fonctionnement/', 'fallback_title' => 'Fonctionnement'),
			array('source' => 'tarifs.html', 'pageurl' => 'tarifs', 'meta' => 'tarifs', 'public_path' => '/tarifs/', 'fallback_title' => 'Tarifs'),
			array('source' => 'guides.html', 'pageurl' => 'guides', 'meta' => 'guides', 'public_path' => '/guides/', 'fallback_title' => 'Guides'),
			array('source' => 'modules.html', 'pageurl' => 'modules', 'meta' => 'modules', 'public_path' => '/modules/', 'fallback_title' => 'Nos modules'),
			array('source' => 'contact.html', 'pageurl' => 'contact', 'meta' => 'contact', 'public_path' => '/contact/', 'fallback_title' => 'Contact'),
			array('source' => 'conditions-generales.html', 'pageurl' => 'conditions-generales', 'meta' => 'conditions-generales', 'public_path' => '/conditions-generales/', 'fallback_title' => 'Conditions generales'),
			array('source' => 'mentions-legales.html', 'pageurl' => 'mentions', 'meta' => 'mentions-legales', 'public_path' => '/mentions-legales/', 'fallback_title' => 'Mentions legales'),
			array('source' => 'guides_erp-batiment.html', 'pageurl' => 'erp-batiment', 'meta' => 'guides/erp-batiment', 'public_path' => '/guides/erp-batiment/', 'fallback_title' => 'ERP batiment'),
			array('source' => 'guides_devis-batiment.html', 'pageurl' => 'devis-batiment', 'meta' => 'guides/devis-batiment', 'public_path' => '/guides/devis-batiment/', 'fallback_title' => 'Devis batiment'),
			array('source' => 'guides_gestion-chantier.html', 'pageurl' => 'chantier', 'meta' => 'guides/gestion-chantier', 'public_path' => '/guides/gestion-chantier/', 'fallback_title' => 'Gestion chantier'),
		);

		foreach ($definitions as $key => $definition) {
			$pageMeta = empty($meta[$definition['meta']]) ? array() : $meta[$definition['meta']];
			$definitions[$key]['title'] = empty($pageMeta['title']) ? $definition['fallback_title'] : $pageMeta['title'];
			$definitions[$key]['description'] = empty($pageMeta['description']) ? '' : $pageMeta['description'];
			$definitions[$key]['keywords'] = empty($pageMeta['keywords']) ? '' : $pageMeta['keywords'];
		}

		return $definitions;
	}

	/**
	 * Hydrate page metadata.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function getContentMeta()
	{
		$meta = array();
		$content = $this->getContentConfig();
		if (!is_array($content)) {
			return $meta;
		}

		foreach (array('pages', 'guides') as $section) {
			if (empty($content[$section]) || !is_array($content[$section])) {
				continue;
			}
			foreach ($content[$section] as $page) {
				$key = empty($page['slug']) ? (string) ($page['id'] ?? '') : (string) $page['slug'];
				if ($key === '') {
					continue;
				}
				$meta[$key] = array(
					'title' => (string) ($page['title'] ?? $page['headline'] ?? $key),
					'description' => (string) ($page['description'] ?? ''),
					'keywords' => (string) ($page['keywords'] ?? ''),
				);
				if (!empty($page['id'])) {
					$meta[(string) $page['id']] = $meta[$key];
				}
			}
		}

		return $meta;
	}

	/**
	 * Load site content configuration.
	 *
	 * @return array<string,mixed>
	 */
	private function getContentConfig()
	{
		$contentFile = dol_buildpath('/lmdbwebsite/content/site.json', 0);
		if (!is_readable($contentFile)) {
			return array();
		}

		$content = json_decode((string) file_get_contents($contentFile), true);
		return is_array($content) ? $content : array();
	}

	/**
	 * Wrap a page fragment with shared navigation and footer.
	 *
	 * @param string $content Page fragment
	 * @param array<string,mixed> $definition Page definition
	 * @return string
	 */
	private function wrapPageContent($content, $definition)
	{
		$config = $this->getContentConfig();
		$site = empty($config['site']) || !is_array($config['site']) ? array() : $config['site'];
		$externalLinks = empty($config['externalLinks']) || !is_array($config['externalLinks']) ? array() : $config['externalLinks'];
		$navigation = empty($config['navigation']) || !is_array($config['navigation']) ? array() : $config['navigation'];

		$siteName = empty($site['name']) ? 'Les Metiers du Batiment' : (string) $site['name'];
		$siteDescription = empty($site['description']) ? '' : (string) $site['description'];
		$logoImage = empty($site['logoImage']) ? '/assets/img/mediumsmall-res.png' : (string) $site['logoImage'];
		$portalUrl = empty($externalLinks['portal']) ? '' : (string) $externalLinks['portal'];
		$erpUrl = empty($externalLinks['erp']) ? '' : (string) $externalLinks['erp'];

		if (empty($navigation)) {
			$navigation = array(
				array('label' => 'Accueil', 'href' => '/'),
				array('label' => 'Fonctionnement', 'href' => '/fonctionnement/'),
				array('label' => 'Tarifs', 'href' => '/tarifs/'),
				array('label' => 'Guides', 'href' => '/guides/'),
				array('label' => 'Nos modules', 'href' => '/modules/'),
				array('label' => 'Contact', 'href' => '/contact/'),
			);
		}

		$navHtml = '';
		foreach ($navigation as $item) {
			if (empty($item['label']) || empty($item['href'])) {
				continue;
			}
			$href = (string) $item['href'];
			$active = $this->isCurrentNavigationItem((string) $definition['pageurl'], $href) ? ' aria-current="page"' : '';
			$navHtml .= '<a href="'.$this->escapeHtml($href).'"'.$active.'>'.$this->escapeHtml($item['label']).'</a>';
		}
		if ($portalUrl !== '') {
			$navHtml .= '<a href="'.$this->escapeHtml($portalUrl).'">Portail</a>';
		}
		if ($erpUrl !== '') {
			$navHtml .= '<a href="'.$this->escapeHtml($erpUrl).'">ERP/CRM</a>';
		}

		$header = '<header class="site-header">'."\n";
		$header .= '  <div class="nav-wrap">'."\n";
		$header .= '    <a class="brand" href="/"><img class="brand-logo" src="'.$this->escapeHtml($logoImage).'" alt="" width="500" height="500"><span>'.$this->escapeHtml($siteName).'</span></a>'."\n";
		$header .= '    <nav class="nav-links" aria-label="Navigation principale">'.$navHtml.'</nav>'."\n";
		$header .= '  </div>'."\n";
		$header .= '</header>'."\n";

		$footer = '<footer class="site-footer">'."\n";
		$footer .= '  <div class="section-inner footer-grid">'."\n";
		$footer .= '    <div><strong>'.$this->escapeHtml($siteName).'</strong><p>'.$this->escapeHtml($siteDescription).'</p></div>'."\n";
		$footer .= '    <div><a href="/conditions-generales/">Conditions generales</a><br><a href="/mentions-legales/">Mentions legales</a><br><a href="/modules/">Nos modules</a><br><a href="/contact/">Contact</a></div>'."\n";
		$footer .= '  </div>'."\n";
		$footer .= '</footer>'."\n";

		return $header.'<main>'."\n".$content."\n".'</main>'."\n".$footer;
	}

	/**
	 * Build per-page HTML header additions.
	 *
	 * @param array<string,mixed> $definition Page definition
	 * @return string
	 */
	private function buildPageHtmlHeader($definition)
	{
		$config = $this->getContentConfig();
		$site = empty($config['site']) || !is_array($config['site']) ? array() : $config['site'];
		$productImage = $this->buildPublicAssetUrl(empty($site['productImage']) ? '' : (string) $site['productImage']);
		$publicUrl = $this->buildPublicUrl($definition);

		$schema = array(
			'@context' => 'https://schema.org',
			'@type' => !empty($definition['is_home']) ? 'SoftwareApplication' : 'WebPage',
			'name' => (string) $definition['title'],
			'description' => (string) $definition['description'],
			'url' => $publicUrl,
		);
		if (!empty($definition['is_home'])) {
			$schema['applicationCategory'] = 'BusinessApplication';
			$schema['operatingSystem'] = 'Web';
		}

		$header = '<meta property="og:title" content="'.$this->escapeHtml($definition['title']).'">'."\n";
		if (!empty($site['faviconImage'])) {
			$header .= '<link rel="icon" type="image/png" href="'.$this->escapeHtml((string) $site['faviconImage']).'">'."\n";
		}
		$header .= '<meta property="og:description" content="'.$this->escapeHtml($definition['description']).'">'."\n";
		$header .= '<meta property="og:url" content="'.$this->escapeHtml($publicUrl).'">'."\n";
		$header .= '<meta property="og:type" content="website">'."\n";
		if ($productImage !== '') {
			$header .= '<meta property="og:image" content="'.$this->escapeHtml($productImage).'">'."\n";
		}
		$header .= '<script type="application/ld+json">'.json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'</script>'."\n";

		return $header;
	}

	/**
	 * Return absolute public URL for a managed page.
	 *
	 * @param array<string,mixed> $definition Page definition
	 * @return string
	 */
	private function buildPublicUrl($definition)
	{
		$config = $this->getContentConfig();
		$site = empty($config['site']) || !is_array($config['site']) ? array() : $config['site'];
		$baseUrl = rtrim((string) lmdbwebsite_get_conf('SITE_URL', empty($site['url']) ? getDolGlobalString('MAIN_URL_ROOT', '') : (string) $site['url']), '/');
		$path = empty($definition['public_path']) ? '/'.$definition['pageurl'].'.php' : (string) $definition['public_path'];

		return $baseUrl.$path;
	}

	/**
	 * Return an absolute public URL for a Website asset.
	 *
	 * @param string $path Asset path or URL
	 * @return string
	 */
	private function buildPublicAssetUrl($path)
	{
		if ($path === '' || preg_match('~^(?:https?:)?//~', $path)) {
			return $path;
		}

		$config = $this->getContentConfig();
		$site = empty($config['site']) || !is_array($config['site']) ? array() : $config['site'];
		$baseUrl = rtrim((string) lmdbwebsite_get_conf('SITE_URL', empty($site['url']) ? getDolGlobalString('MAIN_URL_ROOT', '') : (string) $site['url']), '/');

		return $baseUrl.'/'.ltrim($path, '/');
	}

	/**
	 * Check whether a navigation item should be marked current.
	 *
	 * @param string $pageurl Current Website page URL
	 * @param string $href Navigation href
	 * @return bool
	 */
	private function isCurrentNavigationItem($pageurl, $href)
	{
		$map = array(
			'/' => array('home'),
			'/fonctionnement/' => array('fonctionnement'),
			'/tarifs/' => array('tarifs'),
			'/guides/' => array('guides', 'erp-batiment', 'devis-batiment', 'chantier'),
			'/modules/' => array('modules'),
			'/contact/' => array('contact'),
		);

		return !empty($map[$href]) && in_array($pageurl, $map[$href], true);
	}

	/**
	 * Escape a value for HTML output.
	 *
	 * @param mixed $value Value to escape
	 * @return string
	 */
	private function escapeHtml($value)
	{
		return dol_escape_htmltag((string) $value);
	}

	/**
	 * Synchronize bundled assets into Website assets directory.
	 *
	 * @param string $source Source directory
	 * @param string $target Target directory
	 * @return int Number of copied files, <0 if error
	 */
	private function syncAssets($source, $target)
	{
		if (!is_dir($source)) {
			return 0;
		}
		if ($this->ensureDirectoryExists($target, 'Unable to create assets directory') < 0) {
			return -1;
		}

		$count = 0;
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if (!$file->isFile()) {
				continue;
			}
			$relative = substr($file->getPathname(), strlen($source) + 1);
			$destination = $target.'/'.$relative;
			$result = $this->copyFileIfChanged($file->getPathname(), $destination);
			if ($result < 0) {
				return -1;
			}
			$count += $result;
		}

		return $count;
	}

	/**
	 * Synchronize assets used by Dolibarr generated runtime files.
	 *
	 * @param string $sourceAssets Source assets directory
	 * @param string $targetDir Target website directory
	 * @return int Number of changed files, <0 if error
	 */
	private function syncRuntimeAssets($sourceAssets, $targetDir)
	{
		$count = 0;
		$files = array(
			'styles.css' => 'styles.css.php',
			'main.js' => 'javascript.js.php',
		);

		foreach ($files as $sourceName => $targetName) {
			$source = $sourceAssets.'/'.$sourceName;
			if (!is_readable($source)) {
				continue;
			}
			$content = (string) file_get_contents($source);
			$content = $sourceName === 'styles.css' ? $this->wrapCssContent($content) : $this->wrapJsContent($content);
			$result = $this->saveDolibarrFile($targetDir.'/'.$targetName, $content, $sourceName === 'styles.css' ? 'dolSaveCssFile' : 'dolSaveJsFile');
			if ($result < 0) {
				return -1;
			}
			$count += $result;
		}

		return $count;
	}

	/**
	 * Ensure basic Website runtime files exist.
	 *
	 * @param string $targetDir Target directory
	 * @param Website $website Website object
	 * @return void
	 */
	private function ensureBaseRuntimeFiles($targetDir, Website $website)
	{
		if (!dol_is_file($targetDir.'/master.inc.php')) {
			dolSaveMasterFile($targetDir.'/master.inc.php');
		}
		$htmlHeaderFile = $targetDir.'/htmlheader.html';
		$htmlHeaderContent = dol_is_file($htmlHeaderFile) ? trim((string) file_get_contents($htmlHeaderFile)) : '';
		if (!dol_is_file($htmlHeaderFile) || $htmlHeaderContent === '<html></html>') {
			$this->saveDolibarrFile($htmlHeaderFile, "\n", 'dolSaveHtmlHeader');
		}
		if (!dol_is_file($targetDir.'/robots.txt')) {
			$this->saveDolibarrFile($targetDir.'/robots.txt', $this->buildRobotsContent(), 'dolSaveRobotFile');
		}
		$this->saveDolibarrFile($targetDir.'/sitemap.xml', $this->buildSitemapContent(), 'dolSaveRobotFile');
		if (!dol_is_file($targetDir.'/.htaccess')) {
			$this->saveDolibarrFile($targetDir.'/.htaccess', "# Order allow,deny\n# Deny from all\n", 'dolSaveHtaccessFile');
		}
		if (!dol_is_file($targetDir.'/manifest.json.php')) {
			$this->saveDolibarrFile($targetDir.'/manifest.json.php', "{}\n", 'dolSaveManifestJson');
		}
		if (!dol_is_file($targetDir.'/README.md')) {
			$this->saveDolibarrFile($targetDir.'/README.md', "Website generated by LMDB Website\n", 'dolSaveReadme');
		}
		if (!dol_is_file($targetDir.'/LICENSE')) {
			$this->saveDolibarrFile($targetDir.'/LICENSE', "LICENSE\n-------\nContent generated for Les Metiers du Batiment.\n", 'dolSaveLicense');
		}

		$mediasLink = $targetDir.'/medias';
		if (!is_link(dol_osencode($mediasLink)) && defined('DOL_DATA_ROOT')) {
			@symlink(DOL_DATA_ROOT.'/medias', $mediasLink);
		}
	}

	/**
	 * Save index and wrapper for current default home.
	 *
	 * @param Website $website Website object
	 * @param string $targetDir Target directory
	 * @return void
	 */
	private function saveIndexForDefaultHome(Website $website, $targetDir)
	{
		if (empty($website->fk_default_home)) {
			return;
		}

		$fileTpl = $targetDir.'/page'.$website->fk_default_home.'.tpl.php';
		if (!dol_is_file($fileTpl)) {
			return;
		}
		dolSaveIndexPage($targetDir, $targetDir.'/index.php', $fileTpl, $targetDir.'/wrapper.php', $website);
	}

	/**
	 * Normalize legacy static links to Dolibarr Website aliases.
	 *
	 * @param string $content HTML content
	 * @return string
	 */
	private function normalizeInternalLinks($content)
	{
		$replacements = array(
			'href="/"' => 'href="index.php"',
			'href="/fonctionnement/"' => 'href="fonctionnement.php"',
			'href="/tarifs/"' => 'href="tarifs.php"',
			'href="/tarifs/#offres"' => 'href="tarifs.php#offres"',
			'href="#offres"' => 'href="#offres"',
			'href="/contact/"' => 'href="contact.php"',
			'href="/conditions-generales/"' => 'href="conditions-generales.php"',
			'href="/modules/"' => 'href="modules.php"',
			'href="/mentions-legales/"' => 'href="mentions.php"',
			'href="/guides/"' => 'href="guides.php"',
			'href="/guides/erp-batiment/"' => 'href="erp-batiment.php"',
			'href="/guides/devis-batiment/"' => 'href="devis-batiment.php"',
			'href="/guides/gestion-chantier/"' => 'href="chantier.php"',
		);

		return str_replace(array_keys($replacements), array_values($replacements), $content);
	}

	/**
	 * Normalize subscription links to the public Dolibarr endpoint URL.
	 *
	 * @param string $content HTML content
	 * @return string
	 */
	private function normalizeSubscriptionLinks($content)
	{
		$baseUrl = lmdbwebsite_public_url('subscribe.php');
		$result = preg_replace_callback(
			'~href=(["\'])(?:https?://[^"\']+)?/custom/lmdbwebsite/public/subscribe\.php([^"\']*)\1~',
			function ($matches) use ($baseUrl) {
				return 'href='.$matches[1].$this->escapeHtml($baseUrl.$matches[2]).$matches[1];
			},
			$content
		);

		return $result === null ? $content : $result;
	}

	/**
	 * Normalize contact form endpoint links to the dedicated public Dolibarr URL.
	 *
	 * @param string $content HTML content
	 * @return string
	 */
	private function normalizeContactLinks($content)
	{
		$baseUrl = lmdbwebsite_contact_public_url('contact.php');
		$result = preg_replace_callback(
			'~(href|src|action)=(["\'])(?:https?://[^"\']+)?/custom/lmdbwebsite/public/contact\.php([^"\']*)\2~',
			function ($matches) use ($baseUrl) {
				return $matches[1].'='.$matches[2].$this->escapeHtml($baseUrl.$matches[3]).$matches[2];
			},
			$content
		);

		return $result === null ? $content : $result;
	}

	/**
	 * Copy a file only when changed.
	 *
	 * @param string $source Source file
	 * @param string $destination Destination file
	 * @return int 1 if copied, 0 if unchanged, <0 if error
	 */
	private function copyFileIfChanged($source, $destination)
	{
		if (dol_is_file($destination) && sha1_file($source) === sha1_file($destination)) {
			if ($this->isImageAssetPath($destination)) {
				@chmod($destination, 0644);
			}
			return 0;
		}

		if ($this->ensureDirectoryExists(dirname($destination), 'Unable to create directory') < 0) {
			return -1;
		}
		if (!copy($source, $destination)) {
			return $this->fail('Unable to copy file '.$destination);
		}
		dolChmod($destination);
		if ($this->isImageAssetPath($destination)) {
			@chmod($destination, 0644);
		}

		return 1;
	}

	/**
	 * Check whether a file path is a bundled image asset.
	 *
	 * @param string $path File path
	 * @return bool
	 */
	private function isImageAssetPath($path)
	{
		return (bool) preg_match('~/assets/img/.*\.(?:gif|jpe?g|png|webp)$~i', $path);
	}

	/**
	 * Save a file through a Dolibarr Website helper when available.
	 *
	 * @param string $file Destination file
	 * @param string $content File content
	 * @param string $function Dolibarr helper function
	 * @return int 1 if written, 0 if unchanged, <0 if error
	 */
	private function saveDolibarrFile($file, $content, $function)
	{
		if (dol_is_file($file) && sha1_file($file) === sha1($content)) {
			return 0;
		}

		if ($this->ensureDirectoryExists(dirname($file), 'Unable to create directory') < 0) {
			return -1;
		}

		if (function_exists($function)) {
			global $pathofwebsite;
			$previousPathOfWebsite = isset($pathofwebsite) ? $pathofwebsite : null;
			$pathofwebsite = dirname($file);
			$result = $function($file, $content);
			if ($previousPathOfWebsite === null) {
				unset($GLOBALS['pathofwebsite']);
			} else {
				$pathofwebsite = $previousPathOfWebsite;
			}
		} else {
			$result = file_put_contents($file, $content);
			if ($result !== false) {
				dolChmod($file);
			}
		}

		return $result ? 1 : $this->fail('Unable to write file '.$file);
	}

	/**
	 * Create and validate a writable directory.
	 *
	 * @param string $dir Directory path
	 * @param string $message Error message prefix
	 * @return int 1 if OK, <0 if KO
	 */
	private function ensureDirectoryExists($dir, $message)
	{
		if (!@is_dir($dir)) {
			dol_mkdir($dir);
			clearstatcache(true, $dir);
		}
		if (!@is_dir($dir) || !@is_writable($dir)) {
			return $this->fail($message.' '.$dir);
		}

		return 1;
	}

	/**
	 * Build robots.txt content.
	 *
	 * @return string
	 */
	private function buildRobotsContent()
	{
		$config = $this->getContentConfig();
		$site = empty($config['site']) || !is_array($config['site']) ? array() : $config['site'];
		$baseUrl = rtrim((string) lmdbwebsite_get_conf('SITE_URL', empty($site['url']) ? getDolGlobalString('MAIN_URL_ROOT', '') : (string) $site['url']), '/');
		$content = "User-agent: *\nAllow: /\n";
		if ($baseUrl !== '') {
			$content .= 'Sitemap: '.$baseUrl."/sitemap.xml\n";
		}

		return $content;
	}

	/**
	 * Build sitemap XML content from managed pages.
	 *
	 * @return string
	 */
	private function buildSitemapContent()
	{
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
		foreach ($this->getManagedPages() as $definition) {
			$xml .= "  <url><loc>".$this->escapeHtml($this->buildPublicUrl($definition))."</loc></url>\n";
		}
		$xml .= "</urlset>\n";

		return $xml;
	}

	/**
	 * Wrap CSS content like Dolibarr Website editor does.
	 *
	 * @param string $css CSS content
	 * @return string
	 */
	private function wrapCssContent($css)
	{
		$content = "<?php // BEGIN PHP\n";
		$content .= "\$websitekey=basename(__DIR__);\n";
		$content .= "if (! defined('USEDOLIBARRSERVER') && ! defined('USEDOLIBARREDITOR')) { require_once __DIR__.'/master.inc.php'; }\n";
		$content .= "require_once DOL_DOCUMENT_ROOT.'/core/lib/website.lib.php';\n";
		$content .= "require_once DOL_DOCUMENT_ROOT.'/core/website.inc.php';\n";
		$content .= "ob_start();\n";
		$content .= "if (! headers_sent()) {\n";
		$content .= "header('Cache-Control: no-cache, max-age=0, must-revalidate');\n";
		$content .= "header('Pragma: no-cache');\n";
		$content .= "header('Expires: 0');\n";
		$content .= "header('Content-type: text/css');\n";
		$content .= "}\n";
		$content .= "// END PHP ?>\n";
		$content .= $css."\n";
		$content .= "<?php // BEGIN PHP\n";
		$content .= "\$tmp = ob_get_contents(); ob_end_clean(); dolWebsiteOutput(\$tmp, \"css\");\n";
		$content .= "// END PHP\n";

		return $content;
	}

	/**
	 * Wrap JS content like Dolibarr Website editor does.
	 *
	 * @param string $js JavaScript content
	 * @return string
	 */
	private function wrapJsContent($js)
	{
		$content = "<?php // BEGIN PHP\n";
		$content .= "\$websitekey=basename(__DIR__);\n";
		$content .= "if (! defined('USEDOLIBARRSERVER') && ! defined('USEDOLIBARREDITOR')) { require_once __DIR__.'/master.inc.php'; }\n";
		$content .= "require_once DOL_DOCUMENT_ROOT.'/core/lib/website.lib.php';\n";
		$content .= "require_once DOL_DOCUMENT_ROOT.'/core/website.inc.php';\n";
		$content .= "ob_start();\n";
		$content .= "if (! headers_sent()) {\n";
		$content .= "header('Cache-Control: no-cache, max-age=0, must-revalidate');\n";
		$content .= "header('Pragma: no-cache');\n";
		$content .= "header('Expires: 0');\n";
		$content .= "header('Content-type: application/javascript');\n";
		$content .= "}\n";
		$content .= "// END PHP ?>\n";
		$content .= $js."\n";
		$content .= "<?php // BEGIN PHP\n";
		$content .= "\$tmp = ob_get_contents(); ob_end_clean(); dolWebsiteOutput(\$tmp, \"js\");\n";
		$content .= "// END PHP\n";

		return $content;
	}

	/**
	 * Count pages of a Website.
	 *
	 * @param int $websiteId Website id
	 * @return int
	 */
	private function countPages($websiteId)
	{
		$sql = 'SELECT COUNT(rowid) as nb FROM '.MAIN_DB_PREFIX.'website_page';
		$sql .= ' WHERE fk_website = '.((int) $websiteId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ? (int) $obj->nb : 0;
	}

	/**
	 * Return the output base directory for Website files.
	 *
	 * @return string
	 */
	private function getWebsiteOutputBase()
	{
		global $conf;

		return rtrim(DOL_DATA_ROOT.((int) $conf->entity > 1 ? '/'.((int) $conf->entity) : '').'/website', '/');
	}

	/**
	 * Return bundled resource directory.
	 *
	 * @return string
	 */
	private function getResourceDir()
	{
		return dol_buildpath('/lmdbwebsite/resources/dolibarr-website', 0);
	}

	/**
	 * Find the closest existing parent directory.
	 *
	 * @param string $path Directory path
	 * @return string
	 */
	private function findClosestExistingParent($path)
	{
		$current = rtrim($path, '/');
		while ($current !== '' && $current !== '.' && $current !== '/') {
			if (@is_dir($current)) {
				return $current;
			}
			$parent = dirname($current);
			if ($parent === $current) {
				break;
			}
			$current = $parent;
		}

		return @is_dir('/') ? '/' : '';
	}

	/**
	 * Register an error.
	 *
	 * @param string $message Error message
	 * @return int
	 */
	private function fail($message)
	{
		$this->error = $message;
		$this->errors[] = $message;
		return -1;
	}
}
