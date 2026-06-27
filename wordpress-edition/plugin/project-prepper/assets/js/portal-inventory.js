/**
 * Mein Inventar — Excel-Brücke (nutzt das gebündelte SheetJS).
 *
 * Import: eine gewählte .xlsx/.xls wird vor dem Absenden clientseitig in CSV
 *   (Semikolon + BOM) umgewandelt und in dasselbe Datei-Feld gelegt — der
 *   bestehende Server-CSV-Import bleibt unverändert. CSV-Dateien laufen direkt durch.
 * Export (Excel): holt den vorhandenen CSV-Export und schreibt daraus eine .xlsx.
 */
(function () {
	if (typeof XLSX === 'undefined') return;
	var tools = document.querySelector('.pp-inv-tools');
	if (!tools) return;

	/* ---- Import: XLSX → CSV vor dem Submit ---- */
	var fileInput = tools.querySelector('input[type="file"][name="pp_file"]');
	if (fileInput) {
		fileInput.addEventListener('change', function (e) {
			var f = e.target.files && e.target.files[0];
			if (!f || !/\.xlsx?$/i.test(f.name)) return; // CSV unverändert durchlassen
			var reader = new FileReader();
			reader.onload = function (ev) {
				try {
					var wb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array' });
					var ws = wb.Sheets[wb.SheetNames[0]];
					var csv = XLSX.utils.sheet_to_csv(ws, { FS: ';' });
					var csvFile = new File(['﻿' + csv], f.name.replace(/\.xlsx?$/i, '.csv'), { type: 'text/csv' });
					var dt = new DataTransfer();
					dt.items.add(csvFile);
					fileInput.files = dt.files; // ersetzt die XLSX durch die konvertierte CSV
				} catch (err) {
					/* bei Parse-Fehler bleibt die Originaldatei stehen */
				}
			};
			reader.readAsArrayBuffer(f);
		});
	}

	/* ---- Export (Excel): CSV holen → XLSX schreiben ---- */
	var xlsxBtn = tools.querySelector('[data-pp-xlsx-export]');
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
