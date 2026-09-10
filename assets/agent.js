(() => {
  const config = window.PKContentAgent;
  if (!config) return;

  // WordPress can sit behind a local frontend proxy with another port. REST
  // calls must use the page origin so login cookies and the REST nonce apply.
  const configuredRestUrl = new URL(config.restUrl, window.location.origin);
  config.restUrl = configuredRestUrl.pathname.replace(/\/?$/, '/');

  const historyKey = `pkca-history-${config.version || 'current'}-${config.postId}`;
  const positionKey = `pkca-position-${config.postId}`;
  const sizeKey = `pkca-size-${config.postId}`;
  const state = { open: false, collapsed: false, context: null, upload: null, selection: null, selectedTarget: null, layoutButtons: [], formButtons: [], activeLayout: null, previewTargets: new Map() };
  const root = document.createElement('div');
  root.className = 'pkca';
  root.innerHTML = `
    <button class="pkca__toggle" type="button" aria-expanded="false" aria-controls="pkca-panel">Pagina aanpassen</button>
    <aside class="pkca__panel" id="pkca-panel" aria-label="Content-assistent" hidden>
      <header class="pkca__header"><div class="pkca__heading"><strong>Content-assistent</strong><small>${escapeHtml(config.title)}</small></div><div class="pkca__window-actions"><button class="pkca__restart" type="button" aria-label="Nieuwe chat" title="Nieuwe chat">↻</button><button class="pkca__collapse" type="button" aria-label="Inklappen">−</button><button class="pkca__close" type="button" aria-label="Sluiten">×</button></div></header>
      <div class="pkca__messages" aria-live="polite"></div>
      <div class="pkca__changes" hidden></div>
      <section class="pkca__layout-editor" hidden></section>
      <form class="pkca__form">
        <div class="pkca__selection" hidden></div>
        <button class="pkca__select" type="button">Aanwijzen</button>
        <label class="pkca__upload"><input type="file" accept="image/*"><span>Afbeelding</span></label>
        <textarea rows="3" placeholder="Bijv. pas in de eerste sectie de titel aan…" required></textarea>
        <button class="pkca__send" type="submit">Versturen</button>
      </form><div class="pkca__resize" aria-hidden="true"></div>
    </aside>`;
  document.body.append(root);

  const panel = root.querySelector('.pkca__panel');
  const toggle = root.querySelector('.pkca__toggle');
  const messages = root.querySelector('.pkca__messages');
  const form = root.querySelector('.pkca__form');
  const textarea = form.querySelector('textarea');
  const fileInput = form.querySelector('input[type=file]');
  const changes = root.querySelector('.pkca__changes');
  const layoutEditor = root.querySelector('.pkca__layout-editor');
  const selection = form.querySelector('.pkca__selection');

  restoreHistory();
  restorePosition();
  restoreSize();

  toggle.addEventListener('click', () => setOpen(!state.open));
  root.querySelector('.pkca__close').addEventListener('click', event => {
    event.preventDefault();
    event.stopPropagation();
    closeAssistant();
  });
  root.querySelector('.pkca__collapse').addEventListener('click', toggleCollapsed);
  root.querySelector('.pkca__restart').addEventListener('click', restartChat);
  root.querySelector('.pkca__select').addEventListener('click', startSelection);
  makeDraggable();
  makeResizable();
  window.addEventListener('scroll', syncLayoutButtons, { passive: true });
  window.addEventListener('resize', syncLayoutButtons);
  window.addEventListener('scroll', syncFormButtons, { passive: true });
  window.addEventListener('resize', syncFormButtons);
  installFormButtons();
  if (config.formsEditUrl) {
    let formScanQueued = false;
    new MutationObserver(() => {
      if (formScanQueued) return;
      formScanQueued = true;
      requestAnimationFrame(() => {
        formScanQueued = false;
        installFormButtons();
      });
    }).observe(document.body, { childList: true, subtree: true });
  }
  loadContext();

  function setOpen(open) {
    state.open = open;
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
    toggle.hidden = open;
    if (open) loadContext();
    syncLayoutButtons();
  }

  function closeAssistant() {
    closeLayoutEditor();
    clearSelection();
    if (state.collapsed) toggleCollapsed();
    setOpen(false);
  }

  function toggleCollapsed() {
    state.collapsed = !state.collapsed;
    panel.classList.toggle('pkca__panel--collapsed', state.collapsed);
    root.querySelector('.pkca__collapse').textContent = state.collapsed ? '+' : '−';
    root.querySelector('.pkca__collapse').setAttribute('aria-label', state.collapsed ? 'Uitklappen' : 'Inklappen');
  }

  function restartChat() {
    sessionStorage.removeItem(historyKey);
    messages.innerHTML = '';
    state.selectedTarget = null;
    clearSelection();
    addMessage('Nieuwe chat gestart. Wijs een onderdeel aan of beschrijf wat je wilt aanpassen.', 'agent');
    textarea.focus();
  }

  function startSelection() {
    // Selection must become active immediately. The server can resolve the
    // final ACF path, so loading context may safely continue in the background.
    if (!state.context) loadContext();
    root.classList.add('pkca--selecting');
    panel.hidden = true;
    toggle.textContent = 'Klik een onderdeel aan…';

    const selectElement = event => {
      if (event.target.closest('.pkca')) return;
      event.preventDefault();
      event.stopPropagation();
      document.removeEventListener('click', selectElement, true);

      const target = event.target.closest('a, button, h1, h2, h3, h4, h5, p, img') || event.target;
      const sections = getSections();
	  const containingSections = sections
		.map((sectionElement, index) => ({ sectionElement, index }))
		.filter(item => item.sectionElement.contains(target));
	  const sectionIndex = containingSections.reduce((best, item) => {
		if (!best || best.sectionElement.contains(item.sectionElement)) return item;
		return best;
	  }, null)?.index ?? -1;
      const section = sections[sectionIndex];
      if (!section) {
        root.classList.remove('pkca--selecting');
        toggle.textContent = 'Pagina aanpassen';
        panel.hidden = false;
        state.open = true;
        addMessage('Dit onderdeel hoort niet bij een bewerkbare contentsectie.', 'error');
        return;
      }
      const isImage = target.tagName === 'IMG';
      const selectedValue = isImage
        ? (target.currentSrc || target.src || '')
        : selectableText(target);

	  const sectionNumber = Number(section.dataset.pkcaSectionNumber || 0);
	  const sectionContext = state.context?.sections?.find(candidate => Number(candidate.number) === sectionNumber);
      const sameVisibleTargets = isImage
        ? Array.from(section.querySelectorAll('img')).filter(element => imageKeyFromUrl(element.currentSrc || element.src) === imageKeyFromUrl(selectedValue))
        : Array.from(section.querySelectorAll(target.tagName.toLowerCase())).filter(element => selectableText(element) === selectedValue);
      const occurrence = Math.max( 0, sameVisibleTargets.indexOf(target) );
      const fieldMatches = (sectionContext?.fields || []).filter(field => {
        if (isImage) {
          return field.type === 'image' && imageKeyFromUrl(field.url || '') === imageKeyFromUrl(selectedValue);
        }
        if (field.type !== 'text') return false;
        // The DOM can still contain the stored label while this field already has
        // a pending preview value. Match both versions so clicking that exact
        // button never degrades to a layout-wide, model-guessed selection.
        return [field.value, field.original_value]
          .filter(value => value !== undefined && value !== null)
          .some(value => normalizeText(stripHtml(String(value))) === selectedValue);
      });
      let matchedField = fieldMatches.length === 1 ? fieldMatches[0] : null;
      if (!matchedField && fieldMatches.length > 1 && !isImage) {
        const selector = 'a, button, h1, h2, h3, h4, h5, h6, p, li, span';
        const matchingElements = Array.from(section.querySelectorAll(selector)).filter(element => {
          if (element.tagName !== target.tagName) return false;
          return selectableText(element) === selectedValue;
        });
        const occurrence = matchingElements.indexOf(target);
        if (occurrence >= 0 && fieldMatches[occurrence]) matchedField = fieldMatches[occurrence];
      }

      state.selection = {
		section: sectionNumber,
		layout: sectionContext?.layout || '',
		scope: matchedField ? 'field' : 'layout',
        type: isImage ? 'image' : 'text',
        value: selectedValue,
        alt: isImage ? (target.alt || '') : '',
        occurrence,
        fieldPath: matchedField?.path || null,
        fieldName: matchedField?.name || null
      };
	  state.selectedTarget = { section: sectionNumber, type: state.selection.type, fieldPath: matchedField?.path || null, node: target };
      if (matchedField && sectionContext) {
        const selectionKey = `${sectionContext.row_index}:${sectionContext.layout_index}:${matchedField.path.join('.')}`;
        if (isImage) {
          state.previewTargets.set(selectionKey, { type: 'image', node: target });
        } else {
          const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
          let selectedTextNode = null;
          while ((selectedTextNode = walker.nextNode()) && !normalizeText(selectedTextNode.nodeValue)) {
            // Skip whitespace-only wrapper nodes.
          }
          state.previewTargets.set(selectionKey, { type: 'text', node: selectedTextNode || target });
        }
      }
      target.classList.add('pkca-selected-target');
      selection.hidden = false;
      const selectionLabel = isImage ? (state.selection.alt || 'afbeelding') : selectedValue;
      selection.innerHTML = `<span title="${escapeHtml(selectionLabel)}">Aangewezen: ${escapeHtml(selectionLabel)}</span><button type="button" aria-label="Selectie verwijderen">×</button>`;
      selection.querySelector('button').addEventListener('click', clearSelection);
      root.classList.remove('pkca--selecting');
      toggle.textContent = 'Pagina aanpassen';
      panel.hidden = false;
      state.open = true;
      textarea.focus();
      addMessage(`Je hebt “${isImage ? (state.selection.alt || 'afbeelding') : selectedValue}” aangewezen. Wat wil je hiermee doen?`, 'agent');
    };

    document.addEventListener('click', selectElement, true);
  }

  function clearSelection() {
    state.selection = null;
    state.selectedTarget = null;
    selection.hidden = true;
    selection.innerHTML = '';
    document.querySelectorAll('.pkca-selected-target').forEach(element => element.classList.remove('pkca-selected-target'));
  }

  function getSections() {
	return getSectionEntries().map(entry => entry.element);
	}

	function getSectionEntries() {
	  if (!state.context?.sections) return [];
	  const containers = Array.from(document.querySelectorAll('.flex-repeater > .flex-content'));
	  if (!containers.length) return [];
	  const topContexts = state.context.sections.filter(section => !section.nested);
	  const nestedContexts = state.context.sections.filter(section => section.nested);
	  const entries = [];
	  const topElements = Array.from(containers[0].children);
	  const unusedTopElements = new Set(topElements);
	  topContexts.forEach(context => {
		const element = findLayoutElement(context, topElements, unusedTopElements);
		if (!element) return;
		unusedTopElements.delete(element);
		entries.push({ element, context, contextIndex: state.context.sections.indexOf(context) });
	  });
	  const nestedElements = containers.slice(1).flatMap(container => Array.from(container.children));
	  const unusedElements = new Set(nestedElements);
	  nestedContexts.forEach(context => {
		const element = findLayoutElement(context, nestedElements, unusedElements);
		if (!element) return;
		unusedElements.delete(element);
		entries.push({ element, context, contextIndex: state.context.sections.indexOf(context) });
	  });
	  entries.forEach(entry => { entry.element.dataset.pkcaSectionNumber = String(entry.context.number); });
	  return entries;
	}

	function findLayoutElement(context, candidates, unused) {
	  const layout = String(context.layout || '').toLowerCase();
	  const compactLayout = layout.replace(/[^a-z0-9]/g, '');
	  const words = layout.split(/[_-]+/).filter(Boolean);
	  return candidates.find(candidate => {
		if (!unused.has(candidate)) return false;
		const classes = Array.from(candidate.classList).join(' ').toLowerCase();
		const compactClasses = classes.replace(/[^a-z0-9]/g, '');
		return (compactLayout && compactClasses.includes(compactLayout)) || words.every(word => classes.includes(word));
	  });
	}

	function getSectionByNumber(sectionNumber) {
	  return getSectionEntries().find(entry => Number(entry.context.number) === Number(sectionNumber))?.element || null;
  }

  async function request(path, options = {}) {
    const response = await fetch(config.restUrl + path, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...options,
      headers: { 'X-WP-Nonce': config.nonce, ...(options.headers || {}) }
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const message = normalizeText(stripHtml(String(data.message || '')));
      throw new Error(message || 'Er ging iets mis bij het verwerken van de opdracht.');
    }
    return data;
  }

  async function loadContext() {
    try {
      state.context = await request(`context?post_id=${config.postId}`);
      applyPreview(state.context.changes || []);
      renderChanges(state.context.changes || []);
      installLayoutButtons();
    } catch (error) {
      addMessage(error.message, 'error');
    }
  }

  function installLayoutButtons() {
    state.layoutButtons.forEach(button => button.remove());
    state.layoutButtons = [];
	const entries = getSectionEntries();
	entries.forEach((entry, index) => {
	  const section = entry.element;
	  const layout = entry.context;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'pkca__layout-button';
      button.innerHTML = '<span aria-hidden="true">✎</span><span class="screen-reader-text">Layout bewerken</span>';
	  button.title = `${humanize(layout.layout)} bewerken`;
      button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
		openLayoutEditor(entry.contextIndex);
      });
      root.append(button);
      state.layoutButtons.push(button);
    });
    syncLayoutButtons();
  }

  function syncLayoutButtons() {
	const sections = getSectionEntries().map(entry => entry.element);
    state.layoutButtons.forEach((button, index) => {
      const rect = sections[index]?.getBoundingClientRect();
      if (!rect || rect.bottom < 0 || rect.top > window.innerHeight) {
        button.hidden = true;
        return;
      }
      button.hidden = false;
      button.style.left = `${Math.max(8, Math.min(window.innerWidth - 42, rect.right - 42))}px`;
      button.style.top = `${Math.max(8, rect.top + 10)}px`;
    });
  }

  function installFormButtons() {
    if (!config.formsEditUrl) return;
    const wrappers = Array.from(document.querySelectorAll('.gform_wrapper'));
    const activeWrappers = new Set(wrappers);
    state.formButtons = state.formButtons.filter(item => {
      if (activeWrappers.has(item.form) && item.form.isConnected) return true;
      item.button.remove();
      return false;
    });
    wrappers.forEach(form => {
      if (state.formButtons.some(item => item.form === form)) return;
      const idSource = form.dataset.formid || form.id || form.querySelector('form[id^="gform_"]')?.id || '';
      const match = String(idSource).match(/(?:gform_wrapper_|gform_)?(\d+)$/);
      if (!match) return;
      const formId = Number(match[1]);
      if (!formId) return;
      const button = document.createElement('a');
      button.className = 'pkca__layout-button pkca__form-button';
      button.href = `${config.formsEditUrl}${formId}`;
      button.target = '_blank';
      button.rel = 'noopener';
      button.innerHTML = '<span aria-hidden="true">✎</span><span class="screen-reader-text">Formulier bewerken</span>';
      button.title = `Formulier ${formId} bewerken`;
      button.addEventListener('click', event => event.stopPropagation());
      root.append(button);
      state.formButtons.push({ button, form });
    });
    syncFormButtons();
  }

  function syncFormButtons() {
    state.formButtons.forEach(({ button, form }) => {
      const rect = form.getBoundingClientRect();
      if (rect.bottom < 0 || rect.top > window.innerHeight || rect.width < 1 || rect.height < 1) {
        button.hidden = true;
        return;
      }
      button.hidden = false;
      button.style.left = `${Math.max(8, Math.min(window.innerWidth - 42, rect.right - 42))}px`;
      button.style.top = `${Math.max(8, rect.top + 10)}px`;
    });
  }

  function openLayoutEditor(index) {
    const layout = state.context?.sections?.[index];
    if (!layout) return;
    state.activeLayout = index;
    setOpen(true);
    if (state.collapsed) toggleCollapsed();
    messages.hidden = true;
    form.hidden = true;
    layoutEditor.hidden = false;
    const layoutFields = layout.fields || [];
    layoutEditor.innerHTML = `<header class="pkca__layout-editor-header"><div><strong>Layout ${layout.number}</strong><small>${escapeHtml(humanize(layout.layout))}</small></div><button type="button" aria-label="Layout-editor sluiten">×</button></header>
      <form class="pkca__layout-form"><div class="pkca__layout-fields">${layoutFieldsMarkup(layoutFields, layout.number)}</div>
        <div class="pkca__layout-status" role="status" aria-live="polite" hidden></div>
        <button class="pkca__layout-save" type="submit">Wijzigingen klaarzetten</button></form>`;
    layoutEditor.querySelector('.pkca__layout-editor-header button').addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      closeLayoutEditor();
    });
    layoutEditor.querySelector('.pkca__layout-form').addEventListener('submit', event => saveLayout(event, layout, layoutFields));
    layoutEditor.querySelectorAll('input[type=file]').forEach(input => input.addEventListener('change', () => uploadLayoutImage(input, layout, layoutFields[Number(input.dataset.fieldIndex)])));
    layoutEditor.querySelectorAll('[data-media-field]').forEach(button => button.addEventListener('click', () => chooseFromMedia(button, layout, layoutFields[Number(button.dataset.mediaField)])));
    layoutEditor.addEventListener('input', event => {
      const input = event.target;
      if (input.matches('[data-repeater-input]')) syncRepeaterValue(input);
      if (input.type === 'checkbox') input.closest('.pkca__layout-toggle')?.querySelector('span')?.replaceChildren(input.checked ? 'Ingeschakeld' : 'Uitgeschakeld');
      applyConditionalLogic();
    });
    layoutEditor.querySelectorAll('[data-gallery-remove]').forEach(button => button.addEventListener('click', () => updateGallery(button, layout, layoutFields[Number(button.dataset.fieldIndex)])));
    layoutEditor.addEventListener('click', event => {
      const button = event.target.closest('[data-repeater-action]');
      if (button) updateRepeater(button, layoutFields[Number(button.dataset.fieldIndex)]);
    });
    initWysiwygControls();
    applyConditionalLogic();
  }

  function layoutFieldsMarkup(fields, sectionNumber) {
    const rendered = new Set();
    return fields.map((field, index) => {
      if (rendered.has(index)) return '';
      if (field.type === 'repeater') {
        fields.forEach((candidate, candidateIndex) => {
          if (candidateIndex !== index && pathStartsWith(candidate.path || [], field.path || [])) rendered.add(candidateIndex);
        });
      }
      const path = field.path.join('/');
      if (path.endsWith('/tag')) {
        const textIndex = fields.findIndex(candidate => candidate.path.join('/') === path.replace(/\/tag$/, '/text'));
        if (textIndex >= 0) {
          rendered.add(textIndex);
          return `<div class="pkca__heading-row">${layoutField(field, index, sectionNumber)}${layoutField(fields[textIndex], textIndex, sectionNumber)}</div>`;
        }
      }
      return layoutField(field, index, sectionNumber);
    }).join('');
  }

  function layoutField(field, index, sectionNumber) {
    const pending = (state.context?.changes || []).find(change => Number(change.section) === sectionNumber && (change.field_path || []).join('.') === field.path.join('.'));
    const value = pending ? pending.new_value : field.value;
    const label = field.label || humanize(field.path.filter(part => !/^\d+$/.test(part)).slice(-2).join(' · '));
    const conditions = encodeURIComponent(JSON.stringify(field.conditional_logic || []));
    const meta = `data-acf-key="${escapeHtml(field.key || '')}" data-conditions="${escapeHtml(conditions)}"`;
    const help = field.instructions ? `<small class="pkca__layout-help">${escapeHtml(field.instructions)}</small>` : '';
    if (field.type === 'image') {
      const imageUrl = pending?.new_url || field.url || '';
      return `<div class="pkca__layout-field pkca__layout-field--image" ${meta}><span>${escapeHtml(label)}</span>${help}${imageUrl ? `<img class="pkca__layout-image-preview" src="${escapeHtml(imageUrl)}" alt="">` : '<span class="pkca__layout-image-empty">Geen afbeelding gekozen</span>'}<small>${escapeHtml(field.preview || 'Afbeelding')}</small><div class="pkca__media-actions"><label><input type="file" accept="image/*" data-field-index="${index}"><em>Uploaden</em></label><button type="button" data-media-field="${index}">Mediabibliotheek</button></div></div>`;
    }
    if (field.type === 'gallery') {
      const galleryImages = pending?.new_images || field.images || [];
      return `<div class="pkca__layout-field pkca__layout-field--gallery" ${meta}><span>${escapeHtml(label)}</span>${help}<div class="pkca__layout-gallery">${galleryImages.map(image => `<figure><img src="${escapeHtml(image.url)}" alt="${escapeHtml(image.title || '')}"><button type="button" data-gallery-remove="${image.id}" data-field-index="${index}" aria-label="Afbeelding verwijderen">×</button></figure>`).join('')}</div><div class="pkca__media-actions"><label><input type="file" accept="image/*" multiple data-field-index="${index}"><em>Uploaden</em></label><button type="button" data-media-field="${index}">Mediabibliotheek</button></div></div>`;
    }
    if (field.type === 'repeater') {
      const rows = pending?.new_value || field.value || [];
      const serialized = JSON.stringify(rows);
      const atMaximum = Number(field.max || 0) > 0 && rows.length >= Number(field.max);
      const limits = field.min || field.max ? `<small class="pkca__repeater-limit">${field.min ? `Minimaal ${field.min}` : ''}${field.min && field.max ? ' · ' : ''}${field.max ? `Maximaal ${field.max}` : ''} rijen</small>` : '';
      return `<div class="pkca__layout-field pkca__layout-repeater" data-repeater-field="${index}" ${meta}><span>${escapeHtml(label)}</span>${help}${limits}<div class="pkca__repeater-rows">${repeaterRowsMarkup(field, index, rows)}</div><button type="button" data-repeater-action="add" data-field-index="${index}"${atMaximum ? ' disabled' : ''}>${atMaximum ? `Maximum van ${field.max} rijen bereikt` : 'Rij toevoegen'}</button><textarea hidden data-repeater-value data-field-index="${index}" data-original="${escapeHtml(serialized)}">${escapeHtml(serialized)}</textarea></div>`;
    }
    const rawValue = String(value ?? '');
    const plainValue = stripHtml(rawValue);
    if (!field.editable) {
      const gallery = Array.isArray(field.images) && field.images.length
        ? `<div class="pkca__layout-gallery">${field.images.map(image => `<img src="${escapeHtml(image.url)}" alt="${escapeHtml(image.title || '')}">`).join('')}</div>`
        : '';
      return `<div class="pkca__layout-field pkca__layout-field--readonly" ${meta}><span>${escapeHtml(label)}</span>${help}${gallery}<div>${escapeHtml(field.preview || plainValue || (gallery ? `${field.images.length} afbeelding(en)` : 'Niet ingevuld'))}</div><small>${escapeHtml(humanize(field.acf_type || 'veld'))} · alleen-lezen</small></div>`;
    }
    let control;
    if (field.type === 'boolean') {
      const checked = value === true || value === 1 || value === '1';
      control = `<label class="pkca__layout-toggle"><input type="checkbox" data-field-index="${index}" data-original="${checked ? '1' : '0'}"${checked ? ' checked' : ''}><span>${checked ? 'Ingeschakeld' : 'Uitgeschakeld'}</span></label>`;
    } else if (field.type === 'option') {
      const choices = field.choices || {};
      control = `<select data-field-index="${index}" data-original="${escapeHtml(plainValue)}">${Object.entries(choices).map(([choiceValue, choiceLabel]) => `<option value="${escapeHtml(choiceValue)}"${String(choiceValue) === plainValue ? ' selected' : ''}>${escapeHtml(choiceLabel)}</option>`).join('')}</select>`;
    } else if (field.type === 'number') {
      control = `<input type="number" placeholder="${escapeHtml(field.placeholder || '')}" value="${escapeHtml(plainValue)}" data-field-index="${index}" data-original="${escapeHtml(plainValue)}">`;
    } else if (field.type === 'email') {
      control = `<input type="email" placeholder="${escapeHtml(field.placeholder || '')}" value="${escapeHtml(plainValue)}" data-field-index="${index}" data-original="${escapeHtml(plainValue)}">`;
    } else if (field.type === 'color') {
      control = `<input type="color" value="${escapeHtml(plainValue || '#000000')}" data-field-index="${index}" data-original="${escapeHtml(plainValue || '#000000')}">`;
    } else if (field.acf_type === 'wysiwyg') {
      control = `<div class="pkca__wysiwyg"><div class="pkca__wysiwyg-toolbar" role="toolbar" aria-label="Tekstopmaak"><select data-wysiwyg-format aria-label="Teksttype" title="Teksttype"><option value="p">Paragraaf</option><option value="h1">Kop 1</option><option value="h2">Kop 2</option><option value="h3">Kop 3</option><option value="h4">Kop 4</option><option value="h5">Kop 5</option><option value="h6">Kop 6</option></select><button type="button" data-command="bold" title="Vet"><strong>B</strong></button><button type="button" data-command="italic" title="Cursief"><em>I</em></button><button type="button" data-command="createLink" title="Link toevoegen">↗</button><button type="button" data-command="unlink" title="Link verwijderen">×↗</button><button type="button" data-command="insertUnorderedList" title="Opsomming">• Lijst</button><button type="button" data-command="insertOrderedList" title="Genummerde lijst">1. Lijst</button><button type="button" class="pkca__wysiwyg-mode" data-wysiwyg-mode="html" aria-pressed="false">HTML</button></div><div class="pkca__wysiwyg-editor" contenteditable="true">${rawValue}</div><textarea hidden class="pkca__wysiwyg-source" data-wysiwyg-value data-field-index="${index}" data-original="${escapeHtml(rawValue)}" aria-label="HTML-broncode">${escapeHtml(rawValue)}</textarea></div>`;
    } else {
      control = field.type === 'link' || plainValue.length < 90
        ? `<input type="text"${field.type === 'link' ? ' inputmode="url"' : ''} placeholder="${escapeHtml(field.placeholder || '')}" value="${escapeHtml(plainValue)}" data-field-index="${index}" data-original="${escapeHtml(plainValue)}">`
        : `<textarea rows="4" placeholder="${escapeHtml(field.placeholder || '')}" data-field-index="${index}" data-original="${escapeHtml(plainValue)}">${escapeHtml(plainValue)}</textarea>`;
    }
	if (field.acf_type === 'wysiwyg') {
	  return `<div class="pkca__layout-field" ${meta}><span>${escapeHtml(label)}${field.required ? ' *' : ''}</span>${help}${control}</div>`;
	}
    return `<label class="pkca__layout-field" ${meta}><span>${escapeHtml(label)}${field.required ? ' *' : ''}</span>${help}${control}</label>`;
  }

  function initWysiwygControls() {
    layoutEditor.querySelectorAll('.pkca__wysiwyg').forEach(editor => {
      const surface = editor.querySelector('.pkca__wysiwyg-editor');
      const value = editor.querySelector('[data-wysiwyg-value]');
      const format = editor.querySelector('[data-wysiwyg-format]');
      const mode = editor.querySelector('[data-wysiwyg-mode]');
      const sync = () => {
        value.value = surface.innerHTML.trim();
        value.dispatchEvent(new Event('input', { bubbles: true }));
      };
      surface.addEventListener('input', sync);
      // A double click should only select a word; it must never trigger an
      // editor command or bubble into the layout/page selection controls.
      surface.addEventListener('dblclick', event => event.stopPropagation());
      format.addEventListener('change', () => {
        surface.focus();
        document.execCommand('formatBlock', false, format.value);
        sync();
      });
      mode.addEventListener('click', () => {
        const showHtml = mode.dataset.wysiwygMode === 'html';
        if (showHtml) {
          sync();
          surface.hidden = true;
          value.hidden = false;
          mode.dataset.wysiwygMode = 'visual';
          mode.textContent = 'Visueel';
          mode.setAttribute('aria-pressed', 'true');
          value.focus();
        } else {
          surface.innerHTML = value.value;
          value.hidden = true;
          surface.hidden = false;
          mode.dataset.wysiwygMode = 'html';
          mode.textContent = 'HTML';
          mode.setAttribute('aria-pressed', 'false');
          surface.focus();
          sync();
        }
      });
      editor.querySelectorAll('[data-command]').forEach(button => button.addEventListener('click', () => {
        surface.focus();
        const command = button.dataset.command;
        let argument = null;
        if (command === 'createLink') {
          argument = window.prompt('Naar welke URL moet de geselecteerde tekst linken?', 'https://');
          if (!argument) return;
        }
        document.execCommand(command, false, argument);
        sync();
      }));
      editor.querySelectorAll('[data-command]').forEach(button => button.addEventListener('dblclick', event => event.preventDefault()));
    });
  }

  async function saveLayout(event, layout, fields) {
    event.preventDefault();
    const submit = event.currentTarget.querySelector('.pkca__layout-save');
    if (submit.disabled) return;
    setLayoutStatus('Wijzigingen controleren…', 'loading');
    const edits = [];
    const controls = event.currentTarget.querySelectorAll('input[data-field-index]:not([type=file]),select[data-field-index],textarea[data-field-index]');
    for (const input of controls) {
      const field = fields[Number(input.dataset.fieldIndex)];
      if (!field) {
        setLayoutStatus('Een veld kon niet aan de layout worden gekoppeld. Sluit de editor en probeer het opnieuw.', 'error');
        return;
      }
      let value = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
      if (value === input.dataset.original) continue;
      if (field.type === 'repeater') {
        try {
          value = JSON.parse(value || '[]');
        } catch (_) {
          setLayoutStatus(`Repeater “${field.label}” bevat ongeldige gegevens. Herstel het veld en probeer opnieuw.`, 'error');
          return;
        }
      }
      edits.push({ section: layout.number, field_path: field.path, field_name: field.name, type: field.type, value, refresh: field.acf_type === 'wysiwyg' });
    }
    if (!edits.length) {
      setLayoutStatus('Er zijn geen velden gewijzigd in deze layout.', 'info');
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Klaarzetten…';
    setLayoutStatus(`${edits.length} wijziging${edits.length === 1 ? '' : 'en'} klaarzetten…`, 'loading');
    await queueLayoutChanges(edits, `${edits.length} veld${edits.length === 1 ? '' : 'en'} uit layout ${layout.number} aangepast.`);
  }

  async function uploadLayoutImage(input, layout, field) {
    if (!input.files?.[0]) return;
    input.disabled = true;
    try {
      const uploadedIds = [];
      for (const file of Array.from(input.files)) {
        const uploadData = new FormData();
        uploadData.append('post_id', config.postId);
        uploadData.append('file', file);
        const uploaded = await request('upload', { method: 'POST', body: uploadData });
        uploadedIds.push(uploaded.id);
      }
      const pending = (state.context?.changes || []).find(change => Number(change.section) === layout.number && (change.field_path || []).join('.') === field.path.join('.'));
      const currentIds = field.type === 'gallery' ? (pending?.new_value || field.value || []).map(Number) : [];
      await queueLayoutChanges([{ section: layout.number, field_path: field.path, field_name: field.name, type: field.type, value: field.type === 'gallery' ? [...currentIds, ...uploadedIds] : uploadedIds[0] }], `${uploadedIds.length} afbeelding${uploadedIds.length === 1 ? '' : 'en'} in layout ${layout.number} aangepast.`);
    } catch (error) {
      addMessage(error.message, 'error');
      input.disabled = false;
    }
  }

  async function updateGallery(button, layout, field) {
    const removeId = Number(button.dataset.galleryRemove);
    const pending = (state.context?.changes || []).find(change => Number(change.section) === layout.number && (change.field_path || []).join('.') === field.path.join('.'));
    const ids = (pending?.new_value || field.value || []).map(Number).filter(id => id !== removeId);
    await queueLayoutChanges([{ section: layout.number, field_path: field.path, field_name: field.name, type: 'gallery', value: ids }], `Afbeelding uit galerij in layout ${layout.number} verwijderd.`);
  }

  function updateRepeater(button, field) {
    const wrapper = button.closest('[data-repeater-field]');
    const value = wrapper.querySelector('[data-repeater-value]');
    const rows = JSON.parse(value.value || '[]');
    if (button.dataset.repeaterAction === 'add') {
      if (Number(field.max || 0) > 0 && rows.length >= Number(field.max)) return;
      rows.push(structuredClone(field.row_template || {}));
    } else {
      if (Number(field.min || 0) > 0 && rows.length <= Number(field.min)) return;
      rows.splice(Number(button.dataset.row), 1);
    }
    value.value = JSON.stringify(rows);
    wrapper.querySelector('.pkca__repeater-rows').innerHTML = repeaterRowsMarkup(field, Number(button.dataset.fieldIndex), rows);
    const addButton = wrapper.querySelector('[data-repeater-action="add"]');
    const atMaximum = Number(field.max || 0) > 0 && rows.length >= Number(field.max);
    addButton.disabled = atMaximum;
    addButton.textContent = atMaximum ? `Maximum van ${field.max} rijen bereikt` : 'Rij toevoegen';
    value.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function repeaterRowsMarkup(field, fieldIndex, rows) {
    const rowFields = (field.row_fields || []).filter(rowField => rowField.type !== 'repeater');
    const atMinimum = Number(field.min || 0) > 0 && rows.length <= Number(field.min);
    return rows.map((row, rowIndex) => `<section class="pkca__repeater-row"><header><strong>Rij ${rowIndex + 1}</strong><button type="button" data-repeater-action="remove" data-row="${rowIndex}" data-field-index="${fieldIndex}"${atMinimum ? ' disabled' : ''}>Verwijderen</button></header><div class="pkca__repeater-fields">${rowFields.map(rowField => repeaterInputMarkup(rowField, row, rowIndex)).join('')}</div></section>`).join('');
  }

  function repeaterInputMarkup(field, row, rowIndex) {
    const path = field.path || [];
    const value = valueAtPath(row, path);
    const attrs = `data-repeater-input data-row="${rowIndex}" data-repeater-path="${escapeHtml(path.join('.'))}"`;
    const label = escapeHtml(field.label || humanize(path[path.length - 1] || 'Veld'));
    if (field.type === 'boolean') {
      const checked = value === true || value === 1 || value === '1';
      return `<label><span>${label}</span><span class="pkca__layout-toggle"><input type="checkbox" ${attrs}${checked ? ' checked' : ''}><span>${checked ? 'Ingeschakeld' : 'Uitgeschakeld'}</span></span></label>`;
    }
    if (field.type === 'option') {
      return `<label><span>${label}</span><select ${attrs}>${Object.entries(field.choices || {}).map(([key, choice]) => `<option value="${escapeHtml(key)}"${String(key) === String(value ?? '') ? ' selected' : ''}>${escapeHtml(choice)}</option>`).join('')}</select></label>`;
    }
    const plain = String(value ?? '');
    const type = field.type === 'number' ? 'number' : field.type === 'email' ? 'email' : 'text';
    const control = plain.length > 90 || field.acf_type === 'textarea' || field.acf_type === 'wysiwyg'
      ? `<textarea rows="3" ${attrs}>${escapeHtml(plain)}</textarea>`
      : `<input type="${type}" value="${escapeHtml(plain)}" ${attrs}>`;
    return `<label><span>${label}</span>${control}</label>`;
  }

  function syncRepeaterValue(input) {
    const wrapper = input.closest('[data-repeater-field]');
    const store = wrapper?.querySelector('[data-repeater-value]');
    if (!store) return;
    const rows = JSON.parse(store.value || '[]');
    const row = rows[Number(input.dataset.row)];
    if (!row) return;
    setValueAtPath(row, input.dataset.repeaterPath.split('.'), input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value);
    store.value = JSON.stringify(rows);
  }

  function valueAtPath(value, path) {
    return path.reduce((current, key) => current && typeof current === 'object' ? current[key] : undefined, value);
  }

  function setValueAtPath(value, path, nextValue) {
    let current = value;
    path.forEach((key, index) => {
      if (index === path.length - 1) current[key] = nextValue;
      else current = current[key] ||= {};
    });
  }

  function pathStartsWith(path, prefix) {
    return path.length > prefix.length && prefix.every((part, index) => String(path[index]) === String(part));
  }

  function chooseFromMedia(button, layout, field) {
    if (!window.wp?.media) {
      addMessage('De WordPress-mediabibliotheek kon niet worden geopend.', 'error');
      return;
    }
    const gallery = field.type === 'gallery';
    const frame = wp.media({ title: gallery ? 'Afbeeldingen kiezen' : 'Afbeelding kiezen', library: { type: 'image' }, multiple: gallery, button: { text: 'Gebruiken' } });
    frame.on('select', async () => {
      const selected = frame.state().get('selection').toJSON();
      const pending = (state.context?.changes || []).find(change => Number(change.section) === layout.number && (change.field_path || []).join('.') === field.path.join('.'));
      const current = gallery ? (pending?.new_value || field.value || []).map(Number) : [];
      const value = gallery ? [...new Set([...current, ...selected.map(item => Number(item.id))])] : Number(selected[0]?.id || 0);
      if (!value || (Array.isArray(value) && !value.length)) return;
      await queueLayoutChanges([{ section: layout.number, field_path: field.path, field_name: field.name, type: field.type, value }], gallery ? 'Galerij aangepast.' : 'Afbeelding aangepast.');
    });
    frame.open();
  }

  function applyConditionalLogic() {
    const values = new Map();
    layoutEditor.querySelectorAll('[data-acf-key]').forEach(wrapper => {
      const input = wrapper.querySelector('[data-field-index]:not([type=file])');
      if (!input || !wrapper.dataset.acfKey) return;
      values.set(wrapper.dataset.acfKey, input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value);
    });
    layoutEditor.querySelectorAll('[data-conditions]').forEach(wrapper => {
      let groups = [];
      try { groups = JSON.parse(decodeURIComponent(wrapper.dataset.conditions || '[]')); } catch (_) {}
      if (!groups.length) { wrapper.hidden = false; return; }
      wrapper.hidden = !groups.some(group => Array.isArray(group) && group.every(rule => {
        const actual = String(values.get(rule.field) ?? '');
        const expected = String(rule.value ?? '');
        if (rule.operator === '!=') return actual !== expected;
        if (rule.operator === '==empty') return actual === '';
        if (rule.operator === '!=empty') return actual !== '';
        return actual === expected;
      }));
    });
  }

  async function queueLayoutChanges(edits, confirmation) {
    setBusy(true);
    try {
      const data = await request('change', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ post_id: config.postId, changes: edits }) });
      state.context.changes = data.changes || [];
      syncContextWithChanges(state.context.changes);
      applyPreview(state.context.changes);
      renderChanges(state.context.changes);
      addMessage(data.queued === 0 && data.unchanged > 0 ? 'Deze wijziging stond al klaar; de pagina-preview is opnieuw gesynchroniseerd.' : confirmation, 'agent');
      closeLayoutEditor();
      if (edits.some(edit => edit.refresh || !['text', 'link', 'image'].includes(edit.type))) {
        sessionStorage.setItem('pkca-preview-resume', String(config.postId));
        window.location.reload();
      }
    } catch (error) {
      addMessage(error.message, 'error');
      setLayoutStatus(error.message || 'De wijzigingen konden niet worden klaargezet.', 'error');
    } finally {
      setBusy(false);
      const submit = layoutEditor.querySelector('.pkca__layout-save');
      if (submit) {
        submit.disabled = false;
        submit.textContent = 'Wijzigingen klaarzetten';
      }
    }
  }

  function setLayoutStatus(message, kind = 'info') {
    const status = layoutEditor.querySelector('.pkca__layout-status');
    if (!status) return;
    status.hidden = !message;
    status.className = `pkca__layout-status pkca__layout-status--${kind}`;
    status.textContent = message || '';
  }

  function closeLayoutEditor() {
    state.activeLayout = null;
    layoutEditor.hidden = true;
    layoutEditor.innerHTML = '';
    messages.hidden = false;
    form.hidden = false;
  }

  function humanize(value) {
    return String(value || '').replace(/[_-]+/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
  }

  function syncContextWithChanges(items) {
    if (!state.context?.sections || !Array.isArray(items)) return;
    items.forEach(item => {
      const section = state.context.sections[Number(item.section) - 1];
      const path = (item.field_path || []).join('.');
      const field = section?.fields?.find(candidate => (candidate.path || []).join('.') === path);
      if (!field) return;
      field.value = item.new_value;
      field.preview = stripHtml(String(item.new_value ?? ''));
      if (item.type === 'image' && item.new_url) field.url = item.new_url;
      if (item.type === 'gallery' && Array.isArray(item.new_images)) field.images = item.new_images;
    });
  }

  function applyPreview(items) {
    items.forEach(item => {
	  const section = getSectionByNumber(item.section);
      if (!section) return;

      const targetKey = `${item.row_index}:${item.layout_index}:${(item.field_path || []).join('.')}`;
      let changedElement = null;
      if (item.type === 'image' && item.new_url) {
        const existingTarget = state.previewTargets.get(targetKey);
        const images = Array.from(section.querySelectorAll('img'));
        const oldImageKey = imageKeyFromUrl(item.old_url || '');
        const existingImage = existingTarget?.node?.isConnected ? existingTarget.node : null;
        const image = existingImage || images.find(candidate => oldImageKey && imageKeyFromUrl(candidate.currentSrc || candidate.src) === oldImageKey)
          || (images.length === 1 ? images[0] : null);
        if (image) {
          let previewImage = image;
          if (item.new_html) {
            const template = document.createElement('template');
            template.innerHTML = item.new_html.trim();
            const replacement = template.content.querySelector('img');
            if (replacement) {
              image.replaceWith(replacement);
              previewImage = replacement;
            }
          } else {
            previewImage.src = item.new_url;
            previewImage.removeAttribute('srcset');
            previewImage.removeAttribute('sizes');
          }
          previewImage.closest('picture')?.querySelectorAll('source').forEach(source => {
            source.removeAttribute('srcset');
            source.removeAttribute('data-srcset');
          });
          ['data-src', 'data-lazy-src'].forEach(attribute => {
            if (previewImage.hasAttribute(attribute)) previewImage.setAttribute(attribute, item.new_url);
          });
          previewImage.removeAttribute('data-srcset');
          changedElement = previewImage;
          state.previewTargets.set(targetKey, { type: 'image', node: previewImage });
        }
      } else if (item.type === 'link') {
        const anchors = Array.from(section.querySelectorAll('a[href]'));
        const existingTarget = state.previewTargets.get(targetKey)?.node;
        const anchor = existingTarget?.isConnected ? existingTarget : anchors.find(candidate => urlKey(candidate.href) === urlKey(item.old_value));
        if (anchor) {
          anchor.setAttribute('href', item.new_value);
          changedElement = anchor;
          state.previewTargets.set(targetKey, { type: 'link', node: anchor });
        }
      } else if (item.type === 'option') {
        changedElement = previewButtonOption(section, item);
      } else if (item.type === 'text') {
        const oldText = normalizeText(stripHtml(String(item.old_value ?? '')));
        const newHtml = String(item.new_value ?? '');
        const newText = stripHtml(newHtml);
        const existingTarget = state.previewTargets.get(targetKey);
        const selectedElement = state.selectedTarget?.section === Number(item.section)
          && state.selectedTarget?.type === 'text'
          && Array.isArray(state.selectedTarget.fieldPath)
          && state.selectedTarget.fieldPath.join('.') === (item.field_path || []).join('.')
          && state.selectedTarget.node?.isConnected
          ? state.selectedTarget.node
          : null;
        let textNode = existingTarget?.node || null;
        if (selectedElement) {
          changedElement = updateSelectedElement(selectedElement, oldText, newText, newHtml) ? selectedElement : null;
          textNode = null;
        }
        // A later instruction for the same field must update the node that was
        // changed by the previous preview. The original ACF value is retained
        // for undo, so it can no longer be used to find that node in the DOM.
        if (!changedElement && textNode?.isConnected && textNode.nodeType === Node.TEXT_NODE) {
          const leading = textNode.nodeValue.match(/^\s*/)?.[0] || '';
          const trailing = textNode.nodeValue.match(/\s*$/)?.[0] || '';
          textNode.nodeValue = `${leading}${newText}${trailing}`;
          changedElement = textNode.parentElement;
        }
        if (!changedElement && textNode?.nodeType === Node.ELEMENT_NODE) {
          changedElement = updateSelectedElement(textNode, oldText, newText, newHtml) ? textNode : null;
          textNode = null;
        }
		if (!changedElement && hasHtmlMarkup(newHtml)) {
		  const richElement = Array.from(section.querySelectorAll('.pk-text, .wysiwyg, .wysiwyg-gaps'))
		    .find(element => normalizeText(element.textContent) === oldText);
		  if (richElement) {
			richElement.innerHTML = newHtml;
			changedElement = richElement;
			state.previewTargets.set(targetKey, { type: 'text', node: richElement });
		  }
		}
		// After a server-rendered preview reload the intended element can already
		// contain the new value. In that case do not replace another occurrence of
		// the old value (for example the page title inside a breadcrumb).
		if (!changedElement && !textNode) {
		  const newValueWalker = document.createTreeWalker(section, NodeFilter.SHOW_TEXT);
		  let newValueNode;
		  while ((newValueNode = newValueWalker.nextNode())) {
			if (normalizeText(newValueNode.nodeValue) === normalizeText(newText)) break;
		  }
		  if (newValueNode) {
			changedElement = newValueNode.parentElement;
			state.previewTargets.set(targetKey, { type: 'text', node: newValueNode });
		  }
		}
        if (!changedElement && !textNode) {
          const walker = document.createTreeWalker(section, NodeFilter.SHOW_TEXT);
          while ((textNode = walker.nextNode())) {
            if (normalizeText(textNode.nodeValue) === oldText) break;
          }
        }
        if (!changedElement && textNode) {
          const leading = textNode.nodeValue.match(/^\s*/)?.[0] || '';
          const trailing = textNode.nodeValue.match(/\s*$/)?.[0] || '';
          textNode.nodeValue = `${leading}${newText}${trailing}`;
          changedElement = textNode.parentElement;
          state.previewTargets.set(targetKey, { type: 'text', node: textNode });
        }
      }

      if (changedElement) {
        changedElement.classList.add('pkca-preview-target');
        changedElement.setAttribute('data-pkca-preview', 'true');
      }
    });
  }

  function previewButtonOption(section, item) {
    const path = item.field_path || [];
    const buttonsIndex = path.indexOf('buttons');
    const archiveIndex = path.indexOf('archive_button');
    const collectionIndex = buttonsIndex >= 0 ? buttonsIndex : archiveIndex;
    if (collectionIndex < 0) return null;
    const buttonIndex = Number(path[collectionIndex + 1] || 0);
    const property = path[path.length - 1];
    const button = section.querySelectorAll('.pk-button')[buttonIndex];
    if (!button) return null;
    if (property === 'variant') {
      ['primary', 'secondary', 'tertiary', 'textual'].forEach(name => button.classList.remove(name));
      if (item.new_value) button.classList.add(String(item.new_value));
    } else if (property === 'color') {
      Array.from(button.classList).filter(name => name.startsWith('color-')).forEach(name => button.classList.remove(name));
      if (item.new_value) button.classList.add(`color-${item.new_value}`);
    } else if (property === 'icon') {
      let icon = button.querySelector('.pk-button-icon');
      if (!item.new_value || item.new_value === 'none') {
        icon?.remove();
        button.classList.remove('has-icon');
      } else {
        if (!icon) {
          icon = document.createElement('span');
          icon.setAttribute('aria-hidden', 'true');
          button.append(icon);
        }
        icon.className = `pk-button-icon icon-${item.new_value}`;
        button.classList.add('has-icon');
        Array.from(button.classList).filter(name => ['dot', 'scrolldown', 'chevron', 'arrow-long', 'arrow-diagonal', 'bell', 'download', 'mail'].includes(name)).forEach(name => button.classList.remove(name));
        button.classList.add(String(item.new_value));
      }
    } else {
      return null;
    }
    button.classList.add('pkca-preview-target');
    return button;
  }

  function updateTextNodes(element, oldText, newText) {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    const allNodes = [];
    let node;
    while ((node = walker.nextNode())) allNodes.push(node);
    let nodes = allNodes.filter(textNode => normalizeText(textNode.nodeValue) === oldText);
    if (!nodes.length) nodes = allNodes.filter(textNode => textNode.nodeValue.includes(oldText));
    nodes.forEach(textNode => {
      textNode.nodeValue = textNode.nodeValue.replace(oldText, newText);
    });
    return nodes.length > 0;
  }

  function updateSelectedElement(element, oldText, newText, newHtml = '') {
    const labels = element.matches('.pk-button-text, .pk-heading-text')
      ? [element]
      : Array.from(element.querySelectorAll('.pk-button-text, .pk-heading-text'));
    if (labels.length) {
      labels.forEach(label => {
        if (hasHtmlMarkup(newHtml)) label.innerHTML = newHtml;
        else label.textContent = newText;
      });
      return true;
    }
	if (hasHtmlMarkup(newHtml) && normalizeText(element.textContent) === oldText) {
	  element.innerHTML = newHtml;
	  return true;
	}
    return updateTextNodes(element, oldText, newText);
  }

  function hasHtmlMarkup(value) {
    return /<\/?(?:p|br|strong|b|em|i|ul|ol|li|a|h[1-6]|blockquote)\b/i.test(String(value || ''));
  }

  function stripHtml(value) {
    const template = document.createElement('template');
    template.innerHTML = value;
    return template.content.textContent || '';
  }

  function normalizeText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function selectableText(element) {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    const fragments = [];
    let node;
    while ((node = walker.nextNode())) {
      const fragment = normalizeText(node.nodeValue);
      if (fragment && /[\p{L}\p{N}]/u.test(fragment)) fragments.push(fragment);
    }
    // Animated buttons often render the same label twice in separate spans.
    // Keep every distinct fragment once, in DOM order, so both
    // "Ons verhaal Ons verhaal" and split labels normalize predictably.
    return normalizeText([...new Set(fragments)].join(' '));
  }

  function filenameFromUrl(value) {
    try {
      return new URL(value, window.location.origin).pathname.split('/').pop() || '';
    } catch (_) {
      return '';
    }
  }

  function imageKeyFromUrl(value) {
    return decodeURIComponent(filenameFromUrl(value))
      .replace(/-\d+x\d+(?=\.[^.]+$)/i, '')
      .toLowerCase();
  }

  function urlKey(value) {
    try {
      const url = new URL(value, window.location.origin);
      return `${url.pathname.replace(/\/$/, '') || '/'}${url.search}${url.hash}`;
    } catch (_) {
      return String(value || '');
    }
  }

  fileInput.addEventListener('change', async () => {
    if (!fileInput.files[0]) return;
    const data = new FormData();
    data.append('post_id', config.postId);
    data.append('file', fileInput.files[0]);
    addMessage('Afbeelding uploaden…', 'agent');
    try {
      state.upload = await request('upload', { method: 'POST', body: data });
      addMessage(`Afbeelding “${state.upload.title}” is klaar. Beschrijf nu waar ik hem moet plaatsen.`, 'agent');
    } catch (error) {
      addMessage(error.message, 'error');
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const originalMessage = textarea.value.trim();
    let message = originalMessage;
    if (!message) return;
    if (state.upload) message += `\n\nDe geüploade afbeelding heeft attachment-ID ${state.upload.id}.`;
    if (state.selection) {
      message += `\n\nAANGEWEZEN ONDERDEEL: sectie ${state.selection.section}, type ${state.selection.type}, zichtbare waarde "${state.selection.value}", alt-tekst "${state.selection.alt}", exact veldpad ${JSON.stringify(state.selection.fieldPath)}, veldnaam ${JSON.stringify(state.selection.fieldName)}. Als een exact veldpad aanwezig is, gebruik je exact dat pad.`;
    }
    addMessage(originalMessage, 'user');
    textarea.value = '';
    setBusy(true);
    try {
      const selectedContext = state.selection ? { ...state.selection } : null;
      const data = await request('chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ post_id: config.postId, message, original_message: originalMessage, selection: selectedContext, history: readHistory().slice(0, -1).slice(-20) })
      });
      addMessage(data.message || 'De opdracht is verwerkt.', 'agent');
      if (data.change && state.selectedTarget && Array.isArray(data.change.field_path)) {
        state.selectedTarget.fieldPath = data.change.field_path;
      }
      if (Array.isArray(data.changes)) {
        state.context.changes = data.changes;
        syncContextWithChanges(data.changes);
        applyPreview(data.changes);
        renderChanges(data.changes);
      }
      if (data.change) {
        state.upload = null;
        fileInput.value = '';
        clearSelection();
		sessionStorage.setItem('pkca-preview-resume', String(config.postId));
		window.location.reload();
		return;
      } else if (state.selection) {
        selection.hidden = false;
      }
    } catch (error) {
      addMessage(error.message, 'error');
    } finally {
      setBusy(false);
    }
  });

  function renderChanges(items) {
    const pendingCount = Array.isArray(items) ? items.length : 0;
    root.classList.toggle('pkca--has-pending', pendingCount > 0);
    toggle.dataset.pendingCount = String(pendingCount);
    toggle.setAttribute('aria-label', pendingCount > 0
      ? `Pagina aanpassen, ${pendingCount} wijziging${pendingCount === 1 ? '' : 'en'} staat klaar`
      : 'Pagina aanpassen');
    if (!pendingCount) {
      changes.hidden = true;
      changes.innerHTML = '';
      return;
    }
    changes.hidden = false;
    changes.innerHTML = `<details class="pkca__change-details"><summary><strong><i aria-hidden="true"></i>${pendingCount} wijziging${pendingCount === 1 ? '' : 'en'} klaar</strong><span>Bekijken</span></summary><div class="pkca__change-list">${items.map(item => `
      <div class="pkca__change"><span>Sectie ${item.section} · ${escapeHtml(item.field_name)}</span><del>${escapeHtml(changeValueSummary(item.old_value, item.type))}</del><ins>${escapeHtml(changeValueSummary(item.new_value, item.type))}</ins><button type="button" class="pkca__change-discard" data-change-id="${escapeHtml(item.id || '')}">Deze wijziging ongedaan maken</button></div>`).join('')}</div></details>
      <div class="pkca__actions"><button type="button" data-action="discard">Alles ongedaan</button><button type="button" data-action="publish">Alles opslaan</button></div>`;
    changes.querySelector('[data-action=publish]').addEventListener('click', () => finish('publish'));
    changes.querySelector('[data-action=discard]').addEventListener('click', () => finish('discard'));
	changes.querySelectorAll('[data-change-id]').forEach(button => button.addEventListener('click', () => discardChange(button.dataset.changeId)));
  }

  function changeValueSummary(value, type) {
    if (type === 'repeater' && Array.isArray(value)) return `${value.length} rij${value.length === 1 ? '' : 'en'}`;
    if (type === 'gallery' && Array.isArray(value)) return `${value.length} afbeelding${value.length === 1 ? '' : 'en'}`;
    if (value && typeof value === 'object') return 'Samengestelde waarde';
    return String(value ?? '');
  }

  async function finish(action) {
    setBusy(true);
    try {
      const data = await request(action, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ post_id: config.postId })
      });
      sessionStorage.setItem('pkca-status', action === 'publish' ? `${data.published} wijziging(en) opgeslagen.` : 'Previewwijzigingen verwijderd.');
      const destination = action === 'publish' && data.url
        ? new URL(data.url, window.location.origin).pathname
        : window.location.pathname;
      window.location.assign(destination);
    } catch (error) {
      addMessage(error.message, 'error');
      setBusy(false);
    }
  }

	async function discardChange(changeId) {
	  if (!changeId) return;
	  setBusy(true);
	  try {
		await request('discard', {
		  method: 'POST',
		  headers: { 'Content-Type': 'application/json' },
		  body: JSON.stringify({ post_id: config.postId, change_id: changeId })
		});
		sessionStorage.setItem('pkca-status', 'Wijziging ongedaan gemaakt.');
		window.location.reload();
	  } catch (error) {
		addMessage(error.message, 'error');
		setBusy(false);
	  }
	}

  function addMessage(text, kind, persist = true) {
    const el = document.createElement('div');
    el.className = `pkca__message pkca__message--${kind}`;
    el.textContent = text;
    messages.append(el);
    messages.scrollTop = messages.scrollHeight;
    if (persist) {
      const history = readHistory();
      history.push({ text, kind });
      sessionStorage.setItem(historyKey, JSON.stringify(history.slice(-100)));
    }
  }

  function readHistory() {
    try {
      const history = JSON.parse(sessionStorage.getItem(historyKey) || '[]');
      return Array.isArray(history) ? history : [];
    } catch (_) {
      return [];
    }
  }

  function restoreHistory() {
    const history = readHistory();
    if (!history.length) {
      addMessage('Wat wil je op deze pagina aanpassen? Je kunt meerdere wijzigingen achter elkaar doorgeven.', 'agent');
      return;
    }
    history.forEach(item => addMessage(item.text, item.kind, false));
  }

  function makeDraggable() {
    const header = root.querySelector('.pkca__header');
    let drag = null;

    header.addEventListener('pointerdown', event => {
      if (event.target.closest('button')) return;
      const rect = panel.getBoundingClientRect();
      drag = { x: event.clientX - rect.left, y: event.clientY - rect.top };
      panel.style.position = 'fixed';
      panel.style.right = 'auto';
      panel.style.bottom = 'auto';
      panel.style.left = `${rect.left}px`;
      panel.style.top = `${rect.top}px`;
      header.setPointerCapture(event.pointerId);
      panel.classList.add('pkca__panel--dragging');
    });

    header.addEventListener('pointermove', event => {
      if (!drag) return;
      const maxLeft = Math.max(8, window.innerWidth - panel.offsetWidth - 8);
      const maxTop = Math.max(8, window.innerHeight - panel.offsetHeight - 8);
      panel.style.left = `${Math.min(maxLeft, Math.max(8, event.clientX - drag.x))}px`;
      panel.style.top = `${Math.min(maxTop, Math.max(8, event.clientY - drag.y))}px`;
    });

    header.addEventListener('pointerup', event => {
      if (!drag) return;
      drag = null;
      header.releasePointerCapture(event.pointerId);
      panel.classList.remove('pkca__panel--dragging');
      sessionStorage.setItem(positionKey, JSON.stringify({ left: panel.style.left, top: panel.style.top }));
    });
  }

  function restorePosition() {
    try {
      const position = JSON.parse(sessionStorage.getItem(positionKey) || 'null');
      if (!position?.left || !position?.top) return;
      panel.style.position = 'fixed';
      panel.style.right = 'auto';
      panel.style.bottom = 'auto';
      panel.style.left = position.left;
      panel.style.top = position.top;
    } catch (_) {
      // Ignore invalid persisted positions.
    }
  }

  function makeResizable() {
    const handle = root.querySelector('.pkca__resize');
    let resize = null;

    handle.addEventListener('pointerdown', event => {
      const rect = panel.getBoundingClientRect();
      resize = { x: event.clientX, y: event.clientY, width: rect.width, height: rect.height };
      panel.style.position = 'fixed';
      panel.style.right = 'auto';
      panel.style.bottom = 'auto';
      panel.style.left = `${rect.left}px`;
      panel.style.top = `${rect.top}px`;
      handle.setPointerCapture(event.pointerId);
      panel.classList.add('pkca__panel--resizing');
      event.preventDefault();
    });

    handle.addEventListener('pointermove', event => {
      if (!resize) return;
      const maxWidth = window.innerWidth - panel.offsetLeft - 8;
      const maxHeight = window.innerHeight - panel.offsetTop - 8;
      panel.style.width = `${Math.min(maxWidth, Math.max(320, resize.width + event.clientX - resize.x))}px`;
      panel.style.height = `${Math.min(maxHeight, Math.max(420, resize.height + event.clientY - resize.y))}px`;
    });

    handle.addEventListener('pointerup', event => {
      if (!resize) return;
      resize = null;
      handle.releasePointerCapture(event.pointerId);
      panel.classList.remove('pkca__panel--resizing');
      sessionStorage.setItem(sizeKey, JSON.stringify({ width: panel.style.width, height: panel.style.height }));
      sessionStorage.setItem(positionKey, JSON.stringify({ left: panel.style.left, top: panel.style.top }));
    });
  }

  function restoreSize() {
    try {
      const size = JSON.parse(sessionStorage.getItem(sizeKey) || 'null');
      if (size?.width) panel.style.width = size.width;
      if (size?.height) panel.style.height = size.height;
    } catch (_) {
      // Ignore invalid persisted sizes.
    }
  }

  function setBusy(busy) {
    form.querySelector('.pkca__send').disabled = busy;
    textarea.disabled = busy;
  }

  function escapeHtml(value) {
    const el = document.createElement('span');
    el.textContent = value;
    return el.innerHTML;
  }

  // De assistent begint bewust gesloten, ook na opslaan of een paginaverversing.
  sessionStorage.removeItem('pkca-reopen');
  if (sessionStorage.getItem('pkca-preview-resume') === String(config.postId)) {
    sessionStorage.removeItem('pkca-preview-resume');
    setOpen(true);
    addMessage('De bijgewerkte pagina-preview is geladen.', 'agent');
  }
})();
