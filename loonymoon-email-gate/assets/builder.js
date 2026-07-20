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
        this.dragIndex = null;
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
            b.addEventListener('click', function () { self.addBlock(type); });
            b.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/lmeg-new', type); self.dragIndex = null; });
            pal.appendChild(b);
        });
        // canvas
        var canvas = document.createElement('div');
        canvas.className = 'lmeg-bd-canvas';
        var sheet = document.createElement('div');
        sheet.className = 'lmeg-bd-sheet';
        canvas.appendChild(sheet);
        this.sheet = sheet;

        this.root.classList.add('lmeg-builder');
        this.root.appendChild(pal);
        this.root.appendChild(canvas);
    };

    Builder.prototype.addBlock = function (type, atIndex) {
        var block = { id: nid(), type: type, data: BLOCKS[type].def() };
        if (atIndex == null || atIndex < 0 || atIndex > this.blocks.length) this.blocks.push(block);
        else this.blocks.splice(atIndex, 0, block);
        this.selected = block.id;
        this.render();
        this.sync();
    };

    Builder.prototype.remove = function (id) {
        this.blocks = this.blocks.filter(function (b) { return b.id !== id; });
        this.render(); this.sync();
    };
    Builder.prototype.duplicate = function (id) {
        var i = this.blocks.findIndex(function (b) { return b.id === id; });
        if (i < 0) return;
        var copy = { id: nid(), type: this.blocks[i].type, data: JSON.parse(JSON.stringify(this.blocks[i].data)) };
        this.blocks.splice(i + 1, 0, copy);
        this.render(); this.sync();
    };
    Builder.prototype.move = function (id, dir) {
        var i = this.blocks.findIndex(function (b) { return b.id === id; });
        var j = i + dir;
        if (i < 0 || j < 0 || j >= this.blocks.length) return;
        var t = this.blocks[i]; this.blocks[i] = this.blocks[j]; this.blocks[j] = t;
        this.render(); this.sync();
    };

    Builder.prototype.render = function () {
        var self = this;
        this.sheet.innerHTML = '';
        if (!this.blocks.length) {
            var e = document.createElement('div');
            e.className = 'lmeg-bd-empty';
            e.textContent = 'Click or drag a block from the left to start building.';
            this.sheet.appendChild(e);
            return;
        }
        this.blocks.forEach(function (block, idx) {
            self.sheet.appendChild(self.dropZone(idx));
            self.sheet.appendChild(self.renderBlock(block, idx));
        });
        this.sheet.appendChild(this.dropZone(this.blocks.length));
    };

    Builder.prototype.dropZone = function (index) {
        var self = this;
        var dz = document.createElement('div');
        dz.className = 'lmeg-bd-drop';
        dz.addEventListener('dragover', function (e) { e.preventDefault(); dz.classList.add('is-over'); });
        dz.addEventListener('dragleave', function () { dz.classList.remove('is-over'); });
        dz.addEventListener('drop', function (e) {
            e.preventDefault(); dz.classList.remove('is-over');
            var newType = e.dataTransfer.getData('text/lmeg-new');
            if (newType) { self.addBlock(newType, index); return; }
            if (self.dragIndex != null) {
                var from = self.dragIndex, to = index;
                var b = self.blocks.splice(from, 1)[0];
                if (from < to) to -= 1;
                self.blocks.splice(to, 0, b);
                self.dragIndex = null;
                self.render(); self.sync();
            }
        });
        return dz;
    };

    Builder.prototype.renderBlock = function (block, idx) {
        var self = this;
        var wrap = document.createElement('div');
        wrap.className = 'lmeg-bd-block' + (this.selected === block.id ? ' is-selected' : '');
        wrap.dataset.id = block.id;
        wrap.addEventListener('click', function (e) { e.stopPropagation(); self.select(block.id); });

        // drag reorder
        wrap.setAttribute('draggable', 'true');
        wrap.addEventListener('dragstart', function (e) { self.dragIndex = idx; wrap.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; });
        wrap.addEventListener('dragend', function () { wrap.classList.remove('is-dragging'); });

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

    Builder.prototype.render_text = function (block, inner, wrap) {
        var self = this;
        // mini toolbar
        var tb = document.createElement('div');
        tb.className = 'lmeg-bd-tb';
        tb.innerHTML =
            '<button type="button" data-c="bold"><b>B</b></button>' +
            '<button type="button" data-c="italic"><i>I</i></button>' +
            '<button type="button" data-c="link">🔗</button>';
        var mt = document.createElement('select');
        mt.innerHTML = '<option value="">merge…</option>' +
            ['{name}','{email}','{unique_code}','{referral_link}','{site_name}'].map(function (t) { return '<option value="' + t + '">' + t + '</option>'; }).join('');
        tb.appendChild(mt);
        tb.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep selection
        tb.addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            e.stopPropagation();
            if (b.dataset.c === 'link') { var u = prompt('Link URL:', 'https://'); if (u) document.execCommand('createLink', false, u); }
            else document.execCommand(b.dataset.c, false, null);
            block.data.html = body.innerHTML; self.sync();
        });
        mt.addEventListener('change', function () {
            if (!mt.value) return;
            document.execCommand('insertText', false, mt.value);
            mt.value = ''; block.data.html = body.innerHTML; self.sync();
        });
        wrap.appendChild(tb);

        var body = document.createElement('div');
        body.style.cssText = 'margin:6px 0;font-size:16px;line-height:1.65;color:#2f2a2c;min-height:1.4em;';
        body.setAttribute('contenteditable', 'true');
        body.innerHTML = block.data.html;
        body.addEventListener('input', function () { block.data.html = body.innerHTML; self.sync(); });
        inner.appendChild(body);
    };

    Builder.prototype.render_image = function (block, inner) {
        var self = this;
        var img = document.createElement('img');
        img.style.cssText = 'max-width:100%;height:auto;display:block;border-radius:8px;margin:4px auto;';
        img.src = block.data.src || 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="180"><rect width="600" height="180" fill="#efe6dd"/><text x="300" y="96" font-family="sans-serif" font-size="16" fill="#9a8f94" text-anchor="middle">No image — choose one below</text></svg>');
        img.alt = block.data.alt || '';
        inner.appendChild(img);

        if (this.selected === block.id) {
            var f = document.createElement('div');
            f.className = 'lmeg-bd-fields';
            f.addEventListener('click', function (e) { e.stopPropagation(); });
            f.innerHTML =
                '<div><button type="button" class="button lmeg-bd-pick">Choose from Media Library</button></div>' +
                '<div><label>Image URL</label><input type="url" data-k="src" value="' + esc(block.data.src) + '"></div>' +
                '<div class="row"><div><label>Alt text</label><input type="text" data-k="alt" value="' + esc(block.data.alt) + '"></div>' +
                '<div><label>Link (optional)</label><input type="url" data-k="href" value="' + esc(block.data.href) + '"></div></div>';
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
        }
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

        if (this.selected === block.id) {
            var f = document.createElement('div');
            f.className = 'lmeg-bd-fields';
            f.addEventListener('click', function (e) { e.stopPropagation(); });
            f.innerHTML =
                '<div class="row"><div><label>Label</label><input type="text" data-k="label" value="' + esc(block.data.label) + '"></div>' +
                '<div><label>Color</label><input type="text" data-k="color" value="' + esc(block.data.color || accent) + '"></div></div>' +
                '<div><label>Link URL</label><input type="url" data-k="href" value="' + esc(block.data.href) + '"></div>';
            f.querySelectorAll('input').forEach(function (inp) {
                inp.addEventListener('input', function () {
                    block.data[inp.dataset.k] = inp.value;
                    a.textContent = block.data.label || 'Button';
                    a.style.background = block.data.color || accent;
                    self.sync();
                });
            });
            inner.appendChild(f);
        }
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
        if (this.selected === block.id) {
            var f = document.createElement('div');
            f.className = 'lmeg-bd-fields';
            f.addEventListener('click', function (e) { e.stopPropagation(); });
            f.innerHTML = '<div><label>Height (px)</label><input type="number" min="4" max="160" data-k="height" value="' + (block.data.height || 24) + '"></div>';
            var inp = f.querySelector('input');
            inp.addEventListener('input', function () { block.data.height = parseInt(inp.value, 10) || 24; sp.style.height = block.data.height + 'px'; self.sync(); });
            inner.appendChild(f);
        }
    };

    Builder.prototype.select = function (id) {
        this.selected = id; this.render();
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

    Builder.prototype.sync = function () {
        var html = this.toHtml();
        if (this.textarea) this.textarea.value = html;
        if (window.tinymce && tinymce.get(this.textarea && this.textarea.id)) {
            try { tinymce.get(this.textarea.id).setContent(html); } catch (e) {}
        }
        // stash blocks JSON on a sibling hidden input if present
        var store = document.getElementById(this.textarea.id + '_blocks');
        if (store) store.value = JSON.stringify(this.blocks);
    };

    // Hydrate from a previously-saved blocks JSON if present; else start empty.
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
            // deselect on canvas background click
            var b = new Builder(root, textareaId);
            root.addEventListener('click', function () { b.selected = null; b.render(); });
            return b;
        }
    };
})();
