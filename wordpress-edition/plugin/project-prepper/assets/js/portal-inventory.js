/**
 * Mein Inventar — Import mit Spalten-Zuordnung + Excel-Export (SheetJS gebündelt).
 *
 * Import: Nach der Dateiwahl liest der Browser die Datei (CSV mit ; , oder Tab,
 *   UTF-8 oder Windows-1252 — oder Excel, erstes Blatt) und zeigt je Spalte eine
 *   Auswahl, welches Feld sie füllt (vorbelegt nach denselben Überschriften wie
 *   der Server, dazu Muster wie „Anzahl" → Menge), eine Vorschau und die Zahl der
 *   Zeilen. Beim Absenden entsteht daraus eine UTF-8-CSV mit den INTERNEN
 *   Schlüsseln als Kopfzeile; sie ersetzt die Datei im selben Feld und geht an
 *   den unveränderten Server-Import. Ohne JS lädt das Formular die Datei direkt hoch.
 * Export (Excel): holt den vorhandenen CSV-Export und schreibt daraus eine .xlsx.
 *
 * Felder, Texte und die Kopfzeilen-Tabelle kommen übersetzt aus PHP (ppImport).
 */
(function () {
	'use strict';

	var tools = document.querySelector('.pp-inv-tools');
	if (!tools) return;
	var hasXlsx = typeof XLSX !== 'undefined';

	/* ==== Reine Helfer (ohne DOM) — Parsen, Zuordnen, CSV bauen ==== */
	/* pp-import-lib:start */

	// Muster für Überschriften, die der Server nicht wörtlich kennt. Eingabe ist
	// klein geschrieben; die ERSTE passende Zeile entscheidet (Reihenfolge zählt:
	// „Seriennummer" vor „Nummer", Preise vor „Stück", Name ganz zuletzt).
	// Ziel '' = bewusst ignorieren (Links/URLs landen sonst z. B. bei „Hersteller").
	var AUTO_MAP = [
		[/link\b|url\b|^https?|website|webseite|homepage/, ''],
		[/seriennummer|serien-?nr|serial|^s\/n$/, 'serial_number'],
		[/inventar|inventory|artikel-?n(umme)?r|art\.?-?nr|^inv\b|^nr\.?$|^nummer$|^number$|^no\.?$|^id$/, 'inventory_number'],
		[/tagessatz|tagespreis|tagesmiete|leihgebühr|leihpreis|miete|preis\s*\/\s*tag|pro tag|per day|daily/, 'cost_per_day'],
		[/kaufpreis|einkaufspreis|anschaffungspreis|neupreis|purchase price|^ek\b/, 'purchase_price'],
		[/kaufdatum|anschaffungsdatum|gekauft|purchase date|purchased/, 'purchase_date'],
		[/zeitwert|restwert|aktueller wert|current value|^wert$|^value$/, 'current_value'],
		[/menge|anzahl|stückzahl|^stück$|^stk\.?$|quantity|^qty$|^amount$|^count$/, 'quantity'],
		[/zustand|condition/, 'condition'],
		[/kategorie|category|rubrik|^gruppe$|^group$/, 'category_name'],
		[/beschreibung|description|^details?$/, 'description'],
		[/hersteller|manufacturer|^marke$|^brand$|^make$/, 'manufacturer'],
		[/modell|model|typ$|^type$/, 'model'],
		[/lagerort|standort|lager|^ort$|location|storage/, 'location'],
		[/abmessung|maße|masse|dimension|größe|grösse|^size$/, 'dimensions'],
		[/leistung|watt|power/, 'power_watts'],
		[/zubehör|zubehoer|accessor/, 'accessories'],
		[/^tags?$|schlagw|stichw|keyword/, 'tags'],
		[/notiz|bemerkung|anmerkung|kommentar|note|comment|freifeld/, 'notes'],
		[/^name$|bezeichnung|artikel|gerät|^item|^titel$|^title$|produkt|product/, 'name']
	];

	function normHead(s) {
		return String(s == null ? '' : s).trim().toLowerCase();
	}

	function isBlank(row) {
		for (var i = 0; i < row.length; i++) {
			if (String(row[i] == null ? '' : row[i]).trim() !== '') return false;
		}
		return true;
	}

	function pad2(n) { return n < 10 ? '0' + n : String(n); }

	/** Bytes → Text: UTF-8, sonst Windows-1252 (älteres Excel „CSV (Trennzeichen-getrennt)"). */
	function decodeText(buf) {
		var bytes = new Uint8Array(buf);
		try {
			return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
		} catch (e) {
			return new TextDecoder('windows-1252').decode(bytes);
		}
	}

	/** Häufigstes Trennzeichen (; , Tab) der ersten Zeile außerhalb von Anführungszeichen. */
	function detectDelimiter(text) {
		var counts = { ';': 0, ',': 0, '\t': 0 };
		var quoted = false;
		for (var i = 0; i < text.length; i++) {
			var ch = text.charAt(i);
			if (ch === '"') quoted = !quoted;
			else if (!quoted && (ch === '\n' || ch === '\r')) break;
			else if (!quoted && counts.hasOwnProperty(ch)) counts[ch]++;
		}
		var best = ';';
		if (counts[','] > counts[best]) best = ',';
		if (counts['\t'] > counts[best]) best = '\t';
		return best;
	}

	/**
	 * CSV-Text → { rows, offset }. Leere Zeilen MITTEN in der Datei bleiben
	 * erhalten (Zeilennummern im Server-Bericht stimmen dann mit der Tabelle
	 * überein); offset = Zeilen vor der Kopfzeile (Leerzeilen, „sep=;").
	 */
	function parseCsv(text) {
		text = String(text).replace(/^﻿/, '');
		var offset = 0;
		var m;
		while ((m = /^[ \t;,]*(\r\n|\n|\r)/.exec(text))) {
			text = text.slice(m[0].length);
			offset++;
		}
		var delim = '';
		m = /^sep=([^"\r\n])[ ]*(\r\n|\n|\r|$)/i.exec(text);
		if (m) {
			delim = m[1];
			text = text.slice(m[0].length);
			offset++;
		}
		if (!delim) delim = detectDelimiter(text);

		var rows = [], row = [], cell = '', quoted = false, wasQuoted = false;
		for (var i = 0; i < text.length; i++) {
			var ch = text.charAt(i);
			if (quoted) {
				if (ch === '"') {
					if (text.charAt(i + 1) === '"') { cell += '"'; i++; } else { quoted = false; }
				} else {
					cell += ch;
				}
			} else if (ch === '"' && cell === '' && !wasQuoted) {
				quoted = true; // Anführungszeichen zählen nur am Zellenanfang (12" Monitor bleibt Text)
				wasQuoted = true;
			} else if (ch === delim) {
				row.push(cell); cell = ''; wasQuoted = false;
			} else if (ch === '\n' || ch === '\r') {
				if (ch === '\r' && text.charAt(i + 1) === '\n') i++;
				row.push(cell); rows.push(row);
				row = []; cell = ''; wasQuoted = false;
			} else {
				cell += ch;
			}
		}
		if (cell !== '' || row.length || wasQuoted) { row.push(cell); rows.push(row); }
		while (rows.length && isBlank(rows[rows.length - 1])) rows.pop();
		return { rows: rows, offset: offset };
	}

	/** Zahl als Text: angezeigtes Format, wenn es eine schlichte Zahl ist (00123, 12.50), sonst der Wert. */
	function numText(cell) {
		var w = cell.w == null ? '' : String(cell.w).trim();
		if (/^-?\d+(\.\d+)?$/.test(w)) return w;
		var v = cell.v;
		return String(Math.round(v * 1e6) / 1e6);
	}

	/** Eine SheetJS-Zelle → Text. Datumszellen zeitzonensicher als YYYY-MM-DD. */
	function cellText(X, cell, date1904) {
		if (!cell || cell.v == null) return '';
		if (cell.t === 'n' && cell.z && X.SSF.is_date(cell.z)) {
			var p = X.SSF.parse_date_code(cell.v, { date1904: date1904 });
			if (p && p.y) return p.y + '-' + pad2(p.m) + '-' + pad2(p.d);
		}
		if (cell.t === 'd' && cell.v instanceof Date) {
			return cell.v.getFullYear() + '-' + pad2(cell.v.getMonth() + 1) + '-' + pad2(cell.v.getDate());
		}
		if (cell.t === 'n') return numText(cell);
		if (cell.t === 'e') return '';
		if (cell.t === 'b') return cell.w != null ? String(cell.w) : (cell.v ? 'TRUE' : 'FALSE');
		return String(cell.v);
	}

	/** Excel (erstes Blatt) → { rows, offset }. Große leere Bereiche werden gekappt. */
	function parseXlsx(X, buf, maxRows) {
		var wb = X.read(new Uint8Array(buf), { type: 'array', cellNF: true });
		var ws = wb.Sheets[wb.SheetNames[0]];
		if (!ws || !ws['!ref']) return { rows: [], offset: 0 };
		var date1904 = !!(wb.Workbook && wb.Workbook.WBProps && wb.Workbook.WBProps.date1904);
		var range = X.utils.decode_range(ws['!ref']);
		// Formatierte Leerbereiche (A1:XFD1048576) nicht Zelle für Zelle ablaufen.
		var lastRow = Math.min(range.e.r, range.s.r + maxRows + 1000);
		var lastCol = Math.min(range.e.c, range.s.c + 199);
		var rows = [];
		for (var r = range.s.r; r <= lastRow; r++) {
			var row = [];
			for (var c = range.s.c; c <= lastCol; c++) {
				row.push(cellText(X, ws[X.utils.encode_cell({ r: r, c: c })], date1904));
			}
			rows.push(row);
		}
		var offset = range.s.r; // Blatt beginnt nicht in Zeile 1
		while (rows.length && isBlank(rows[0])) { rows.shift(); offset++; }
		while (rows.length && isBlank(rows[rows.length - 1])) rows.pop();
		// Leere Spalten rechts abschneiden.
		var width = 0;
		rows.forEach(function (row) {
			for (var i = row.length - 1; i >= width; i--) {
				if (String(row[i]).trim() !== '') { width = i + 1; break; }
			}
		});
		rows = rows.map(function (row) { return row.slice(0, width); });
		return { rows: rows, offset: offset };
	}

	/**
	 * Vorschlag je Spalte: erst wörtlich wie der Server (Export-Überschriften,
	 * interne Schlüssel, Aliase), dann Muster. Jedes Feld höchstens einmal.
	 */
	function suggestMapping(headers, cfg) {
		var valid = {};
		cfg.fields.forEach(function (f) { valid[f.key] = true; });
		var result = headers.map(function () { return ''; });
		var taken = {};
		headers.forEach(function (h, i) {
			var key = cfg.heads[normHead(h)];
			if (key && valid[key] && !taken[key]) { result[i] = key; taken[key] = true; }
		});
		headers.forEach(function (h, i) {
			var nh = normHead(h);
			if (result[i] || !nh || cfg.heads.hasOwnProperty(nh)) return;
			for (var j = 0; j < AUTO_MAP.length; j++) {
				if (AUTO_MAP[j][0].test(nh)) {
					var key = AUTO_MAP[j][1];
					if (key && valid[key] && !taken[key]) { result[i] = key; taken[key] = true; }
					return;
				}
			}
		});
		return result;
	}

	/** Zustand wie der Server: bekannte Schreibweise → Schlüssel, sonst ''. */
	function conditionKey(raw, cfg) {
		var k = normHead(raw);
		return k && cfg.conditions.hasOwnProperty(k) ? cfg.conditions[k] : '';
	}

	/** Datum wie der Server: YYYY-MM-DD (auch mit Uhrzeit) oder D.M.YYYY → YYYY-MM-DD, sonst ''. */
	function normDate(v) {
		v = String(v == null ? '' : v).trim();
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:$|[ T])/.exec(v);
		var y, mo, d;
		if (m) { y = +m[1]; mo = +m[2]; d = +m[3]; }
		else if ((m = /^(\d{1,2})\.(\d{1,2})\.(\d{4})$/.exec(v))) { y = +m[3]; mo = +m[2]; d = +m[1]; }
		else return '';
		var dt = new Date(Date.UTC(y, mo - 1, d));
		if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) return '';
		return y + '-' + pad2(mo) + '-' + pad2(d);
	}

	/** Werte einer Zeile nach Zuordnung: { key: Wert } — nur nicht-leere, erster Wert zählt (wie der Server). */
	function mapRow(row, mapping) {
		var out = {};
		for (var i = 0; i < mapping.length; i++) {
			var key = mapping[i];
			if (!key) continue;
			var v = String(row[i] == null ? '' : row[i]).trim();
			if (v !== '' && !out[key]) out[key] = v;
		}
		return out;
	}

	/**
	 * Zählt wie der Server: Leerzeilen (nach Zuordnung) zählen nicht, nach
	 * maxRows Datenzeilen ist Schluss. → { total, named, unnamed, truncated }.
	 */
	function countRows(dataRows, mapping, maxRows) {
		var res = { total: 0, named: 0, unnamed: 0, truncated: false };
		for (var i = 0; i < dataRows.length; i++) {
			var d = mapRow(dataRows[i], mapping);
			if (!Object.keys(d).length) continue;
			if (res.total >= maxRows) { res.truncated = true; break; }
			res.total++;
			if (d.name) res.named++; else res.unnamed++;
		}
		return res;
	}

	function csvCell(v) {
		v = v == null ? '' : String(v);
		return /[";\r\n]|^\s|\s$/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
	}

	/**
	 * Neue CSV für den Server: Kopfzeile = interne Schlüssel, nur zugeordnete
	 * Spalten, Semikolon, UTF-8 mit BOM. Leerzeilen bleiben (Zeilennummern);
	 * nach maxRows+1 Datenzeilen ist Schluss — die eine Zeile zu viel lässt den
	 * Server „nur die ersten N" melden, ohne die ganze Datei zu schicken.
	 */
	function buildCsv(dataRows, mapping, maxRows) {
		var cols = [];
		mapping.forEach(function (key, i) { if (key) cols.push(i); });
		var lines = [cols.map(function (i) { return mapping[i]; }).join(';')];
		var count = 0;
		for (var r = 0; r < dataRows.length; r++) {
			var row = dataRows[r];
			var cells = cols.map(function (i) { return String(row[i] == null ? '' : row[i]).trim(); });
			if (!isBlank(cells) && ++count > maxRows + 1) break;
			lines.push(cells.map(csvCell).join(';'));
		}
		return '﻿' + lines.join('\r\n') + '\r\n';
	}

	/** Mini-sprintf für die übersetzten Texte: %s, %d, %1$s, %2$d … */
	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var n = 0;
		return String(str).replace(/%(?:(\d+)\$)?([sd])/g, function (all, pos, type) {
			var v = args[pos ? parseInt(pos, 10) - 1 : n++];
			return type === 'd' ? String(parseInt(v, 10) || 0) : String(v == null ? '' : v);
		});
	}

	/* pp-import-lib:end */

	/* ==== Import-Formular ==== */

	var form = tools.querySelector('form[data-pp-import]');
	var cfg = window.ppImport;
	var canSwap = (function () {
		try { return typeof DataTransfer === 'function' && !!new DataTransfer().items; } catch (e) { return false; }
	})();
	if (form && cfg && cfg.fields && window.FileReader && window.TextDecoder && canSwap) {
		initImport(form, cfg);
	}

	function initImport(form, cfg) {
		var t = cfg.i18n || {};
		var maxRows = parseInt(cfg.maxRows, 10) || 500;
		var fileInput = form.querySelector('input[type="file"][name="pp_file"]');
		var mapBox = form.querySelector('[data-pp-import-map]');
		var statusBox = form.querySelector('[data-pp-import-status]');
		var submit = form.querySelector('[data-pp-import-submit]');
		if (!fileInput || !mapBox || !statusBox || !submit) return;
		var submitLabel = submit.textContent;
		var labels = {};
		cfg.fields.forEach(function (f) { labels[f.key] = f.label; });

		var state = null; // { name, headers, data, offset, mapping, selects, cols, preview, ready }
		var token = 0;    // verwirft Ergebnisse einer zwischenzeitlich ersetzten Datei

		function el(tag, cls, text) {
			var n = document.createElement(tag);
			if (cls) n.className = cls;
			if (text != null) n.textContent = text;
			return n;
		}

		function setStatus(lines, isError) {
			statusBox.textContent = '';
			(lines || []).forEach(function (line) {
				statusBox.appendChild(el('span', 'pp-import__line', line));
			});
			statusBox.hidden = !lines || !lines.length;
			statusBox.classList.toggle('pp-import__status--err', !!isError);
		}

		function reset() {
			state = null;
			mapBox.hidden = true;
			mapBox.textContent = '';
			form.classList.remove('pp-import--mapping');
			submit.textContent = submitLabel;
			submit.disabled = false;
			setStatus(null);
		}

		function fail(msg) {
			state = null;
			mapBox.hidden = true;
			mapBox.textContent = '';
			form.classList.remove('pp-import--mapping');
			submit.textContent = submitLabel;
			submit.disabled = true;
			setStatus([msg], true);
		}

		fileInput.addEventListener('change', function () {
			var file = fileInput.files && fileInput.files[0];
			var my = ++token;
			if (!file) { reset(); return; }
			submit.disabled = true;
			setStatus([t.reading]);
			var reader = new FileReader();
			reader.onload = function () {
				if (my !== token) return;
				var parsed;
				try {
					var head = new Uint8Array(reader.result.slice(0, 4));
					var isZip = head[0] === 0x50 && head[1] === 0x4B && head[2] === 0x03 && head[3] === 0x04;
					var isOle = head[0] === 0xD0 && head[1] === 0xCF && head[2] === 0x11 && head[3] === 0xE0;
					if (isZip || isOle || /\.(xlsx|xls|ods)$/i.test(file.name)) {
						if (!hasXlsx) throw new Error('no xlsx');
						parsed = parseXlsx(XLSX, reader.result, maxRows);
					} else {
						parsed = parseCsv(decodeText(reader.result));
					}
				} catch (e) {
					fail(t.readError);
					return;
				}
				if (!parsed.rows.length) { fail(t.readError); return; }
				if (parsed.rows.length < 2) { fail(t.noRows); return; }
				var headers = parsed.rows[0].map(function (h) { return String(h == null ? '' : h).trim(); });
				var width = parsed.rows.reduce(function (w, r) { return Math.max(w, r.length); }, 0);
				while (headers.length < width) headers.push('');
				state = {
					name: file.name,
					headers: headers,
					data: parsed.rows.slice(1),
					offset: parsed.offset,
					mapping: suggestMapping(headers, cfg)
				};
				renderMapping();
				update();
			};
			reader.onerror = function () { if (my === token) fail(t.readError); };
			reader.readAsArrayBuffer(file);
		});

		/** Bis zu drei verschiedene Beispielwerte einer Spalte. */
		function samples(col) {
			var seen = {}, out = [];
			for (var r = 0; r < state.data.length && out.length < 3; r++) {
				var v = String(state.data[r][col] == null ? '' : state.data[r][col]).trim();
				if (v !== '' && !seen[v]) { seen[v] = true; out.push(v.length > 40 ? v.slice(0, 39) + '…' : v); }
			}
			return out.join(' · ');
		}

		function renderMapping() {
			mapBox.textContent = '';
			mapBox.appendChild(el('p', 'pp-import__intro', t.intro));
			var cols = el('div', 'pp-import__cols');
			state.cols = [];
			state.headers.forEach(function (h, i) {
				var s = samples(i);
				// Spalte ohne Überschrift UND ohne Werte (z. B. „Name;Menge;;"): nicht anzeigen.
				if (!h && !s) return;
				var box = el('label', 'pp-import__col');
				box.appendChild(el('span', 'pp-import__head', h || fmt(t.column, i + 1)));
				var sel = document.createElement('select');
				sel.appendChild(new Option(t.ignore, ''));
				cfg.fields.forEach(function (f) { sel.appendChild(new Option(f.label, f.key)); });
				sel.value = state.mapping[i] || '';
				sel.addEventListener('change', function () {
					state.mapping[i] = sel.value;
					update();
				});
				box.appendChild(sel);
				if (s) box.appendChild(el('span', 'pp-import__sample', s));
				cols.appendChild(box);
				state.cols[i] = box; // Lücken für ausgeblendete Spalten — forEach überspringt sie
			});
			mapBox.appendChild(cols);
			mapBox.appendChild(el('p', 'pp-import__sub', t.preview));
			state.preview = el('div', 'pp-import__preview');
			mapBox.appendChild(state.preview);
			mapBox.hidden = false;
			form.classList.add('pp-import--mapping');
		}

		function renderPreview(badCond, badDate) {
			var box = state.preview;
			box.textContent = '';
			var shown = 0;
			for (var r = 0; r < state.data.length && shown < 5; r++) {
				var d = mapRow(state.data[r], state.mapping);
				if (!Object.keys(d).length) continue;
				shown++;
				var card = el('div', 'pp-import__card' + (d.name ? '' : ' pp-import__card--skip'));
				card.appendChild(el('span', 'pp-import__card-title', d.name || t.noName));
				cfg.fields.forEach(function (f) {
					if (f.key === 'name' || !d[f.key]) return;
					var v = d[f.key];
					if (f.key === 'condition') {
						v = cfg.conditionLabels[conditionKey(v, cfg) || 'good'] || v;
					} else if (f.key === 'purchase_date') {
						v = normDate(v); // unlesbar → bleibt leer (steht im Hinweis darunter)
						if (!v) return;
					}
					var line = el('span', 'pp-import__kv');
					line.appendChild(el('span', 'pp-import__kv-label', f.label + ': '));
					line.appendChild(document.createTextNode(v));
					card.appendChild(line);
				});
				box.appendChild(card);
			}
			// Werte, die der Server nicht versteht — über ALLE Zeilen, damit nichts still verschwindet.
			var cIdx = state.mapping.indexOf('condition');
			var dIdx = state.mapping.indexOf('purchase_date');
			for (var i = 0; i < state.data.length; i++) {
				var row = state.data[i];
				var cv = cIdx >= 0 ? String(row[cIdx] == null ? '' : row[cIdx]).trim() : '';
				if (cv && !conditionKey(cv, cfg) && badCond.indexOf(cv) < 0) badCond.push(cv);
				var dv = dIdx >= 0 ? String(row[dIdx] == null ? '' : row[dIdx]).trim() : '';
				if (dv && !normDate(dv) && badDate.indexOf(dv) < 0) badDate.push(dv);
			}
		}

		function list(values) {
			var shown = values.slice(0, 5).join(', ');
			return values.length > 5 ? shown + ', …' : shown;
		}

		/** Zuordnung prüfen, Vorschau + Zählung + Knopf aktualisieren. */
		function update() {
			var used = {}, dups = [];
			state.mapping.forEach(function (key) {
				if (!key) return;
				if (used[key] && dups.indexOf(key) < 0) dups.push(key);
				used[key] = true;
			});
			state.cols.forEach(function (box, i) {
				var key = state.mapping[i];
				box.classList.toggle('pp-import__col--dup', !!key && dups.indexOf(key) >= 0);
				box.classList.toggle('pp-import__col--off', !key);
			});
			var badCond = [], badDate = [];
			renderPreview(badCond, badDate);

			var errors = [];
			dups.forEach(function (key) { errors.push(fmt(t.duplicate, labels[key] || key)); });
			if (!used.name) errors.push(t.needName);
			var c = countRows(state.data, state.mapping, maxRows);
			if (!errors.length && !c.total) errors.push(t.noRows);
			else if (!errors.length && !c.named) errors.push(t.noNamedRows);

			state.ready = !errors.length;
			submit.disabled = !state.ready;
			submit.textContent = state.ready ? fmt(c.named === 1 ? t.importOne : t.importMany, c.named) : submitLabel;
			if (errors.length) {
				setStatus(errors, true);
				return;
			}
			var notes = [];
			if (c.unnamed) notes.push(fmt(c.unnamed === 1 ? t.skipOne : t.skipMany, c.unnamed));
			if (c.truncated) notes.push(fmt(t.limit, maxRows));
			if (badCond.length) notes.push(fmt(t.badCondition, cfg.conditionLabels.good || 'good', list(badCond)));
			if (badDate.length) notes.push(fmt(t.badDate, list(badDate)));
			setStatus(notes, false);
		}

		form.addEventListener('submit', function (e) {
			if (!state) return; // keine Datei gelesen → Browser-Validierung / normaler Upload
			if (!state.ready || form.hasAttribute('data-pp-submitting')) {
				e.preventDefault();
				return;
			}
			try {
				var csv = buildCsv(state.data, state.mapping, maxRows);
				var out = new File([csv], state.name.replace(/\.[^.]+$/, '') + '.csv', { type: 'text/csv' });
				var dt = new DataTransfer();
				dt.items.add(out);
				fileInput.files = dt.files; // ersetzt die Originaldatei durch die zugeordnete CSV
			} catch (err) {
				e.preventDefault();
				fail(t.readError);
				return;
			}
			var off = form.querySelector('input[name="pp_row_offset"]');
			if (!off) {
				off = el('input');
				off.type = 'hidden';
				off.name = 'pp_row_offset';
				form.appendChild(off);
			}
			off.value = String(state.offset);
			// Doppelklick würde doppelt importieren.
			form.setAttribute('data-pp-submitting', '1');
			setTimeout(function () { submit.disabled = true; }, 0);
		});

		// Zurück-Taste (bfcache): Sperre lösen, Formular wieder benutzbar.
		window.addEventListener('pageshow', function (e) {
			if (!e.persisted) return;
			form.removeAttribute('data-pp-submitting');
			if (state) update(); else reset();
		});
	}

	/* ==== Export (Excel): CSV holen → XLSX schreiben ==== */
	var xlsxBtn = hasXlsx ? tools.querySelector('[data-pp-xlsx-export]') : null;
	if (xlsxBtn) {
		xlsxBtn.addEventListener('click', function (e) {
			e.preventDefault();
			var url = xlsxBtn.getAttribute('data-pp-xlsx-export');
			var name = (xlsxBtn.getAttribute('data-pp-xlsx-name') || 'mein-inventar') + '.xlsx';
			xlsxBtn.setAttribute('aria-busy', 'true');
			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.text(); })
				.then(function (text) {
					var wb = XLSX.read(text.replace(/^﻿/, ''), { type: 'string' });
					XLSX.writeFile(wb, name);
				})
				.catch(function () { /* still */ })
				.then(function () { xlsxBtn.removeAttribute('aria-busy'); });
		});
	}
})();
