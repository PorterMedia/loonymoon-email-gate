/* Loonybin email builder — block-based drag & drop. Vanilla JS, no deps.
   Writes email-safe HTML into a target <textarea> (and TinyMCE if present).
   Exposed as window.LMEGBuilder.init(rootEl, targetTextareaId). */
(function () {
    'use strict';

    var ACCENT_FALLBACK = '#d05fa2';
    var uid = 0;
    function nid() { uid += 1; return 'b' + uid; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]); }); }

    var BLOCKS = {
        heading: { label: 'Heading', icon: 'M6 4v16M18 4v16M6 12h12', def: function () { return { text: 'Your heading' }; } },
        text:    { label: 'Text',    icon: 'M4 6h16M4 12h16M4 18h10', def: function () { return { html: 'Write something to your fans…' }; } },
        image:   { label: 'Image',   icon: 'M3 5h18v14H3zM3 15l5-5 4 4 3-3 6 6', def: function () { return { src: '', alt: '', href: '', width: 600 }; } },
        button:  { label: 'Button',  icon: 'M4 8h16v8H4z', def: function () { return { label: 'Listen now', href: 'https://', color: '' }; } },
        divider: { label: 'Divider', icon: 'M4 12h16', def: function () { return {}; } },
        spacer:  { label: 'Spacer',  icon: 'M12 4v16M8 8l4-4 4 4M8 16l4 4 4-4', def: function () { return { height: 24 }; } }
    };

    function svg(path) {
        return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="' + path + '"/></svg>';
    }

    function Builder(root, textareaId) {
        this.root = root;
        this.accent = root.getAttribute('data-accent') || ACCENT_FALLBACK;
        this.textarea = document.getElementById(textareaId);
        this.blocks = [];
        this.selected = null;
        this.dragId = null;      // id of block being reordered
        this.newType = null;     // type being dragged from palette
        this.build();
        this.seedFromTextarea();
        this.render();
    }

    Builder.prototype.build = function () {
        var self = this;
        // palette
        var pal = document.createElement('div');
        pal.className = 'lmeg-bd-palette';
        pal.innerHTML = '<div class="lmeg-bd-palette__title">Add block</div>';
        Object.keys(BLOCKS).forEach(function (type) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'lmeg-bd-add';
            b.setAttribute('draggable', 'true');
            b.dataset.type = type;
            b.innerHTML = svg(BLOCKS[type].icon) + '<span>' + BLOCKS[type].label + '</span>';
            b.addEventListener('click', function (e) { e.stopPropagation(); self.addBlock(type); });
            b.addEventListener('dragstart', function (e) { self.newType = type; self.dragId = null; e.dataTransfer.effectAllowed = 'copy'; try { e.dataTransfer.setData('text/plain', 'new:' + type); } catch (x) {} });
            b.addEventListener('dragend', function () { self.newType = null; self.clearIndicator(); });
            pal.appendChild(b);
        });
        // canvas
        var canvas = document.createElement('div');
        canvas.className = 'lmeg-bd-canvas';
        var sheet = document.createElement('div');
        sheet.className = 'lmeg-bd-sheet';
        canvas.appendChild(sheet);
        this.sheet = sheet;

        // moving placeholder line
        this.indicator = document.createElement('div');
        this.indicator.className = 'lmeg-bd-indicator';

        // sheet-wide drag handling — forgiving: drop anywhere, we compute the gap.
        sheet.addEventListener('dragover', function (e) {
            if (self.dragId == null && self.newType == null) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = self.newType ? 'copy' : 'move';
            self.positionIndicator(e.clientY);
        });
        sheet.addEventListener('drop', function (e) {
            if (self.dragId == null && self.newType == null) return;
            e.preventDefault();
            var index = self.indicatorIndex();
            if (self.newType) {
                self.addBlock(self.newType, index);
                self.newType = null;
            } else if (self.dragId != null) {
                self.moveTo(self.dragId, index);
                self.dragId = null;
            }
            self.clearIndicator();
        });

        this.root.classList.add('lmeg-builder');
        this.root.appendChild(pal);
        this.root.appendChild(canvas);
    };

    // ---- drag indicator helpers ----
    Builder.prototype.blockEls = function () {
        return Array.prototype.slice.call(this.sheet.querySelectorAll('.lmeg-bd-block'));
    };
    Builder.prototype.positionIndicator = function (clientY) {
        var els = this.blockEls();
        if (!els.length) { this.sheet.appendChild(this.indicator); return; }
        var placed = false;
        for (var i = 0; i < els.length; i++) {
            var r = els[i].getBoundingClientRect();
            if (clientY < r.top + r.height / 2) {
                this.sheet.insertBefore(this.indicator, els[i]);
                placed = true; break;
            }
        }
        if (!placed) {
            var last = els[els.length - 1];
            if (last.nextSibling) this.sheet.insertBefore(this.indicator, last.nextSibling);
            else this.sheet.appendChild(this.indicator);
        }
    };
    Builder.prototype.indicatorIndex = function () {
        // count block elements before the indicator
        var idx = 0, n = this.sheet.childNodes;
        for (var i = 0; i < n.length; i++) {
            if (n[i] === this.indicator) break;
            if (n[i].classList && n[i].classList.contains('lmeg-bd-block')) idx++;
        }
        return idx;
    };
    Builder.prototype.clearIndicator = function () {
        if (this.indicator && this.indicator.parentNode) this.indicator.parentNode.removeChild(this.indicator);
    };

    Builder.prototype.addBlock = function (type, atIndex) {
        var block = { id: nid(), type: type, data: BLOCKS[type].def() };
        if (atIndex == null || atIndex < 0 || atIndex > this.blocks.length) this.blocks.push(block);
        else this.blocks.splice(atIndex, 0, block);
        this.selected = block.id;
        this.render(); this.sync();
    };
    Builder.prototype.remove = function (id) {
        this.blocks = this.blocks.filter(function (b) { return b.id !== id; });
        this.render(); this.sync();
    };
    Builder.prototype.duplicate = function (id) {
        var i = this.indexOf(id); if (i < 0) return;
        var copy = { id: nid(), type: this.blocks[i].type, data: JSON.parse(JSON.stringify(this.blocks[i].data)) };
        this.blocks.splice(i + 1, 0, copy);
        this.render(); this.sync();
    };
    Builder.prototype.move = function (id, dir) {
        var i = this.indexOf(id), j = i + dir;
        if (i < 0 || j < 0 || j >= this.blocks.length) return;
        var t = this.blocks[i]; this.blocks[i] = this.blocks[j]; this.blocks[j] = t;
        this.render(); this.sync();
    };
    Builder.prototype.moveTo = function (id, index) {
        var from = this.indexOf(id); if (from < 0) return;
        var b = this.blocks.splice(from, 1)[0];
        if (from < index) index -= 1;
        if (index < 0) index = 0; if (index > this.blocks.length) index = this.blocks.length;
        this.blocks.splice(index, 0, b);
        this.render(); this.sync();
    };
    Builder.prototype.indexOf = function (id) { return this.blocks.findIndex(function (b) { return b.id === id; }); };

    Builder.prototype.render = function () {
        var self = this;
        this.sheet.innerHTML = '';
        if (!this.blocks.length) {
            var e = document.createElement('div');
            e.className = 'lmeg-bd-empty';
            e.textContent = 'Click a block on the left to add it — then drag blocks to reorder.';
            this.sheet.appendChild(e);
            return;
        }
        this.blocks.forEach(function (block) { self.sheet.appendChild(self.renderBlock(block)); });
    };

    Builder.prototype.renderBlock = function (block) {
        var self = this;
        var wrap = document.createElement('div');
        wrap.className = 'lmeg-bd-block' + (this.selected === block.id ? ' is-selected' : '');
        wrap.dataset.id = block.id;
        wrap.addEventListener('click', function (e) { e.stopPropagation(); self.select(block.id); });

        // Only the handle initiates a drag — so text editing never triggers one.
        wrap.setAttribute('draggable', 'false');
        wrap.addEventListener('dragstart', function (e) { self.dragId = block.id; wrap.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', 'move:' + block.id); } catch (x) {} });
        wrap.addEventListener('dragend', function () { wrap.classList.remove('is-dragging'); wrap.setAttribute('draggable', 'false'); self.dragId = null; self.clearIndicator(); });

        // control bar
        var bar = document.createElement('div');
        bar.className = 'lmeg-bd-block__bar';
        bar.innerHTML =
            '<button type="button" class="lmeg-bd-ctl lmeg-bd-ctl--drag" title="Drag to reorder">' + svg('M9 5h.01M15 5h.01M9 12h.01M15 12h.01M9 19h.01M15 19h.01') + '</button>' +
            '<button type="button" class="lmeg-bd-ctl" data-a="up" title="Move up">' + svg('M12 19V5M5 12l7-7 7 7') + '</button>' +
            '<button type="button" class="lmeg-bd-ctl" data-a="down" title="Move down">' + svg('M12 5v14M5 12l7 7 7-7') + '</button>' +
            '<button type="button" class="lmeg-bd-ctl" data-a="dup" title="Duplicate">' + svg('M9 9h11v11H9zM5 15H4V4h11v1') + '</button>' +
            '<button type="button" class="lmeg-bd-ctl lmeg-bd-ctl--del" data-a="del" title="Delete">' + svg('M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13') + '</button>';
        bar.addEventListener('click', function (e) {
            var btn = e.target.closest('button'); if (!btn) return;
            e.stopPropagation();
            var a = btn.dataset.a;
            if (a === 'up') self.move(block.id, -1);
            else if (a === 'down') self.move(block.id, 1);
            else if (a === 'dup') self.duplicate(block.id);
            else if (a === 'del') self.remove(block.id);
        });
        // drag handle enables dragging only while pressed
        var handle = bar.querySelector('.lmeg-bd-ctl--drag');
        handle.addEventListener('mousedown', function () { wrap.setAttribute('draggable', 'true'); });
        handle.addEventListener('mouseup', function () { setTimeout(function () { wrap.setAttribute('draggable', 'false'); }, 50); });
        wrap.appendChild(bar);

        var inner = document.createElement('div');
        inner.className = 'lmeg-bd-block__inner';
        this['render_' + block.type](block, inner, wrap);
        wrap.appendChild(inner);
        return wrap;
    };

    // ---- per-type editors ----
    Builder.prototype.render_heading = function (block, inner) {
        var self = this;
        var h = document.createElement('h2');
        h.style.cssText = 'margin:8px 0;font-size:22px;line-height:1.25;color:#2f2a2c;';
        h.setAttribute('contenteditable', 'true');
        h.textContent = block.data.text;
        h.addEventListener('input', function () { block.data.text = h.textContent; self.sync(); });
        inner.appendChild(h);
    };

    Builder.prototype.render_text = function (block, inner) {
        var self = this;
        var body = document.createElement('div');
        body.style.cssText = 'margin:6px 0;font-size:16px;line-height:1.65;color:#2f2a2c;min-height:1.4em;';
        body.setAttribute('contenteditable', 'true');
        body.innerHTML = block.data.html;
        body.addEventListener('input', function () { block.data.html = body.innerHTML; self.sync(); });

        // in-flow toolbar ABOVE the text; CSS shows it only while the block is selected
        var tb = document.createElement('div');
        tb.className = 'lmeg-bd-tb';
        tb.innerHTML =
            '<button type="button" data-c="bold" title="Bold"><b>B</b></button>' +
            '<button type="button" data-c="italic" title="Italic"><i>I</i></button>' +
            '<button type="button" data-c="link" title="Insert link">🔗</button>';
        var mt = document.createElement('select');
        mt.title = 'Insert merge tag';
        mt.innerHTML = '<option value="">Insert merge tag…</option>' +
            ['{name}','{email}','{unique_code}','{referral_link}','{contest_link}','{cart_url}','{site_name}'].map(function (t) { return '<option value="' + t + '">' + t + '</option>'; }).join('');
        tb.appendChild(mt);
        tb.addEventListener('mousedown', function (e) { if (e.target.tagName !== 'SELECT') e.preventDefault(); });
        tb.addEventListener('click', function (e) {
            var bb = e.target.closest('button'); if (!bb) return;
            e.stopPropagation();
            if (bb.dataset.c === 'link') { var u = prompt('Link URL:', 'https://'); if (u) document.execCommand('createLink', false, u); }
            else document.execCommand(bb.dataset.c, false, null);
            block.data.html = body.innerHTML; self.sync();
        });
        mt.addEventListener('change', function () {
            if (!mt.value) return;
            body.focus();
            document.execCommand('insertText', false, mt.value);
            mt.value = ''; block.data.html = body.innerHTML; self.sync();
        });

        inner.appendChild(tb);
        inner.appendChild(body);
    };

    Builder.prototype.render_image = function (block, inner) {
        var self = this;
        var img = document.createElement('img');
        img.style.cssText = 'max-width:100%;height:auto;display:block;border-radius:8px;margin:4px auto;';
        img.src = block.data.src || 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="180"><rect width="600" height="180" fill="#efe6dd"/><text x="300" y="96" font-family="sans-serif" font-size="16" fill="#9a8f94" text-anchor="middle">No image — choose one below</text></svg>');
        img.alt = block.data.alt || '';
        inner.appendChild(img);

        var f = document.createElement('div');
        f.className = 'lmeg-bd-fields';
        f.addEventListener('click', function (e) { e.stopPropagation(); });
        f.innerHTML =
            '<div><button type="button" class="button lmeg-bd-pick">Choose from Media Library</button></div>' +
            '<div><label>Image URL</label><input type="url" data-k="src" placeholder="https://…/image.jpg" value="' + esc(block.data.src) + '"></div>' +
            '<div><label>Alt text (shown if image can\'t load)</label><input type="text" data-k="alt" placeholder="Describe the image" value="' + esc(block.data.alt) + '"></div>' +
            '<div><label>Link when clicked (optional)</label><input type="url" data-k="href" placeholder="https://…" value="' + esc(block.data.href) + '"></div>';
        f.querySelectorAll('input').forEach(function (inp) {
            inp.addEventListener('input', function () { block.data[inp.dataset.k] = inp.value; if (inp.dataset.k === 'src') img.src = inp.value; self.sync(); });
        });
        var pick = f.querySelector('.lmeg-bd-pick');
        pick.addEventListener('click', function (e) {
            e.preventDefault();
            if (!window.wp || !wp.media) { alert('Media library unavailable — paste an image URL instead.'); return; }
            var frame = wp.media({ title: 'Choose image', button: { text: 'Use image' }, multiple: false });
            frame.on('select', function () {
                var a = frame.state().get('selection').first().toJSON();
                block.data.src = a.url; block.data.alt = block.data.alt || a.alt || '';
                self.render(); self.sync();
            });
            frame.open();
        });
        inner.appendChild(f);
    };

    Builder.prototype.render_button = function (block, inner) {
        var self = this;
        var accent = this.accent;
        var a = document.createElement('a');
        a.href = '#';
        a.style.cssText = 'display:inline-block;padding:12px 26px;border-radius:8px;font-weight:600;text-decoration:none;color:#fff;background:' + (block.data.color || accent) + ';margin:6px auto;';
        a.textContent = block.data.label || 'Button';
        var center = document.createElement('div'); center.style.textAlign = 'center'; center.appendChild(a);
        inner.appendChild(center);

        var f = document.createElement('div');
        f.className = 'lmeg-bd-fields';
        f.addEventListener('click', function (e) { e.stopPropagation(); });
        f.innerHTML =
            '<div><label>Button label</label><input type="text" data-k="label" value="' + esc(block.data.label) + '"></div>' +
            '<div><label>Link URL</label><input type="url" data-k="href" placeholder="https://…" value="' + esc(block.data.href) + '"></div>' +
            '<div><label>Button color (hex)</label><input type="text" data-k="color" placeholder="' + accent + '" value="' + esc(block.data.color || accent) + '"></div>';
        f.querySelectorAll('input').forEach(function (inp) {
            inp.addEventListener('input', function () {
                block.data[inp.dataset.k] = inp.value;
                a.textContent = block.data.label || 'Button';
                a.style.background = block.data.color || accent;
                self.sync();
            });
        });
        inner.appendChild(f);
    };

    Builder.prototype.render_divider = function (block, inner) {
        var hr = document.createElement('hr');
        hr.style.cssText = 'border:0;border-top:1px solid #e6ddd2;margin:14px 0;';
        inner.appendChild(hr);
    };

    Builder.prototype.render_spacer = function (block, inner) {
        var self = this;
        var sp = document.createElement('div');
        sp.style.cssText = 'height:' + (block.data.height || 24) + 'px;background:repeating-linear-gradient(45deg,#f3ece3,#f3ece3 6px,#fff 6px,#fff 12px);border-radius:4px;';
        inner.appendChild(sp);
        var f = document.createElement('div');
        f.className = 'lmeg-bd-fields';
        f.addEventListener('click', function (e) { e.stopPropagation(); });
        f.innerHTML = '<div><label>Height (px)</label><input type="number" min="4" max="160" data-k="height" value="' + (block.data.height || 24) + '"></div>';
        var inp = f.querySelector('input');
        inp.addEventListener('input', function () { block.data.height = parseInt(inp.value, 10) || 24; sp.style.height = block.data.height + 'px'; self.sync(); });
        inner.appendChild(f);
    };

    // Selecting a block must NOT re-render the sheet — that would destroy the
    // contenteditable node the user just clicked into and blow away the caret.
    // Toggle a class instead; CSS reveals that block's toolbar / field panel.
    Builder.prototype.select = function (id) {
        this.selected = id;
        Array.prototype.forEach.call(this.sheet.querySelectorAll('.lmeg-bd-block'), function (el) {
            el.classList.toggle('is-selected', el.dataset.id === id);
        });
    };

    // ---- serialize to email-safe HTML ----
    Builder.prototype.toHtml = function () {
        var accent = this.accent;
        return this.blocks.map(function (block) {
            var d = block.data;
            switch (block.type) {
                case 'heading':
                    return '<h2 style="margin:1.2em 0 .5em;font-size:22px;line-height:1.25;color:#2f2a2c;">' + esc(d.text) + '</h2>';
                case 'text':
                    return '<div style="margin:0 0 1.15em;font-size:16px;line-height:1.65;color:#2f2a2c;">' + (d.html || '') + '</div>';
                case 'image':
                    if (!d.src) return '';
                    var im = '<img src="' + esc(d.src) + '" alt="' + esc(d.alt) + '" width="' + (parseInt(d.width, 10) || 600) + '" style="max-width:100%;height:auto;display:block;margin:0 auto 1.15em;border:0;border-radius:10px;" />';
                    return d.href ? '<a href="' + esc(d.href) + '">' + im + '</a>' : im;
                case 'button':
                    return '<div style="text-align:center;margin:0 0 1.2em;"><a href="' + esc(d.href || '#') + '" style="display:inline-block;padding:13px 30px;border-radius:8px;font-weight:600;text-decoration:none;color:#ffffff;background:' + esc(d.color || accent) + ';">' + esc(d.label || 'Button') + '</a></div>';
                case 'divider':
                    return '<hr style="border:0;border-top:1px solid #ddd;margin:1.4em 0;" />';
                case 'spacer':
                    return '<div style="height:' + (parseInt(d.height, 10) || 24) + 'px;line-height:' + (parseInt(d.height, 10) || 24) + 'px;font-size:1px;">&nbsp;</div>';
            }
            return '';
        }).join('\n');
    };

    // Per-keystroke sync: cheap, and it must NOT touch TinyMCE — calling
    // tinymce.setContent() on every input steals focus into the hidden rich
    // editor, which stops you typing in the block. TinyMCE is refreshed once,
    // on demand, via pushToEditor() (mode switch to rich / form submit).
    Builder.prototype.sync = function () {
        var html = this.toHtml();
        if (this.textarea) this.textarea.value = html;
        var store = this.textarea ? document.getElementById(this.textarea.id + '_blocks') : null;
        if (store) store.value = JSON.stringify(this.blocks);
    };

    // Push the built HTML into the target textarea AND TinyMCE. Call this only
    // at deliberate hand-off points, never on every keystroke.
    Builder.prototype.pushToEditor = function () {
        var html = this.toHtml();
        if (this.textarea) this.textarea.value = html;
        if (window.tinymce && this.textarea && tinymce.get(this.textarea.id)) {
            try { tinymce.get(this.textarea.id).setContent(html); } catch (e) {}
        }
        var store = this.textarea ? document.getElementById(this.textarea.id + '_blocks') : null;
        if (store) store.value = JSON.stringify(this.blocks);
    };

    Builder.prototype.seedFromTextarea = function () {
        var store = this.textarea ? document.getElementById(this.textarea.id + '_blocks') : null;
        if (store && store.value) {
            try {
                var arr = JSON.parse(store.value);
                if (Array.isArray(arr) && arr.length) {
                    this.blocks = arr.map(function (b) { return { id: nid(), type: b.type, data: b.data || {} }; });
                }
            } catch (e) {}
        }
    };

    window.LMEGBuilder = {
        init: function (root, textareaId) {
            if (!root) return null;
            var b = new Builder(root, textareaId);
            // Clicking empty canvas deselects — class toggle only, never re-render
            // (re-rendering here would kill focus on an editable block mid-edit).
            root.addEventListener('click', function () { b.select(null); });
            return b;
        }
    };
})();
