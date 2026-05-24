import { chmod, cp, mkdir, readFile, readdir, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const content = JSON.parse(await readFile(path.join(root, "content/site.json"), "utf8"));
const outSite = path.join(root, "dist/site");
const outDolibarr = path.join(root, "dist/dolibarr-website");
const outDolibarrResource = path.join(root, "resources/dolibarr-website");
const sourceAssets = path.join(root, "src/assets");
const dolibarrOutputs = [outDolibarr, outDolibarrResource];

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function slugToHref(slug) {
  return slug ? `/${slug}/` : "/";
}

function pagePath(base, slug) {
  return path.join(base, slug || "", "index.html");
}

function absoluteAssetUrl(value) {
  if (!value || value.startsWith("http://") || value.startsWith("https://") || value.startsWith("//")) {
    return value;
  }
  return `${content.site.url}${value.startsWith("/") ? value : `/${value}`}`;
}

async function chmodImageTree(dir) {
  const entries = await readdir(dir, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      await chmodImageTree(fullPath);
      continue;
    }
    if (entry.isFile() && /\.(?:gif|jpe?g|png|webp)$/i.test(entry.name)) {
      await chmod(fullPath, 0o644);
    }
  }
}

function isCurrentNav(currentSlug, href) {
  if (href === slugToHref(currentSlug)) return true;
  if (href === "/guides/" && (currentSlug === "guides" || currentSlug.startsWith("guides/"))) return true;
  return false;
}

function nav(currentSlug) {
  return content.navigation.map((item) => {
    const current = isCurrentNav(currentSlug, item.href) ? ' aria-current="page"' : "";
    return `<a href="${item.href}"${current}>${escapeHtml(item.label)}</a>`;
  }).join("");
}

function footerLinks() {
  return [
    ["Conditions generales", "/conditions-generales/"],
    ["Mentions legales", "/mentions-legales/"],
    ["Nos modules", "/modules/"],
    ["Contact", "/contact/"]
  ].map(([label, href]) => `<a href="${href}">${escapeHtml(label)}</a>`).join("<br>\n        ");
}

function layout(page, body) {
  const canonical = `${content.site.url}${slugToHref(page.slug)}`;
  const schema = {
    "@context": "https://schema.org",
    "@type": page.id === "home" ? "SoftwareApplication" : "WebPage",
    name: page.title,
    description: page.description,
    url: canonical,
    applicationCategory: "BusinessApplication",
    operatingSystem: "Web"
  };
  const favicon = content.site.faviconImage ? `<link rel="icon" type="image/png" href="${escapeHtml(content.site.faviconImage)}">` : "";

  return `<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(page.title)} | ${escapeHtml(content.site.name)}</title>
  <meta name="description" content="${escapeHtml(page.description)}">
  <link rel="canonical" href="${canonical}">
  ${favicon}
  <meta property="og:title" content="${escapeHtml(page.title)}">
  <meta property="og:description" content="${escapeHtml(page.description)}">
  <meta property="og:url" content="${canonical}">
  <meta property="og:type" content="website">
  <meta property="og:image" content="${escapeHtml(absoluteAssetUrl(content.site.productImage))}">
  <link rel="stylesheet" href="/assets/styles.css">
  <script type="application/ld+json">${JSON.stringify(schema)}</script>
</head>
<body>
  <header class="site-header">
    <div class="nav-wrap">
      <a class="brand" href="/"><img class="brand-logo" src="${escapeHtml(content.site.logoImage)}" alt="" width="500" height="500"><span>${escapeHtml(content.site.name)}</span></a>
      <nav class="nav-links" aria-label="Navigation principale">
        ${nav(page.slug)}
        <a href="${content.externalLinks.portal}">Portail</a>
        <a href="${content.externalLinks.erp}">ERP/CRM</a>
      </nav>
    </div>
  </header>
  <main>
    ${body}
  </main>
  <footer class="site-footer">
    <div class="section-inner footer-grid">
      <div>
        <strong>${escapeHtml(content.site.name)}</strong>
        <p>${escapeHtml(content.site.description)}</p>
      </div>
      <div>
        ${footerLinks()}
      </div>
    </div>
  </footer>
  <script src="/assets/main.js" defer></script>
</body>
</html>`;
}

function hero(page, actionHref = "/contact/", secondaryHref = "/tarifs/#offres") {
  return `<section class="hero">
  <div class="section-inner hero-grid">
    <div>
      <p class="eyebrow">ERP/CRM Dolibarr pour le batiment</p>
      <!-- DOLIBARR_EDITABLE:${page.id}_hero -->
      <h1>${escapeHtml(page.headline)}</h1>
      <p class="lead">${escapeHtml(page.intro)}</p>
      <!-- /DOLIBARR_EDITABLE:${page.id}_hero -->
      <div class="hero-actions">
        <a class="button" href="${actionHref}">${escapeHtml(page.cta || "Demander une demo")}</a>
        <a class="button secondary" href="${secondaryHref}">Voir les tarifs</a>
      </div>
    </div>
    <figure class="hero-media">
      <img src="${escapeHtml(content.site.heroImage)}" alt="Interface Dolibarr configuree pour Les Metiers du Batiment" width="1280" height="800" loading="eager">
    </figure>
  </div>
</section>`;
}

function deviceShowcase() {
  return `<section class="section visual-section">
  <div class="section-inner device-showcase">
    <div>
      <p class="eyebrow">Terrain et bureau connectes</p>
      <h2>Une interface exploitable sur ordinateur et mobile</h2>
      <p class="lead">Les equipes gardent la meme base Dolibarr pour les devis, dossiers clients, chantiers, factures et documents, au bureau comme en deplacement.</p>
    </div>
    <div class="device-media" aria-label="Apercus Dolibarr">
      <figure class="device-frame laptop-frame">
        <img class="device-shell" src="/assets/img/apple-macbook-pro.png" alt="" width="2000" height="1182" loading="lazy">
        <img class="device-screen laptop-screen" src="/assets/img/capture-page-accueil.png" alt="Interface ERP Dolibarr Les Metiers du Batiment sur ordinateur" width="1280" height="800" loading="lazy">
      </figure>
      <figure class="device-frame phone-frame">
        <img class="device-shell" src="/assets/img/apple-iphone-5s-silver.png" alt="" width="769" height="1607" loading="lazy">
        <img class="device-screen phone-screen" src="/assets/img/mobile.png" alt="Interface mobile Dolibarr Les Metiers du Batiment" width="640" height="1136" loading="lazy">
      </figure>
    </div>
  </div>
</section>`;
}

function visualCards() {
  const visuals = [
    ["Chantiers", "Centralisez les dossiers, achats, documents et etapes utiles pour suivre chaque chantier.", "/assets/img/cust-home.jpg", "Batiment residentiel suivi dans Dolibarr"],
    ["Construction", "Gardez une lecture claire des tiers, produits, services et pieces administratives.", "/assets/img/immeuble-batiment.jpg", "Immeuble moderne"],
    ["Organisation", "Assemblez un socle ERP durable autour de processus simples et exploitables.", "/assets/img/construction-puzzle.jpg", "Puzzle de construction"]
  ];
  return `<section class="section">
  <div class="section-inner">
    <h2>Un socle pense pour les metiers du batiment</h2>
    <div class="visual-grid">${visuals.map(([title, text, image, alt]) => `<article class="visual-card">
      <img src="${image}" alt="${escapeHtml(alt)}" loading="lazy">
      <div><h3>${escapeHtml(title)}</h3><p>${escapeHtml(text)}</p></div>
    </article>`).join("")}</div>
  </div>
</section>`;
}

function integrationsSection() {
  const integrations = content.integrations || [];
  return `<section class="section alt">
  <div class="section-inner">
    <div class="section-heading">
      <p class="eyebrow">Ecosysteme</p>
      <h2>Des integrations utiles autour de Dolibarr</h2>
      <p class="lead">Le module garde Dolibarr au centre tout en connectant les briques de paiement, documents, donnees tiers, modules et services externes.</p>
    </div>
    <div class="integration-grid">${integrations.map((item) => `<article class="integration-card">
      <img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.name)}" loading="lazy">
      <span>${escapeHtml(item.name)}</span>
    </article>`).join("")}</div>
  </div>
</section>`;
}

function homePage(page) {
  const cards = page.sections[0].items.map((item) => {
    const title = typeof item === "string" ? item : item.title;
    const text = typeof item === "string" ? "Un socle structure dans Dolibarr, avec des donnees exploitables pour vos equipes." : item.text;
    return `<article class="card"><h3>${escapeHtml(title)}</h3><p>${escapeHtml(text)}</p></article>`;
  }).join("");

  return `${hero(page)}
${deviceShowcase()}
<section class="section">
  <div class="section-inner">
    <h2>${escapeHtml(page.sections[0].title)}</h2>
    <div class="grid">${cards}</div>
  </div>
</section>
${integrationsSection()}`;
}

function fonctionnementPage(page) {
  const platform = (page.platform || []).map(([title, text]) => `<article class="card"><h3>${escapeHtml(title)}</h3><p>${escapeHtml(text)}</p></article>`).join("");
  const domains = (page.features || []).map(([title, text]) => `<div class="feature-row"><h3>${escapeHtml(title)}</h3><p>${escapeHtml(text)}</p></div>`).join("");
  const workflow = (page.workflow || []).map(([title, text]) => `<article class="card step"><h3>${escapeHtml(title)}</h3><p>${escapeHtml(text)}</p></article>`).join("");

  return `${hero(page)}
<section class="section">
  <div class="section-inner split">
    <div>
      <p class="eyebrow">Socle open source</p>
      <h2>Dolibarr reste le coeur de votre gestion</h2>
      <p class="lead">Comme les offres Dolibarr les plus solides, l'approche consiste a partir d'un ERP/CRM complet, puis a activer les modules utiles sans complexifier l'usage quotidien.</p>
    </div>
    <div class="grid">${platform}</div>
  </div>
</section>
${visualCards()}
<section class="section">
  <div class="section-inner split">
    <div>
      <p class="eyebrow">Domaines fonctionnels</p>
      <h2>Un outil qui couvre les flux essentiels</h2>
      <p class="lead">Les donnees circulent du prospect jusqu'a la facture, en passant par les chantiers, les produits, les achats et les documents.</p>
    </div>
    <div class="feature-list">${domains}</div>
  </div>
</section>
<section class="section alt">
  <div class="section-inner steps">
    <div class="section-heading">
      <p class="eyebrow">Mise en place</p>
      <h2>Une progression par etapes</h2>
      <p class="lead">L'objectif est de rendre Dolibarr utile rapidement, puis d'enrichir le perimetre au rythme de l'entreprise.</p>
    </div>
    <div class="grid">${workflow}</div>
  </div>
</section>`;
}

function pricingPage(page) {
  const cards = content.offers.map((offer) => {
    const features = offer.features.map((item) => `<li>${escapeHtml(item)}</li>`).join("");
    const href = `/custom/lmdbwebsite/public/subscribe.php?offer=${encodeURIComponent(offer.id)}&frequency=monthly`;
    return `<article class="card" data-offer-card data-monthly="${offer.monthly}" data-annual="${offer.annual}">
      <span class="badge">${escapeHtml(offer.users)}</span>
      <h3>${escapeHtml(offer.name)}</h3>
      <p>${escapeHtml(offer.summary)}</p>
      <div class="price"><strong data-price></strong><span data-period></span></div>
      <ul class="offer-list">${features}</ul>
      <a class="button" data-subscribe-link href="${href}">S'abonner</a>
    </article>`;
  }).join("");

  return `${hero(page, "#offres", "#offres")}
<section class="section" id="offres">
  <div class="section-inner">
    <h2>Choisir une offre</h2>
    <div class="pricing-controls" aria-label="Frequence de facturation">
      <button type="button" data-frequency="monthly">Mensuel</button>
      <button type="button" data-frequency="annual">Annuel</button>
    </div>
    <div class="grid">${cards}</div>
  </div>
</section>
${integrationsSection()}
<section class="section alt">
  <div class="section-inner contact-panel">
    <h2>Besoin d'une offre sur mesure ?</h2>
    <p>Une configuration plus large, plusieurs entites ou un accompagnement particulier peuvent etre cadrees directement ensemble.</p>
    <a class="button secondary" href="/contact/">Nous contacter</a>
  </div>
</section>`;
}

function guidesHubPage(page) {
  const community = (content.communityLinks || []).map((item) => `<article class="link-card">
    <h3>${escapeHtml(item.title)}</h3>
    <p>${escapeHtml(item.text)}</p>
    <a class="text-link" href="${escapeHtml(item.href)}">Consulter</a>
  </article>`).join("");
  const tutorials = (content.tutorials || []).map((item) => `<article class="tutorial-card">
    <h3>${escapeHtml(item.title)}</h3>
    <p>${escapeHtml(item.text)}</p>
    <a class="text-link" href="${escapeHtml(item.href)}">Voir le tutoriel</a>
  </article>`).join("");

  return `${hero(page, content.externalLinks.dolibarrYoutube, "/tarifs/#offres")}
<section class="section">
  <div class="section-inner">
    <div class="section-heading">
      <p class="eyebrow">Communaute Dolibarr</p>
      <h2>Une communaute active autour d'un ERP libre</h2>
      <p class="lead">Dolibarr est porte par son association, ses utilisateurs, ses developpeurs, sa place de marche et ses ressources de formation. Ces liens vous aident a trouver de l'aide et des extensions utiles.</p>
    </div>
    <div class="grid">${community}</div>
  </div>
</section>
<section class="section alt">
  <div class="section-inner">
    <div class="section-heading">
      <p class="eyebrow">Tutoriels officiels</p>
      <h2>Prendre en main les fonctionnalites Dolibarr</h2>
      <p class="lead">Les tuiles ci-dessous regroupent les tutoriels video de la playlist officielle francaise Dolibarr.</p>
    </div>
    <div class="tutorial-grid">${tutorials}</div>
  </div>
</section>`;
}

function modulesPage(page) {
  const modules = (content.modulesList || []).map((item) => `<article class="module-card">
    ${item.image ? `<img src="${escapeHtml(item.image)}" alt="" loading="lazy">` : '<span class="module-icon">LMDB</span>'}
    <div>
      <h3>${escapeHtml(item.title)}</h3>
      <p>${escapeHtml(item.text)}</p>
      <a class="text-link" href="${escapeHtml(item.href)}">Voir le module</a>
    </div>
  </article>`).join("");

  return `${hero(page)}
<section class="section">
  <div class="section-inner">
    <div class="section-heading">
      <p class="eyebrow">Modules Dolibarr</p>
      <h2>Des extensions publiees sur le DoliStore</h2>
      <p class="lead">Ces modules completent Dolibarr avec des usages metier autour des temps, appels d'offres et diffusions de documents.</p>
    </div>
    <div class="module-grid">${modules}</div>
    <div class="note-panel">
      <strong>Achat via DoliStore</strong>
      <p>L'achat de modules via le DoliStore contribue au projet open source Dolibarr et soutient l'association Dolibarr, tout en donnant acces aux mises a jour et au support indiques sur chaque fiche.</p>
    </div>
  </div>
</section>`;
}

function contactPage(page) {
  const endpoint = content.site.contactEndpoint || "/custom/lmdbwebsite/public/contact.php";
  return `${hero(page, endpoint)}
<section class="section">
  <div class="section-inner split">
    <div>
      <p class="eyebrow">Contact</p>
      <h2>Envoyer une demande</h2>
      <p class="lead">Le formulaire cree une demande dans Dolibarr pour qualifier votre besoin et vous recontacter proprement.</p>
    </div>
    <div class="contact-embed">
      <iframe class="contact-frame" src="${escapeHtml(endpoint)}" title="Formulaire de contact Les Metiers du Batiment" loading="lazy"></iframe>
      <p><a class="text-link" href="${escapeHtml(endpoint)}">Ouvrir le formulaire dans une nouvelle page</a></p>
    </div>
  </div>
</section>`;
}

function termsPage(page) {
  const cards = (content.termsLinks || []).map((item) => `<article class="link-card">
    <h3>${escapeHtml(item.title)}</h3>
    <p>${escapeHtml(item.text)}</p>
    <a class="text-link" href="${escapeHtml(item.href)}">Consulter</a>
  </article>`).join("");

  return `${hero(page, "/contact/", "/tarifs/#offres")}
<section class="section">
  <div class="section-inner">
    <div class="section-heading">
      <p class="eyebrow">Conditions</p>
      <h2>Documents et conditions des services</h2>
      <p class="lead">Ces liens regroupent les informations utiles pour consulter les conditions de vente, d'utilisation et de paiement des services concernes.</p>
    </div>
    <div class="grid">${cards}</div>
  </div>
</section>`;
}

function legalPage(page) {
  return `<section class="section">
  <div class="section-inner article">
    <h1>${escapeHtml(page.headline)}</h1>
    <p>${escapeHtml(page.intro)}</p>
    <p>EI Les Metiers du Batiment - contact@lesmetiersdubatiment.fr.</p>
    <p>Responsable RGPD : Pierre Ardoin, contact+dpo@lesmetiersdubatiment.fr.</p>
    <p>Adresse : 174 Rue des Violettes, 40600 Biscarrosse.</p>
    <p>SIRET : 840 808 890 00028. NAF-APE : 7112B.</p>
  </div>
</section>`;
}

function guidePage(page) {
  const body = page.body.map((paragraph) => `<p>${escapeHtml(paragraph)}</p>`).join("");
  return `${hero({ ...page, id: page.slug.replaceAll("/", "_"), slug: page.slug, cta: "Voir les tarifs" }, "/tarifs/#offres", "/guides/")}
<section class="section">
  <div class="section-inner article">
    ${body}
  </div>
</section>`;
}

function renderPage(page) {
  if (page.id === "home") return homePage(page);
  if (page.id === "fonctionnement") return fonctionnementPage(page);
  if (page.id === "tarifs") return pricingPage(page);
  if (page.id === "guides") return guidesHubPage(page);
  if (page.id === "modules") return modulesPage(page);
  if (page.id === "contact") return contactPage(page);
  if (page.id === "conditions-generales") return termsPage(page);
  if (page.id === "mentions-legales") return legalPage(page);
  return hero(page);
}

async function writePage(base, page, html) {
  const file = pagePath(base, page.slug);
  await mkdir(path.dirname(file), { recursive: true });
  await writeFile(file, html);
}

await rm(path.join(root, "dist"), { recursive: true, force: true });
await rm(outDolibarrResource, { recursive: true, force: true });
await mkdir(path.join(outSite, "assets"), { recursive: true });
await mkdir(path.join(outSite, "assets/img"), { recursive: true });
for (const output of dolibarrOutputs) {
  await mkdir(path.join(output, "assets"), { recursive: true });
  await mkdir(path.join(output, "assets/img"), { recursive: true });
  await mkdir(path.join(output, "pages"), { recursive: true });
}

await writeFile(path.join(outSite, "assets/styles.css"), await readFile(path.join(root, "src/styles.css"), "utf8"));
await writeFile(path.join(outSite, "assets/main.js"), await readFile(path.join(root, "src/main.js"), "utf8"));
for (const output of dolibarrOutputs) {
  await writeFile(path.join(output, "assets/styles.css"), await readFile(path.join(root, "src/styles.css"), "utf8"));
  await writeFile(path.join(output, "assets/main.js"), await readFile(path.join(root, "src/main.js"), "utf8"));
}
await cp(path.join(sourceAssets, "img"), path.join(outSite, "assets/img"), { recursive: true });
await chmodImageTree(path.join(outSite, "assets/img"));
for (const output of dolibarrOutputs) {
  await cp(path.join(sourceAssets, "img"), path.join(output, "assets/img"), { recursive: true });
  await chmodImageTree(path.join(output, "assets/img"));
}

for (const page of content.pages) {
  const body = renderPage(page);
  await writePage(outSite, page, layout(page, body));
  for (const output of dolibarrOutputs) {
    await writeFile(path.join(output, "pages", `${page.id}.html`), body);
  }
}

for (const guide of content.guides) {
  const page = { ...guide, id: guide.slug.replaceAll("/", "_"), slug: guide.slug };
  const body = guidePage(page);
  await writePage(outSite, page, layout(page, body));
  for (const output of dolibarrOutputs) {
    await writeFile(path.join(output, "pages", `${page.id}.html`), body);
  }
}

const sitemapUrls = [
  ...content.pages.map((page) => `${content.site.url}${slugToHref(page.slug)}`),
  ...content.guides.map((page) => `${content.site.url}${slugToHref(page.slug)}`)
];
await writeFile(path.join(outSite, "sitemap.xml"), `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${sitemapUrls.map((url) => `  <url><loc>${url}</loc></url>`).join("\n")}
</urlset>
`);
await writeFile(path.join(outSite, "robots.txt"), `User-agent: *
Allow: /
Sitemap: ${content.site.url}/sitemap.xml
`);

const dolibarrReadme = `# Export Website Dolibarr

Importer les fichiers de pages comme contenus ou templates du module Website.
Les commentaires DOLIBARR_EDITABLE indiquent les blocs qui doivent rester modifiables dans Dolibarr.

Assets:
- assets/styles.css
- assets/main.js
- assets/img
`;
for (const output of dolibarrOutputs) {
  await writeFile(path.join(output, "README.md"), dolibarrReadme);
}

console.log(`Generated ${outSite}`);
console.log(`Generated ${outDolibarr}`);
console.log(`Generated ${outDolibarrResource}`);
