'use strict';
// Point d’entrée : charge les templates HTML, résout les inclusions puis monte l’application.
(function () {
  const TEMPLATES = 'src/templates/';
  const INCLUDE = /^[ \t]*<!-- @include (\S+) -->\n/gm;

  async function fetchText(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error(url + ' : HTTP ' + res.status);
    return res.text();
  }

  // Remplace chaque ligne `<!-- @include chemin -->` par le contenu du fichier (récursif).
  async function loadTemplate(path) {
    const src = await fetchText(TEMPLATES + path);
    const paths = [...src.matchAll(INCLUDE)].map(m => m[1]);
    const parts = await Promise.all(paths.map(loadTemplate));
    let i = 0;
    return src.replace(INCLUDE, () => parts[i++]);
  }

  function showError(err) {
    console.error(err);
    const local = location.protocol === 'file:';
    document.getElementById('dc-root').innerHTML =
      '<div style="max-width:560px;margin:15vh auto;padding:24px;font:15px/1.5 system-ui,sans-serif;' +
      'border:1px solid #e5e7eb;border-radius:12px;background:#fff;color:#1f2937">' +
      '<strong>Impossible de démarrer le back-office.</strong><p>' +
      (local
        ? 'Les templates sont chargés via <code>fetch()</code>, ce que le navigateur bloque en <code>file://</code>. ' +
          'Lancez un serveur statique dans le dossier du projet, par exemple <code>npx serve .</code> ' +
          'ou <code>python3 -m http.server 8080</code>.'
        : String(err && err.message || err)) +
      '</p></div>';
  }

  async function start() {
    const names = Object.keys(OCH.components);
    const [dc, template, componentTemplates] = await Promise.all([
      window.dcReady,
      loadTemplate('app.html'),
      Promise.all(names.map(n => fetchText(OCH.components[n].template)))
    ]);

    const components = {};
    names.forEach((name, i) => {
      const def = OCH.components[name];
      components[name] = { template: componentTemplates[i], props: def.props, Logic: def.logic(dc.DCLogic) };
    });

    dc.mount({
      name: 'BackOffice',
      el: document.getElementById('dc-root'),
      template,
      props: OCH.rootProps,
      Logic: OCH.buildApp(dc.DCLogic),
      components
    });
  }

  start().catch(showError);
})();
