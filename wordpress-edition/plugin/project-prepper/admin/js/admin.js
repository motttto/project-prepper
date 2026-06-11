/**
 * Project Prepper — Admin-UI (Vanilla JS, kein Build-Step).
 * Drei Seiten: Inventar, Verleih, Kategorien. Spricht die eigene REST-API an.
 */
(function () {
	"use strict";

	var root = document.getElementById("pp-admin");
	if (!root || typeof ppConfig === "undefined") return;

	var page = root.dataset.page;

	/* ---------- API-Helper ---------- */

	function api(path, options) {
		options = options || {};
		options.headers = Object.assign(
			{ "Content-Type": "application/json", "X-WP-Nonce": ppConfig.nonce },
			options.headers || {}
		);
		return fetch(ppConfig.restUrl + path, options).then(function (res) {
			return res.json().then(function (body) {
				if (!res.ok) {
					throw new Error(body && body.message ? body.message : "Fehler " + res.status);
				}
				return body;
			});
		});
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (key) {
				if (key === "text") node.textContent = attrs[key];
				else if (key === "html") node.innerHTML = attrs[key];
				else if (key.indexOf("on") === 0) node.addEventListener(key.slice(2), attrs[key]);
				else node.setAttribute(key, attrs[key]);
			});
		}
		(children || []).forEach(function (child) {
			if (child) node.appendChild(child);
		});
		return node;
	}

	function notice(message, type) {
		var box = el("div", { class: "pp-notice pp-notice-" + (type || "success"), text: message });
		root.prepend(box);
		setTimeout(function () { box.remove(); }, 4000);
	}

	function fmtMoney(value) {
		if (value === null || value === undefined || value === "") return "—";
		return parseFloat(value).toFixed(2).replace(".", ",") + " €";
	}

	var CONDITIONS = { new: "Neu", good: "Gut", used: "Gebraucht", defect: "Defekt" };
	var STATUS_LABELS = { reserved: "Reserviert", active: "Verliehen", returned: "Zurück", cancelled: "Storniert" };

	/* ---------- Seite: Kategorien ---------- */

	function renderCategories() {
		root.innerHTML = "";
		var list = el("div");

		function load() {
			api("/categories").then(function (cats) {
				list.innerHTML = "";
				var table = el("table", { class: "widefat striped pp-table" });
				table.appendChild(el("thead", {
					html: "<tr><th>Name</th><th>Icon</th><th>Prefix</th><th>Sortierung</th><th></th></tr>"
				}));
				var tbody = el("tbody");
				cats.forEach(function (cat) {
					var del = el("button", {
						class: "button button-small", text: "Löschen",
						onclick: function () {
							if (!confirm('Kategorie "' + cat.name + '" löschen? Artikel bleiben erhalten.')) return;
							api("/categories/" + cat.id, { method: "DELETE" }).then(load).catch(function (e) { notice(e.message, "error"); });
						}
					});
					var row = el("tr", null, [
						el("td", { text: cat.name }),
						el("td", { text: cat.icon || "" }),
						el("td", null, [el("code", { text: cat.prefix || "—" })]),
						el("td", { text: cat.sort_order }),
						el("td", null, [del])
					]);
					tbody.appendChild(row);
				});
				if (!cats.length) {
					tbody.appendChild(el("tr", { html: '<td colspan="5">Noch keine Kategorien.</td>' }));
				}
				table.appendChild(tbody);
				list.appendChild(table);
			}).catch(function (e) { notice(e.message, "error"); });
		}

		var name = el("input", { type: "text", placeholder: "Name (z. B. Licht)" });
		var icon = el("input", { type: "text", placeholder: "Icon (Emoji)", class: "pp-input-small" });
		var prefix = el("input", { type: "text", placeholder: "Prefix (z. B. LIC)", class: "pp-input-small" });
		var form = el("form", {
			class: "pp-form",
			onsubmit: function (e) {
				e.preventDefault();
				if (!name.value.trim()) return;
				api("/categories", {
					method: "POST",
					body: JSON.stringify({ name: name.value.trim(), icon: icon.value.trim(), prefix: prefix.value.trim() })
				}).then(function () {
					name.value = icon.value = prefix.value = "";
					notice("Kategorie angelegt.");
					load();
				}).catch(function (e2) { notice(e2.message, "error"); });
			}
		}, [name, icon, prefix, el("button", { class: "button button-primary", text: "Anlegen" })]);

		root.appendChild(form);
		root.appendChild(list);
		load();
	}

	/* ---------- Seite: Inventar ---------- */

	function renderInventory() {
		root.innerHTML = "";
		var categories = [];
		var list = el("div");

		var search = el("input", { type: "search", placeholder: "Suchen (Name, Nummer, Hersteller, Tags) …", class: "pp-search" });
		var catFilter = el("select");

		function load() {
			var params = [];
			if (search.value.trim()) params.push("search=" + encodeURIComponent(search.value.trim()));
			if (catFilter.value) params.push("category_id=" + catFilter.value);
			api("/items" + (params.length ? "?" + params.join("&") : "")).then(function (items) {
				list.innerHTML = "";
				var table = el("table", { class: "widefat striped pp-table" });
				table.appendChild(el("thead", {
					html: "<tr><th>Nummer</th><th>Name</th><th>Kategorie</th><th>Menge</th><th>Zustand</th><th>Tagessatz</th><th>Lagerort</th><th></th></tr>"
				}));
				var tbody = el("tbody");
				items.forEach(function (item) {
					var del = ppConfig.canEdit.inventory ? el("button", {
						class: "button button-small", text: "Löschen",
						onclick: function () {
							if (!confirm('Artikel "' + item.name + '" löschen?')) return;
							api("/items/" + item.id, { method: "DELETE" }).then(load).catch(function (e) { notice(e.message, "error"); });
						}
					}) : null;
					tbody.appendChild(el("tr", null, [
						el("td", null, [el("code", { text: item.inventory_number })]),
						el("td", { text: item.name }),
						el("td", { text: (item.category_icon ? item.category_icon + " " : "") + (item.category_name || "—") }),
						el("td", { text: item.quantity }),
						el("td", { text: CONDITIONS[item.condition] || item.condition }),
						el("td", { text: fmtMoney(item.cost_per_day) }),
						el("td", { text: item.location || "—" }),
						el("td", null, [del])
					]));
				});
				if (!items.length) {
					tbody.appendChild(el("tr", { html: '<td colspan="8">Keine Artikel gefunden.</td>' }));
				}
				table.appendChild(tbody);
				list.appendChild(table);
			}).catch(function (e) { notice(e.message, "error"); });
		}

		api("/categories").then(function (cats) {
			categories = cats;
			catFilter.innerHTML = "";
			catFilter.appendChild(el("option", { value: "", text: "Alle Kategorien" }));
			cats.forEach(function (cat) {
				catFilter.appendChild(el("option", { value: cat.id, text: cat.name }));
			});
			fillFormCategories();
		});

		search.addEventListener("input", debounce(load, 300));
		catFilter.addEventListener("change", load);

		// Anlege-Formular
		var fName = el("input", { type: "text", placeholder: "Name *", required: "required" });
		var fCat = el("select");
		var fQty = el("input", { type: "number", value: "1", min: "1", class: "pp-input-small" });
		var fCondition = el("select", null, Object.keys(CONDITIONS).map(function (key) {
			return el("option", { value: key, text: CONDITIONS[key] });
		}));
		fCondition.value = "good";
		var fRate = el("input", { type: "number", step: "0.01", placeholder: "Tagessatz €", class: "pp-input-small" });
		var fLocation = el("input", { type: "text", placeholder: "Lagerort" });

		function fillFormCategories() {
			fCat.innerHTML = "";
			fCat.appendChild(el("option", { value: "", text: "— Kategorie —" }));
			categories.forEach(function (cat) {
				fCat.appendChild(el("option", { value: cat.id, text: cat.name }));
			});
		}

		var form = el("form", {
			class: "pp-form",
			onsubmit: function (e) {
				e.preventDefault();
				api("/items", {
					method: "POST",
					body: JSON.stringify({
						name: fName.value.trim(),
						category_id: fCat.value ? parseInt(fCat.value, 10) : 0,
						quantity: parseInt(fQty.value, 10) || 1,
						condition: fCondition.value,
						cost_per_day: fRate.value,
						location: fLocation.value.trim()
					})
				}).then(function (item) {
					notice("Artikel " + item.inventory_number + " angelegt.");
					fName.value = fLocation.value = fRate.value = "";
					fQty.value = "1";
					load();
				}).catch(function (e2) { notice(e2.message, "error"); });
			}
		}, [fName, fCat, fQty, fCondition, fRate, fLocation, el("button", { class: "button button-primary", text: "Artikel anlegen" })]);

		if (ppConfig.canEdit.inventory) root.appendChild(form);
		root.appendChild(el("div", { class: "pp-toolbar" }, [search, catFilter]));
		root.appendChild(list);
		load();
	}

	/* ---------- Seite: Verleih ---------- */

	function renderRentals() {
		root.innerHTML = "";
		var items = [];
		var lines = []; // { item_id, quantity }
		var list = el("div");

		function load() {
			api("/rentals").then(function (rentals) {
				list.innerHTML = "";
				var table = el("table", { class: "widefat striped pp-table" });
				table.appendChild(el("thead", {
					html: "<tr><th>Nummer</th><th>Leiher</th><th>Von</th><th>Bis</th><th>Positionen</th><th>Gebühr</th><th>Status</th><th></th></tr>"
				}));
				var tbody = el("tbody");
				rentals.forEach(function (rental) {
					var actions = el("td");
					if (ppConfig.canEdit.rentals) {
						var transitions = { reserved: ["active", "returned", "cancelled"], active: ["returned", "cancelled"] }[rental.status] || [];
						transitions.forEach(function (next) {
							actions.appendChild(el("button", {
								class: "button button-small pp-action",
								text: { active: "Ausgeben", returned: "Rücknahme", cancelled: "Stornieren" }[next],
								onclick: function () {
									api("/rentals/" + rental.id + "/status", { method: "POST", body: JSON.stringify({ status: next }) })
										.then(load).catch(function (e) { notice(e.message, "error"); });
								}
							}));
						});
					}
					tbody.appendChild(el("tr", null, [
						el("td", null, [el("code", { text: rental.rental_number })]),
						el("td", { text: rental.borrower_name }),
						el("td", { text: rental.date_from }),
						el("td", { text: rental.date_to }),
						el("td", { text: rental.item_count }),
						el("td", { text: fmtMoney(rental.rental_fee) }),
						el("td", null, [el("span", { class: "pp-status pp-status-" + rental.status, text: STATUS_LABELS[rental.status] || rental.status })]),
						actions
					]));
				});
				if (!rentals.length) {
					tbody.appendChild(el("tr", { html: '<td colspan="8">Noch keine Verleihe.</td>' }));
				}
				table.appendChild(tbody);
				list.appendChild(table);
			}).catch(function (e) { notice(e.message, "error"); });
		}

		// Neuer Verleih
		var fBorrower = el("input", { type: "text", placeholder: "Name des Leihers *" });
		var fEmail = el("input", { type: "email", placeholder: "E-Mail" });
		var fFrom = el("input", { type: "date" });
		var fTo = el("input", { type: "date" });
		var fFee = el("input", { type: "number", step: "0.01", placeholder: "Gebühr €", class: "pp-input-small" });
		var fDeposit = el("input", { type: "number", step: "0.01", placeholder: "Kaution €", class: "pp-input-small" });

		var fItem = el("select");
		var fItemQty = el("input", { type: "number", value: "1", min: "1", class: "pp-input-small" });
		var availInfo = el("span", { class: "pp-avail" });
		var linesView = el("ul", { class: "pp-lines" });

		function refreshLines() {
			linesView.innerHTML = "";
			lines.forEach(function (line, index) {
				var item = items.find(function (it) { return it.id == line.item_id; });
				linesView.appendChild(el("li", null, [
					el("span", { text: (item ? item.inventory_number + " " + item.name : "#" + line.item_id) + " × " + line.quantity + " " }),
					el("button", {
						class: "button-link pp-remove", text: "entfernen",
						onclick: function (e) { e.preventDefault(); lines.splice(index, 1); refreshLines(); }
					})
				]));
			});
		}

		function checkAvailability() {
			availInfo.textContent = "";
			if (!fItem.value || !fFrom.value || !fTo.value) return;
			api("/items/" + fItem.value + "/availability?from=" + fFrom.value + "&to=" + fTo.value)
				.then(function (result) {
					availInfo.textContent = result.available + "× verfügbar";
					availInfo.className = "pp-avail " + (result.available > 0 ? "pp-avail-ok" : "pp-avail-none");
				})
				.catch(function () { availInfo.textContent = ""; });
		}

		[fItem, fFrom, fTo].forEach(function (field) { field.addEventListener("change", checkAvailability); });

		var addLine = el("button", {
			class: "button", text: "Position hinzufügen",
			onclick: function (e) {
				e.preventDefault();
				if (!fItem.value) return;
				lines.push({ item_id: parseInt(fItem.value, 10), quantity: parseInt(fItemQty.value, 10) || 1 });
				refreshLines();
			}
		});

		var submit = el("button", { class: "button button-primary", text: "Verleih anlegen" });
		var form = el("form", {
			class: "pp-form pp-form-stacked",
			onsubmit: function (e) {
				e.preventDefault();
				api("/rentals", {
					method: "POST",
					body: JSON.stringify({
						borrower_name: fBorrower.value.trim(),
						borrower_email: fEmail.value.trim(),
						date_from: fFrom.value,
						date_to: fTo.value,
						rental_fee: fFee.value,
						deposit_amount: fDeposit.value,
						items: lines
					})
				}).then(function (rental) {
					notice("Verleih " + rental.rental_number + " angelegt.");
					lines = [];
					refreshLines();
					fBorrower.value = fEmail.value = fFee.value = fDeposit.value = "";
					load();
				}).catch(function (e2) { notice(e2.message, "error"); });
			}
		}, [
			el("div", { class: "pp-row" }, [fBorrower, fEmail, fFrom, fTo, fFee, fDeposit]),
			el("div", { class: "pp-row" }, [fItem, fItemQty, addLine, availInfo]),
			linesView,
			submit
		]);

		api("/items").then(function (result) {
			items = result;
			fItem.innerHTML = "";
			fItem.appendChild(el("option", { value: "", text: "— Artikel wählen —" }));
			items.forEach(function (item) {
				fItem.appendChild(el("option", { value: item.id, text: item.inventory_number + " — " + item.name }));
			});
		});

		if (ppConfig.canEdit.rentals) root.appendChild(form);
		root.appendChild(list);
		load();
	}

	function debounce(fn, wait) {
		var timer;
		return function () {
			clearTimeout(timer);
			timer = setTimeout(fn, wait);
		};
	}

	/* ---------- Routing ---------- */

	if (page === "categories") renderCategories();
	else if (page === "rentals") renderRentals();
	else renderInventory();
})();
