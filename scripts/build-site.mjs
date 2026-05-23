import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const content = JSON.parse(await readFile(path.join(root, "content/site.json"), "utf8"));
const outSite = path.join(root, "dist/site");
const outDolibarr = path.join(root, "dist/dolibarr-website");
const outDolibarrResource = path.join(root, "resources/dolibarr-website");
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

function nav(currentSlug) {
  return content.navigation.map((item) => {
    const current = item.href === slugToHref(currentSlug) ? ' aria-current="page"' : "";
    return `<a href="${item.href}"${current}>${escapeHtml(item.label)}</a>`;
  }).join("");
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

  return `<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(page.title)} | ${escapeHtml(content.site.name)}</title>
  <meta name="description" content="${escapeHtml(page.description)}">
  <link rel="canonical" href="${canonical}">
  <meta property="og:title" content="${escapeHtml(page.title)}">
  <meta property="og:description" content="${escapeHtml(page.description)}">
  <meta property="og:url" content="${canonical}">
  <meta property="og:type" content="website">
  <meta property="og:image" content="${escapeHtml(content.site.productImage)}">
  <link rel="stylesheet" href="/assets/styles.css">
  <script type="application/ld+json">${JSON.stringify(schema)}</script>
</head>
<body>
  <header class="site-header">
    <div class="nav-wrap">
      <a class="brand" href="/"><span class="brand-mark">LM</span><span>${escapeHtml(content.site.name)}</span></a>
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
        <a href="/mentions-legales/">Mentions legales</a><br>
        <a href="/contact/">Contact</a>
      </div>
    </div>
  </footer>
  <script src="/assets/main.js" defer></script>
</body>
</html>`;
}

function hero(page, actionHref = "/contact/") {
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
        <a class="button secondary" href="/tarifs/">Voir les tarifs</a>
      </div>
    </div>
    <figure class="hero-media">
      <img src="${escapeHtml(content.site.heroImage)}" alt="Interface Dolibarr configuree pour Les Metiers du Batiment" width="1280" height="800" loading="eager">
    </figure>
  </div>
</section>`;
}

function homePage(page) {
  const cards = page.sections[0].items.map((item) => `<article class="card"><h3>${escapeHtml(item)}</h3><p>Un socle structure dans Dolibarr, avec des donnees exploitables pour vos equipes.</p></article>`).join("");
  const steps = [
    ["Choix de l'offre", "Le client choisit Base, Standard ou Pro, puis la frequence et le mode de paiement."],
    ["Creation Dolibarr", "Le module cree le tiers, le contact, le contrat, la facture initiale et la facture modele."],
    ["Paiement Stancer", "Le client paie par carte ou active un moyen de paiement recurrent via Stancer."],
    ["Onboarding", "Apres paiement, le SIRET et le logo alimentent un dossier interne a traiter."]
  ].map(([title, text]) => `<article class="card step"><h3>${escapeHtml(title)}</h3><p>${escapeHtml(text)}</p></article>`).join("");

  return `${hero(page, "/custom/lmdbwebsite/public/subscribe.php")}
<section class="section">
  <div class="section-inner">
    <h2>${escapeHtml(page.sections[0].title)}</h2>
    <div class="grid">${cards}</div>
  </div>
</section>
<section class="section alt">
  <div class="section-inner steps">
    <h2>Un tunnel d'abonnement connecte a Dolibarr</h2>
    <div class="grid">${steps}</div>
  </div>
</section>`;
}

function fonctionnementPage(page) {
  const rows = page.features.map(([title, text]) => `<div class="feature-row"><h3>${escapeHtml(title)}</h3><p>${escapeHtml(text)}</p></div>`).join("");
  return `${hero(page)}
<section class="section">
  <div class="section-inner split">
    <div>
      <h2>Fonctionnalites principales</h2>
      <p class="lead">Le site garde une presentation claire, tandis que Dolibarr reste la source des offres, contrats, factures et paiements.</p>
    </div>
    <div class="feature-list">${rows}</div>
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

  return `${hero(page, "/custom/lmdbwebsite/public/subscribe.php")}
<section class="section">
  <div class="section-inner">
    <h2>Choisir une offre</h2>
    <div class="pricing-controls" aria-label="Frequence de facturation">
      <button type="button" data-frequency="monthly">Mensuel</button>
      <button type="button" data-frequency="annual">Annuel</button>
    </div>
    <div class="grid">${cards}</div>
  </div>
</section>
<section class="section alt">
  <div class="section-inner contact-panel">
    <h2>Besoin d'une offre sur mesure ?</h2>
    <p>Une configuration plus large, plusieurs entites ou un accompagnement particulier peuvent etre cadrees directement ensemble.</p>
    <a class="button secondary" href="/contact/">Nous contacter</a>
  </div>
</section>`;
}

function contactPage(page) {
  return `${hero(page)}
<section class="section">
  <div class="section-inner split">
    <div class="contact-panel">
      <h2>Contact</h2>
      <p>Envoyez votre demande depuis Dolibarr ou par email : <a href="mailto:${content.site.email}">${content.site.email}</a>.</p>
      <a class="button" href="/custom/lmdbwebsite/public/subscribe.php">Demander une demo</a>
    </div>
    <div>
      <h2>Apres abonnement</h2>
      <p class="lead">Le formulaire post-paiement collecte le SIRET et le logo pour preparer la creation de l'entite Dolibarr.</p>
    </div>
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
  return `${hero({ ...page, id: page.slug.replaceAll("/", "_"), slug: page.slug, cta: "Voir les tarifs" }, "/tarifs/")}
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
  if (page.id === "contact") return contactPage(page);
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
for (const output of dolibarrOutputs) {
  await mkdir(path.join(output, "assets"), { recursive: true });
  await mkdir(path.join(output, "pages"), { recursive: true });
}

await writeFile(path.join(outSite, "assets/styles.css"), await readFile(path.join(root, "src/styles.css"), "utf8"));
await writeFile(path.join(outSite, "assets/main.js"), await readFile(path.join(root, "src/main.js"), "utf8"));
for (const output of dolibarrOutputs) {
  await writeFile(path.join(output, "assets/styles.css"), await readFile(path.join(root, "src/styles.css"), "utf8"));
  await writeFile(path.join(output, "assets/main.js"), await readFile(path.join(root, "src/main.js"), "utf8"));
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
`;
for (const output of dolibarrOutputs) {
  await writeFile(path.join(output, "README.md"), dolibarrReadme);
}

console.log(`Generated ${outSite}`);
console.log(`Generated ${outDolibarr}`);
console.log(`Generated ${outDolibarrResource}`);
