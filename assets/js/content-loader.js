(() => {
  const CONTENT_URLS = ['content/content.json', '../content/content.json', '/content/content.json'];

  async function fetchFirst(urls) {
    for (const url of urls) {
      try {
        const response = await fetch(url, { cache: 'no-store' });
        if (response.ok) {
          return response.json();
        }
      } catch (_) {
        // Try the next URL candidate.
      }
    }
    return null;
  }

  function pageSlug() {
    const path = window.location.pathname || '';
    const file = path.split('/').pop() || 'index.html';
    return file.replace(/\.html$/i, '') || 'index';
  }

  function applyPageContent(data) {
    if (!data || !data.pages) return;
    const slug = pageSlug();
    const page = data.pages[slug];
    if (!page || !page.fields) return;

    document.querySelectorAll('[data-content-key]').forEach((el) => {
      const key = el.getAttribute('data-content-key');
      if (!key || !(key in page.fields)) return;
      el.innerHTML = page.fields[key];
    });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const data = await fetchFirst(CONTENT_URLS);
    applyPageContent(data);
  });
})();
