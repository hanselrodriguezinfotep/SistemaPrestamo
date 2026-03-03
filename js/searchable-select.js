/**
 * searchable-select.js  v2
 * Convierte <select> en combobox con búsqueda interna.
 *
 * REGLAS:
 *  - Se salta selects con: data-no-search, multiple, ss-skip, disabled al init
 *  - Se salta selects con pocas opciones (< MIN_OPTIONS_FOR_SEARCH)
 *  - Detecta repoblación externa (.innerHTML = ) y se auto-refresca
 *
 * API pública:
 *   SearchableSelect.init(el)      — inicializar un select
 *   SearchableSelect.refresh(el)   — re-sincronizar opciones y valor
 *   SearchableSelect.destroy(el)   — restaurar select nativo
 *   SearchableSelect.initAll(root) — inicializar todos en un contenedor
 *   ssRefreshAll(root)             — refrescar todos en un contenedor
 *   ssSet('id', value)             — asignar valor y refrescar widget
 */
(function (window) {
    'use strict';

    const MIN_OPTIONS = 5;   // mínimo de opciones para activar el buscador
    const instances   = new WeakMap();

    const ARROW_SVG = `<svg class="ss-arrow" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293
        a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
        clip-rule="evenodd"/></svg>`;

    function esc(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function shouldSkip(sel) {
        if (!sel || sel.tagName !== 'SELECT') return true;
        if (sel.dataset.noSearch !== undefined) return true;
        if (sel.multiple)                       return true;
        if (sel.classList.contains('ss-skip'))  return true;
        if (sel.classList.contains('ss-hidden'))return true;
        if (instances.has(sel))                 return true;
        return false;
    }

    /* ═══════════════════════════════════════════════
       Instancia del widget
    ═══════════════════════════════════════════════ */
    class SS {
        constructor(sel) {
            this.sel      = sel;
            this.wrapper  = null;
            this.trigger  = null;
            this.dropdown = null;
            this.search   = null;
            this.list     = null;
            this._open    = false;
            this._onDoc   = null;
            this._build();
        }

        _build() {
            const sel = this.sel;

            /* ── Wrapper ── */
            const wrap = document.createElement('div');
            wrap.className = 'ss-wrapper';

            // Clases de contexto para CSS
            if (sel.classList.contains('sel-asesor')) wrap.classList.add('ss-ctx-asesor');
            if (sel.classList.contains('fs'))          wrap.classList.add('ss-ctx-fs');
            if (sel.classList.contains('fi'))          wrap.classList.add('ss-ctx-fi');
            if (sel.classList.contains('form-select')) wrap.classList.add('ss-ctx-form');
            if (sel.classList.contains('sel-f'))       wrap.classList.add('ss-ctx-fs');

            // Heredar estilos inline de ancho
            ['width','minWidth','maxWidth','flex'].forEach(p => {
                if (sel.style[p]) wrap.style[p] = sel.style[p];
            });

            /* ── Trigger ── */
            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'ss-trigger';
            trigger.setAttribute('aria-haspopup','listbox');
            trigger.setAttribute('aria-expanded','false');
            trigger.innerHTML = `<span class="ss-trigger-text"></span>${ARROW_SVG}`;

            /* ── Dropdown ── */
            const drop = document.createElement('div');
            drop.className = 'ss-dropdown';
            drop.setAttribute('role','listbox');

            const showSearch = sel.options.length >= MIN_OPTIONS;
            if (showSearch) {
                const sw = document.createElement('div');
                sw.className = 'ss-search-wrap';
                sw.innerHTML = `<span class="ss-search-icon">🔍</span>
                    <input type="text" class="ss-search" placeholder="Buscar…"
                           autocomplete="off" spellcheck="false">`;
                drop.appendChild(sw);
                this.search = sw.querySelector('.ss-search');
            }

            const list = document.createElement('div');
            list.className = 'ss-list';
            drop.appendChild(list);
            this.list = list;

            /* ── Ensamblar ── */
            wrap.appendChild(trigger);
            wrap.appendChild(drop);
            sel.parentNode.insertBefore(wrap, sel);
            wrap.appendChild(sel);
            sel.classList.add('ss-hidden');

            this.wrapper  = wrap;
            this.trigger  = trigger;
            this.dropdown = drop;

            this._renderList('');
            this._syncLabel();
            this._bindEvents();
        }

        _renderList(q) {
            const sel  = this.sel;
            const list = this.list;
            const query = q.trim().toLowerCase();
            list.innerHTML = '';
            const selVal = sel.value;
            let count = 0;

            Array.from(sel.options).forEach(opt => {
                if (query && !opt.text.toLowerCase().includes(query)) return;
                const item = document.createElement('div');
                item.className = 'ss-option';
                item.dataset.value = opt.value;
                if (!opt.value || opt.disabled) item.classList.add('ss-opt-placeholder');
                if (opt.value === selVal)        item.classList.add('selected');
                item.setAttribute('role','option');
                item.setAttribute('aria-selected', opt.value === selVal ? 'true':'false');

                if (query) {
                    const i = opt.text.toLowerCase().indexOf(query);
                    item.innerHTML = esc(opt.text.slice(0,i))
                        + `<mark>${esc(opt.text.slice(i,i+query.length))}</mark>`
                        + esc(opt.text.slice(i+query.length));
                } else {
                    item.textContent = opt.text;
                }

                item.addEventListener('mousedown', e => { e.preventDefault(); this._pick(opt.value); });
                list.appendChild(item);
                count++;
            });

            if (!count) {
                const em = document.createElement('div');
                em.className = 'ss-empty';
                em.textContent = 'Sin resultados';
                list.appendChild(em);
            }
        }

        _pick(val) {
            const prev = this.sel.value;
            this.sel.value = val;
            this._syncLabel();
            this._close();
            if (this.sel.value !== prev) {
                this.sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        _syncLabel() {
            const sel  = this.sel;
            const span = this.trigger.querySelector('.ss-trigger-text');
            const opt  = sel.options[sel.selectedIndex];
            if (opt && opt.value !== '') {
                span.textContent = opt.text;
                span.classList.remove('ss-placeholder');
            } else {
                span.textContent = opt ? opt.text : '— Selecciona —';
                span.classList.add('ss-placeholder');
            }
        }

        _openDrop() {
            if (this._open || this.sel.disabled) return;
            this._open = true;
            this.trigger.classList.add('open');
            this.trigger.setAttribute('aria-expanded','true');
            this.dropdown.classList.add('visible');

            // Re-renderizar opciones por si cambiaron
            this._renderList(this.search ? this.search.value : '');

            if (this.search) {
                this.search.value = '';
                this._renderList('');
                setTimeout(() => this.search && this.search.focus(), 20);
            }
            setTimeout(() => {
                const s = this.list.querySelector('.ss-option.selected');
                if (s) s.scrollIntoView({ block:'nearest' });
            }, 30);

            // Cerrar otros
            document.querySelectorAll('.ss-dropdown.visible').forEach(d => {
                if (d !== this.dropdown) {
                    const inst = instances.get(d.closest('.ss-wrapper')?.querySelector('select.ss-hidden'));
                    if (inst) inst._close();
                }
            });
        }

        _close() {
            if (!this._open) return;
            this._open = false;
            this.trigger.classList.remove('open');
            this.trigger.setAttribute('aria-expanded','false');
            this.dropdown.classList.remove('visible');
            if (this.search) this.search.value = '';
        }

        _bindEvents() {
            this.trigger.addEventListener('click', e => {
                e.stopPropagation();
                this._open ? this._close() : this._openDrop();
            });
            this.trigger.addEventListener('keydown', e => {
                if (e.key==='Enter'||e.key===' ') { e.preventDefault(); this._open?this._close():this._openDrop(); }
                if (e.key==='Escape') this._close();
                if (e.key==='ArrowDown') { e.preventDefault(); this._openDrop(); }
            });
            if (this.search) {
                this.search.addEventListener('input', () => this._renderList(this.search.value));
                this.search.addEventListener('keydown', e => {
                    if (e.key==='Escape') { this._close(); this.trigger.focus(); }
                    if (e.key==='Enter') {
                        const f = this.list.querySelector('.ss-option:not(.ss-opt-placeholder)');
                        if (f) this._pick(f.dataset.value);
                    }
                    if (e.key==='ArrowDown') {
                        e.preventDefault();
                        const f = this.list.querySelector('.ss-option');
                        if (f) f.focus();
                    }
                });
            }
            this._onDoc = e => { if (!this.wrapper.contains(e.target)) this._close(); };
            document.addEventListener('click', this._onDoc);
        }

        refresh() {
            // Si el select fue repoblado, re-renderizar buscador si cambió el conteo
            const needsSearch = this.sel.options.length >= MIN_OPTIONS;
            if (needsSearch && !this.search) {
                // Agregar buscador si antes no había
                const sw = document.createElement('div');
                sw.className = 'ss-search-wrap';
                sw.innerHTML = `<span class="ss-search-icon">🔍</span>
                    <input type="text" class="ss-search" placeholder="Buscar…"
                           autocomplete="off" spellcheck="false">`;
                this.dropdown.insertBefore(sw, this.list);
                this.search = sw.querySelector('.ss-search');
                this.search.addEventListener('input', () => this._renderList(this.search.value));
                this.search.addEventListener('keydown', e => {
                    if (e.key==='Escape') { this._close(); this.trigger.focus(); }
                    if (e.key==='Enter') {
                        const f = this.list.querySelector('.ss-option:not(.ss-opt-placeholder)');
                        if (f) this._pick(f.dataset.value);
                    }
                });
            }
            this._renderList(this.search ? this.search.value : '');
            this._syncLabel();
        }

        destroy() {
            document.removeEventListener('click', this._onDoc);
            this.sel.classList.remove('ss-hidden');
            this.wrapper.replaceWith(this.sel);
            instances.delete(this.sel);
        }
    }

    /* ═══════════════════════════════════════════════
       API Pública
    ═══════════════════════════════════════════════ */
    const SearchableSelect = {
        init(sel) {
            if (shouldSkip(sel)) return null;
            // Si tiene muy pocas opciones, no inicializar
            // (pero sí si las opciones aumentarán después — dejar que refresh lo maneje)
            const inst = new SS(sel);
            instances.set(sel, inst);
            return inst;
        },
        refresh(sel) {
            const inst = instances.get(sel);
            if (inst) { inst.refresh(); return; }
            // Si no estaba inicializado y ahora tiene suficientes opciones, init
            if (sel && !shouldSkip(sel) && sel.options.length >= MIN_OPTIONS) {
                SearchableSelect.init(sel);
            }
        },
        destroy(sel) {
            const inst = instances.get(sel);
            if (inst) inst.destroy();
        },
        initAll(root) {
            (root || document).querySelectorAll('select').forEach(sel => {
                if (!shouldSkip(sel)) SearchableSelect.init(sel);
            });
        },
    };

    /* Auto-init */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => SearchableSelect.initAll());
    } else {
        SearchableSelect.initAll();
    }

    /* MutationObserver — detectar selects nuevos en el DOM */
    const mo = new MutationObserver(muts => {
        muts.forEach(m => {
            m.addedNodes.forEach(node => {
                if (node.nodeType !== 1) return;
                const sels = node.tagName === 'SELECT' ? [node]
                           : Array.from(node.querySelectorAll ? node.querySelectorAll('select') : []);
                sels.forEach(s => { if (!shouldSkip(s)) SearchableSelect.init(s); });
            });
        });
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            mo.observe(document.body, { childList:true, subtree:true });
        });
    } else {
        mo.observe(document.body, { childList:true, subtree:true });
    }

    window.SearchableSelect = SearchableSelect;
    window.ssRefreshAll = (root) => {
        (root || document).querySelectorAll('select.ss-hidden').forEach(s => SearchableSelect.refresh(s));
        // También inicializar los que aún no fueron procesados
        (root || document).querySelectorAll('select:not(.ss-hidden)').forEach(s => {
            if (!shouldSkip(s)) SearchableSelect.init(s);
        });
    };
    window.ssSet = (idOrEl, value) => {
        const sel = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl;
        if (!sel) return;
        sel.value = value;
        SearchableSelect.refresh(sel);
    };

})(window);
