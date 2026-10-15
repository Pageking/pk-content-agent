(() => {
  function revealGlobalBlock() {
    const prefix = '#pkca-global-';
    if (!location.hash.startsWith(prefix)) return;
    const id = decodeURIComponent(location.hash.slice(prefix.length));
    const input = Array.from(document.querySelectorAll('.acf-field[data-name="global_block_id"] input')).find(input => input.value === id);
    const row = input?.closest('.acf-row');
    if (!row) return;
    row.classList.remove('-collapsed');
    row.querySelectorAll('.acf-flexible-content > .values > .layout').forEach(layout => layout.classList.remove('-collapsed'));
    row.style.outline = '3px solid #294cff';
    row.style.outlineOffset = '4px';
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (!row.querySelector('.pkca-global-notice')) {
      const notice = document.createElement('div');
      notice.className = 'notice notice-info pkca-global-notice';
      notice.textContent = 'Je bewerkt een gedeeld blok. Wijzigingen gelden op alle pagina’s waar dit blok gebruikt wordt.';
      row.querySelector('td.acf-fields')?.prepend(notice);
    }
  }
  if (window.acf) window.acf.addAction('ready', revealGlobalBlock);
  else document.addEventListener('DOMContentLoaded', revealGlobalBlock);
  window.addEventListener('hashchange', revealGlobalBlock);
})();
