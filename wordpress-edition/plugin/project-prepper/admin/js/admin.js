/**
 * Project Prepper — Admin-UI (Vanilla JS, kein Build-Step).
 * Look & Feel der Live-App (Next.js/Supabase-Version) nachgebaut:
 * KPI-Karten, Kategorie-Pills, Detail-Modal, Badges, Toasts.
 */
(function () {
	"use strict";

	var __ = wp.i18n.__;
	var _x = wp.i18n._x;
	var sprintf = wp.i18n.sprintf;

	var root = document.getElementById("pp-admin");
	if (!root || typeof ppConfig === "undefined") return;

	var page = root.dataset.page;

	/* ================= Helpers ================= */

	function api(path, options) {
		options = options || {};
		options.headers = Object.assign(
			{ "Content-Type": "application/json", "X-WP-Nonce": ppConfig.nonce },
			options.headers || {}
		);
		return fetch(ppConfig.restUrl + path, options).then(handleResponse);
	}

	function apiUpload(path, file) {
		var data = new FormData();
		data.append("file", file);
		return fetch(ppConfig.restUrl + path, {
			method: "POST",
			headers: { "X-WP-Nonce": ppConfig.nonce },
			body: data
		}).then(handleResponse);
	}

	function handleResponse(res) {
		return res.json().then(function (body) {
			if (!res.ok) throw new Error(body && body.message ? body.message : __("Error", "project-prepper") + " " + res.status);
			return body;
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

	var toastBox = null;
	function toast(message, type) {
		if (!toastBox) {
			toastBox = el("div", { class: "pp-toasts" });
			document.body.appendChild(toastBox);
		}
		var item = el("div", { class: "pp-toast" + (type === "error" ? " pp-toast-error" : ""), text: message });
		toastBox.appendChild(item);
		setTimeout(function () { item.remove(); }, 4500);
	}

	function openModal(title, bodyNode, footerNode) {
		var backdrop = el("div", { class: "pp-modal-backdrop" });
		var modal = el("div", { class: "pp-modal" }, [
			el("div", { class: "pp-modal-header" }, [
				el("h2", { text: title }),
				el("button", { class: "pp-modal-close", text: "✕", onclick: close })
			]),
			el("div", { class: "pp-modal-body" }, [bodyNode]),
			footerNode || null
		]);
		backdrop.appendChild(modal);
		backdrop.addEventListener("click", function (e) { if (e.target === backdrop) close(); });
		document.body.appendChild(backdrop);
		function close() { backdrop.remove(); }
		return close;
	}

	function debounce(fn, wait) {
		var timer;
		return function () {
			clearTimeout(timer);
			timer = setTimeout(fn, wait);
		};
	}

	function money(value) {
		if (value === null || value === undefined || value === "") return "—";
		return Number(value).toLocaleString("de-DE", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " €";
	}

	function dateDe(iso) {
		if (!iso) return "—";
		var parts = String(iso).slice(0, 10).split("-");
		return parts[2] + "." + parts[1] + "." + parts[0];
	}

	// Zeitspanne als " · HH:MM–HH:MM" (führendes Trennzeichen, da hinter dem Datum). Leer = "".
	function timeRange(start, end) {
		var s = start ? String(start).slice(0, 5) : "";
		var e = end ? String(end).slice(0, 5) : "";
		if (!s && !e) return "";
		if (s && e) return " · " + s + "–" + e;
		return " · " + (s || e);
	}

	var CONDITIONS = { new: __("New", "project-prepper"), good: __("Good", "project-prepper"), fair: __("Used", "project-prepper"), poor: __("Poor", "project-prepper"), broken: __("Broken", "project-prepper"), retired: __("Retired", "project-prepper") };
	var STATUS_LABELS = { reserved: __("Reserved", "project-prepper"), active: __("On loan", "project-prepper"), returned: __("Returned", "project-prepper"), cancelled: __("Cancelled", "project-prepper") };
	var STATUS_ACTIONS = { active: __("Hand out", "project-prepper"), returned: __("Take back", "project-prepper"), cancelled: _x("Cancel", "rental status action", "project-prepper") };
	var TRANSITIONS = { reserved: ["active", "returned", "cancelled"], active: ["returned", "cancelled"] };

	function badge(value, labels) {
		return el("span", { class: "pp-badge pp-badge-" + value, text: labels[value] || value });
	}

	function conditionSelect(value) {
		var select = el("select", null, Object.keys(CONDITIONS).map(function (key) {
			return el("option", { value: key, text: CONDITIONS[key] });
		}));
		select.value = value || "good";
		return select;
	}

	function field(labelText, input, cls) {
		if (cls) input.classList.add(cls);
		return el("div", { class: "pp-field" }, [el("label", { text: labelText }), input]);
	}

	/* ================= Seite: Inventar ================= */

	function renderInventory() {
		root.innerHTML = "";
		root.appendChild(el("p", { class: "pp-muted", text: __("Read-only oversight of all inventory. Members add and edit their own items in the member portal — here you review and, for moderation, remove items.", "project-prepper") }));

		var search = el("input", { type: "search", placeholder: __("Search items \u2026", "project-prepper"), style: "flex:1; min-width:160px" });
		var catSel = el("select", { style: "min-width:160px" }, [el("option", { value: "", text: __("All categories", "project-prepper") })]);
		var countOut = el("span", { class: "pp-muted" });
		var tableBox = el("div");
		root.appendChild(el("div", { class: "pp-row", style: "gap:10px; margin-bottom:12px; flex-wrap:wrap; align-items:center" }, [search, catSel, countOut]));
		root.appendChild(tableBox);

		function load() {
			var params = [];
			if (search.value) params.push("search=" + encodeURIComponent(search.value));
			if (catSel.value) params.push("category_id=" + encodeURIComponent(catSel.value));
			api("/items" + (params.length ? "?" + params.join("&") : "")).then(draw).catch(function (e) { toast(e.message, "error"); });
		}

		function draw(items) {
			countOut.textContent = items.length + " " + (items.length === 1 ? __("item", "project-prepper") : __("items", "project-prepper"));
			tableBox.innerHTML = "";
			if (!items.length) { tableBox.appendChild(el("p", { class: "pp-muted", text: __("No items.", "project-prepper") })); return; }

			var heads = [__("Number", "project-prepper"), __("Name", "project-prepper"), __("Category", "project-prepper"), __("Condition", "project-prepper"), __("Qty", "project-prepper"), __("Owner", "project-prepper"), __("Value", "project-prepper"), ""];
			var thead = el("thead", null, [el("tr", null, heads.map(function (h) { return el("th", { text: h }); }))]);
			var body = el("tbody", null, items.map(function (it) {
				var del = el("button", {
					class: "pp-btn pp-btn-sm", text: __("Delete", "project-prepper"),
					onclick: function () {
						if (!confirm(__("Delete this item? This cannot be undone.", "project-prepper"))) return;
						api("/items/" + it.id, { method: "DELETE" }).then(function () { toast(__("Item deleted.", "project-prepper")); load(); }).catch(function (e) { toast(e.message, "error"); });
					}
				});
				return el("tr", null, [
					el("td", null, [el("code", { text: it.inventory_number || "\u2014" })]),
					el("td", null, [
						document.createTextNode((it.name || "") + " "),
						it.out_now ? badge("out", { out: __("out", "project-prepper") }) : null
					]),
					el("td", { text: it.category_name || "\u2014" }),
					el("td", { text: CONDITIONS[it.condition] || it.condition || "\u2014" }),
					el("td", { text: String(it.quantity) }),
					el("td", { text: it.owner_name || "\u2014" }),
					el("td", { text: money(it.current_value) }),
					el("td", { style: "text-align:right" }, [del])
				]);
			}));
			tableBox.appendChild(el("table", { class: "wp-list-table widefat fixed striped" }, [thead, body]));
		}

		search.addEventListener("input", debounce(load, 300));
		catSel.addEventListener("change", load);
		api("/categories").then(function (cats) {
			cats.forEach(function (c) { catSel.appendChild(el("option", { value: c.id, text: (c.icon ? c.icon + " " : "") + c.name })); });
		}).catch(function () {});
		load();
	}

	function renderRentals() {
		root.innerHTML = "";
		var items = [];
		var lines = [];
		var listBox = el("div");

		function load() {
			api("/rentals").then(function (rentals) {
				listBox.innerHTML = "";
				var table = el("table", { class: "pp-table" });
				table.appendChild(el("thead", {
					html: "<tr><th>" + __("Number", "project-prepper") + "</th><th>" + __("Borrower", "project-prepper") + "</th><th>" + __("From", "project-prepper") + "</th><th>" + __("To", "project-prepper") + "</th><th>" + __("Line items", "project-prepper") + "</th><th>" + __("Fee", "project-prepper") + "</th><th>" + __("Status", "project-prepper") + "</th><th></th></tr>"
				}));
				var tbody = el("tbody");
				rentals.forEach(function (rental) {
					var actions = el("td");
					(TRANSITIONS[rental.status] || []).forEach(function (next) {
						if (!ppConfig.canEdit.rentals) return;
						actions.appendChild(el("button", {
							class: "pp-btn pp-btn-sm", text: STATUS_ACTIONS[next], style: "margin-right:4px",
							onclick: function (e) {
								e.stopPropagation();
								api("/rentals/" + rental.id + "/status", { method: "POST", body: JSON.stringify({ status: next }) })
									.then(load).catch(function (err) { toast(err.message, "error"); });
							}
						}));
					});
					tbody.appendChild(el("tr", { class: "pp-clickable", onclick: function () { openRentalModal(rental.id); } }, [
						el("td", null, [el("code", { text: rental.rental_number })]),
						el("td", { text: rental.borrower_name }),
						el("td", { text: dateDe(rental.date_from) }),
						el("td", { text: dateDe(rental.date_to) }),
						el("td", { text: rental.item_count }),
						el("td", { text: money(rental.rental_fee) }),
						el("td", null, [badge(rental.status, STATUS_LABELS)]),
						actions
					]));
				});
				if (!rentals.length) tbody.appendChild(el("tr", { html: '<td colspan="8" class="pp-muted">' + __("No rentals yet.", "project-prepper") + "</td>" }));
				table.appendChild(tbody);
				listBox.appendChild(el("div", { class: "pp-table-wrap" }, [table]));
			}).catch(function (e) { toast(e.message, "error"); });
		}

		function openRentalModal(rentalId) {
			api("/rentals/" + rentalId).then(function (rental) {
				// Bearbeitbar nur in Status reserved/active (Diff-Logik §9.4).
				var editable = ppConfig.canEdit.rentals && (rental.status === "reserved" || rental.status === "active");

				function input(type, value, step) {
					var attrs = { type: type, value: value === null || value === undefined ? "" : value };
					if (step) attrs.step = step;
					if (!editable) attrs.disabled = "disabled";
					return el("input", attrs);
				}

				var f = {
					name: input("text", rental.borrower_name),
					email: input("email", rental.borrower_email),
					phone: input("text", rental.borrower_phone),
					address: input("text", rental.borrower_address),
					from: input("date", rental.date_from),
					to: input("date", rental.date_to),
					fee: input("number", rental.rental_fee, "0.01"),
					deposit: input("number", rental.deposit_amount, "0.01"),
					vat: input("number", rental.vat_rate === null || rental.vat_rate === undefined ? "" : rental.vat_rate, "0.1"),
					notes: el("textarea", { rows: "2" })
				};
				f.notes.value = rental.notes || "";
				if (!editable) f.notes.disabled = true;

				var info = el("div", { class: "pp-modal-grid" }, [
					field(__("Borrower *", "project-prepper"), f.name), field(__("Email", "project-prepper"), f.email), field(__("Phone", "project-prepper"), f.phone), field(__("Address", "project-prepper"), f.address),
					field(__("From", "project-prepper"), f.from), field(__("To", "project-prepper"), f.to),
					field(__("Fee €", "project-prepper"), f.fee), field(__("Deposit €", "project-prepper"), f.deposit), field(__("VAT %", "project-prepper"), f.vat), field(__("Notes", "project-prepper"), f.notes)
				]);

				// Positionen — als editierbare Zeilen (Menge + Tagessatz), per id für den Server-Diff.
				var editLines = rental.items.map(function (line) {
					return {
						id: line.id,
						item_id: line.item_id,
						unit_id: line.unit_id,
						quantity: parseInt(line.quantity, 10) || 1,
						daily_rate: line.daily_rate,
						code: line.inventory_number || "#" + line.item_id,
						name: line.item_name || ""
					};
				});
				var lineList = el("ul", { class: "pp-lines" });
				function renderModalLines() {
					lineList.innerHTML = "";
					editLines.forEach(function (line, index) {
						if (!editable) {
							lineList.appendChild(el("li", null, [
								el("code", { text: line.code }),
								el("span", { text: line.name + " × " + line.quantity }),
								el("span", { class: "pp-muted", text: line.daily_rate ? money(line.daily_rate) + "/" + __("day", "project-prepper") : "" })
							]));
							return;
						}
						var qty = el("input", { type: "number", min: "1", value: line.quantity, class: "pp-input-sm", title: __("Quantity", "project-prepper") });
						qty.addEventListener("change", function () { line.quantity = parseInt(qty.value, 10) || 1; });
						var rate = el("input", { type: "number", step: "0.01", value: line.daily_rate === null || line.daily_rate === undefined ? "" : line.daily_rate, class: "pp-input-sm", placeholder: __("Daily rate €", "project-prepper"), title: __("Daily rate €", "project-prepper") });
						rate.addEventListener("change", function () { line.daily_rate = rate.value; });
						lineList.appendChild(el("li", null, [
							el("code", { text: line.code }),
							el("span", { text: line.name }),
							qty, rate,
							el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () { editLines.splice(index, 1); renderModalLines(); }
							})
						]));
					});
					if (!editLines.length) lineList.appendChild(el("li", { class: "pp-muted", text: __("No line items.", "project-prepper") }));
				}
				renderModalLines();

				var linesSection = el("div", { class: "pp-modal-section" }, [el("h3", { text: __("Line items", "project-prepper") }), lineList]);
				if (editable) {
					var addItem = el("select", { class: "pp-input-lg" });
					addItem.appendChild(el("option", { value: "", text: __("— select item —", "project-prepper") }));
					items.forEach(function (item) {
						addItem.appendChild(el("option", { value: item.id, text: item.inventory_number + " — " + item.name }));
					});
					var addQty = el("input", { type: "number", value: "1", min: "1", class: "pp-input-sm", title: __("Quantity", "project-prepper") });
					var addRate = el("input", { type: "number", step: "0.01", placeholder: __("Daily rate €", "project-prepper"), class: "pp-input-sm", title: __("Daily rate €", "project-prepper") });
					linesSection.appendChild(el("div", { class: "pp-row" }, [
						addItem, addQty, addRate,
						el("button", {
							class: "pp-btn pp-btn-sm", text: __("+ Line item", "project-prepper"), type: "button",
							onclick: function () {
								if (!addItem.value) return;
								var item = items.find(function (it) { return it.id == addItem.value; });
								editLines.push({
									item_id: parseInt(addItem.value, 10),
									quantity: parseInt(addQty.value, 10) || 1,
									daily_rate: addRate.value,
									code: item ? item.inventory_number : "#" + addItem.value,
									name: item ? item.name : ""
								});
								addItem.value = ""; addQty.value = "1"; addRate.value = "";
								renderModalLines();
							}
						})
					]));
				}

				var b = rental.billing || {};
				/* translators: %d: number of days */
				var daysText = sprintf(__("%d days", "project-prepper"), b.days || 0);
				var billing = el("dl", { class: "pp-billing" }, [
					el("dt", { text: __("Period", "project-prepper") }), el("dd", { text: daysText }),
					el("dt", { text: __("Net", "project-prepper") }), el("dd", { text: money(b.net) }),
					el("dt", { text: __("VAT", "project-prepper") + " (" + (b.vat_rate || 19) + " %)" }), el("dd", { text: money(b.vat) }),
					el("dt", { class: "pp-billing-total", text: __("Gross", "project-prepper") }), el("dd", { class: "pp-billing-total", text: money(b.gross) }),
					el("dt", { text: __("Deposit (pass-through)", "project-prepper") }), el("dd", { text: money(b.deposit) })
				]);

				var body = el("div", null, [
					el("div", { class: "pp-row" }, [badge(rental.status, STATUS_LABELS)]),
					info,
					linesSection,
					el("div", { class: "pp-modal-section" }, [el("h3", { text: __("Billing", "project-prepper") }), billing])
				]);

				var close;
				var footerButtons = el("div", { class: "pp-right" }, [el("button", { class: "pp-btn", text: __("Close", "project-prepper"), onclick: function () { close(); } })]);
				if (editable) {
					footerButtons.insertBefore(el("button", {
						class: "pp-btn pp-btn-primary", text: __("Save", "project-prepper"),
						onclick: function () {
							api("/rentals/" + rentalId, {
								method: "PUT",
								body: JSON.stringify({
									borrower_name: f.name.value.trim(),
									borrower_email: f.email.value.trim(),
									borrower_phone: f.phone.value.trim(),
									borrower_address: f.address.value.trim(),
									date_from: f.from.value,
									date_to: f.to.value,
									rental_fee: f.fee.value,
									deposit_amount: f.deposit.value,
									vat_rate: f.vat.value,
									notes: f.notes.value,
									items: editLines.map(function (line) {
										return { id: line.id, item_id: line.item_id, unit_id: line.unit_id, quantity: line.quantity, daily_rate: line.daily_rate };
									})
								})
							}).then(function () {
								toast(__("Saved.", "project-prepper")); close(); load();
							}).catch(function (e) { toast(e.message, "error"); });
						}
					}), footerButtons.firstChild);
				}
				(TRANSITIONS[rental.status] || []).forEach(function (next) {
					if (!ppConfig.canEdit.rentals) return;
					footerButtons.insertBefore(el("button", {
						class: "pp-btn", text: STATUS_ACTIONS[next],
						onclick: function () {
							api("/rentals/" + rentalId + "/status", { method: "POST", body: JSON.stringify({ status: next }) })
								.then(function () { close(); load(); }).catch(function (e) { toast(e.message, "error"); });
						}
					}), footerButtons.firstChild);
				});
				var footer = el("div", { class: "pp-modal-footer" }, [
					ppConfig.canEdit.rentals ? el("button", {
						class: "pp-btn pp-btn-danger", text: __("Delete", "project-prepper"),
						onclick: function () {
							/* translators: %s: rental number */
							if (!confirm(sprintf(__("Delete rental %s?", "project-prepper"), rental.rental_number))) return;
							api("/rentals/" + rentalId, { method: "DELETE" }).then(function () { close(); load(); });
						}
					}) : el("span"),
					footerButtons
				]);
				close = openModal(rental.rental_number + " — " + rental.borrower_name, body, footer);
			}).catch(function (e) { toast(e.message, "error"); });
		}

		/* ----- Neuer Verleih ----- */

		if (ppConfig.canEdit.rentals) {
			var fBorrower = el("input", { type: "text", placeholder: __("Name *", "project-prepper"), class: "pp-input-md" });
			var fEmail = el("input", { type: "email", placeholder: __("Email", "project-prepper"), class: "pp-input-md" });
			var fPhone = el("input", { type: "text", placeholder: __("Phone", "project-prepper"), class: "pp-input-sm" });
			var fAddress = el("input", { type: "text", placeholder: __("Address", "project-prepper"), class: "pp-input-lg" });
			var fFrom = el("input", { type: "date" });
			var fTo = el("input", { type: "date" });
			var fFee = el("input", { type: "number", step: "0.01", placeholder: __("Fee €", "project-prepper"), class: "pp-input-sm" });
			var fDeposit = el("input", { type: "number", step: "0.01", placeholder: __("Deposit €", "project-prepper"), class: "pp-input-sm" });
			var fVat = el("input", { type: "number", step: "0.1", value: "19", class: "pp-input-sm", title: __("VAT %", "project-prepper") });

			var fItem = el("select", { class: "pp-input-lg" });
			var fItemQty = el("input", { type: "number", value: "1", min: "1", class: "pp-input-sm" });
			var fItemRate = el("input", { type: "number", step: "0.01", placeholder: __("Daily rate €", "project-prepper"), class: "pp-input-sm" });
			var availInfo = el("span");
			var linesView = el("ul", { class: "pp-lines" });

			var refreshLines = function () {
				linesView.innerHTML = "";
				lines.forEach(function (line, index) {
					var item = items.find(function (it) { return it.id == line.item_id; });
					linesView.appendChild(el("li", null, [
						el("code", { text: item ? item.inventory_number : "#" + line.item_id }),
						el("span", { text: (item ? item.name : "") + " × " + line.quantity }),
						el("span", { class: "pp-muted", text: line.daily_rate ? money(line.daily_rate) + "/" + __("day", "project-prepper") : "" }),
						el("button", {
							class: "pp-link pp-link-danger", text: __("remove", "project-prepper"),
							onclick: function (e) { e.preventDefault(); lines.splice(index, 1); refreshLines(); }
						})
					]));
				});
			};

			var checkAvailability = function () {
				availInfo.textContent = "";
				availInfo.className = "";
				if (!fItem.value || !fFrom.value || !fTo.value) return;
				api("/items/" + fItem.value + "/availability?from=" + fFrom.value + "&to=" + fTo.value).then(function (result) {
					availInfo.textContent = result.available + "× " + __("available", "project-prepper");
					availInfo.className = result.available > 0 ? "pp-avail-ok" : "pp-avail-none";
				}).catch(function () {});
			};
			[fItem, fFrom, fTo].forEach(function (f) { f.addEventListener("change", checkAvailability); });

			// Tagessatz aus dem Artikel-Stammdatensatz vorschlagen (wie equipment-picker der App).
			fItem.addEventListener("change", function () {
				var item = items.find(function (it) { return it.id == fItem.value; });
				fItemRate.value = item && item.cost_per_day !== null && item.cost_per_day !== undefined ? item.cost_per_day : "";
			});

			var form = el("form", {
				onsubmit: function (e) {
					e.preventDefault();
					api("/rentals", {
						method: "POST",
						body: JSON.stringify({
							borrower_name: fBorrower.value.trim(),
							borrower_email: fEmail.value.trim(),
							borrower_phone: fPhone.value.trim(),
							borrower_address: fAddress.value.trim(),
							date_from: fFrom.value,
							date_to: fTo.value,
							rental_fee: fFee.value,
							deposit_amount: fDeposit.value,
							vat_rate: fVat.value,
							items: lines
						})
					}).then(function (rental) {
						/* translators: %s: rental number */
						toast(sprintf(__("Rental %s created.", "project-prepper"), rental.rental_number));
						lines = []; refreshLines();
						fBorrower.value = fEmail.value = fPhone.value = fAddress.value = fFee.value = fDeposit.value = "";
						load();
					}).catch(function (e2) { toast(e2.message, "error"); });
				}
			}, [
				el("div", { class: "pp-row" }, [
					field(__("Borrower *", "project-prepper"), fBorrower), field(__("Email", "project-prepper"), fEmail), field(__("Phone", "project-prepper"), fPhone), field(__("Address", "project-prepper"), fAddress)
				]),
				el("div", { class: "pp-row" }, [
					field(__("From", "project-prepper"), fFrom), field(__("To", "project-prepper"), fTo), field(__("Fee €", "project-prepper"), fFee), field(__("Deposit €", "project-prepper"), fDeposit), field(__("VAT %", "project-prepper"), fVat)
				]),
				el("div", { class: "pp-row" }, [
					field(__("Item", "project-prepper"), fItem), field(__("Quantity", "project-prepper"), fItemQty), field(__("Daily rate €", "project-prepper"), fItemRate),
					el("button", {
						class: "pp-btn", text: __("+ Line item", "project-prepper"), type: "button",
						onclick: function () {
							if (!fItem.value) return;
							lines.push({ item_id: parseInt(fItem.value, 10), quantity: parseInt(fItemQty.value, 10) || 1, daily_rate: fItemRate.value });
							fItemRate.value = "";
							refreshLines();
						}
					}),
					availInfo
				]),
				linesView,
				el("button", { class: "pp-btn pp-btn-primary", text: __("Create rental", "project-prepper") })
			]);

			api("/items").then(function (result) {
				items = result;
				fItem.innerHTML = "";
				fItem.appendChild(el("option", { value: "", text: __("— select item —", "project-prepper") }));
				items.forEach(function (item) {
					fItem.appendChild(el("option", { value: item.id, text: item.inventory_number + " — " + item.name }));
				});
			});

			root.appendChild(el("div", { class: "pp-card" }, [el("h2", { text: __("New rental", "project-prepper") }), form]));
		} else {
			api("/items").then(function (result) { items = result; });
		}

		root.appendChild(listBox);
		load();
	}

	/* ================= Seite: Projekte ================= */

	function renderProjects() {
		root.innerHTML = "";
		root.appendChild(el("p", { class: "pp-muted", text: __("Read-only moderation. Projects belong to the groups that own them — members create and edit projects in the member portal. Here you oversee them and can remove a project. The status flow is fixed: Draft → Planned → Confirmed → Running → Done (or Cancelled).", "project-prepper") }));

		var PROJECT_STATUS = { draft: __("Draft", "project-prepper"), planned: __("Planned", "project-prepper"), confirmed: __("Confirmed", "project-prepper"), running: __("Running", "project-prepper"), done: __("Done", "project-prepper"), cancelled: __("Cancelled", "project-prepper") };
		var PROJECT_BADGE = { draft: "draft", planned: "reserved", confirmed: "offer", running: "active", done: "returned", cancelled: "cancelled" };
		var groupNames = {};
		var activeStatus = "";
		var pillBox = el("div", { class: "pp-pills" });
		var listBox = el("div");

		function projectBadge(status) {
			return el("span", { class: "pp-badge pp-badge-" + (PROJECT_BADGE[status] || status), text: PROJECT_STATUS[status] || status });
		}
		function rangeText(from, to) {
			if (!from && !to) return "—";
			return dateDe(from) + " – " + dateDe(to);
		}
		function renderPills() {
			pillBox.innerHTML = "";
			pillBox.appendChild(el("button", {
				class: "pp-pill" + (activeStatus === "" ? " is-active" : ""), text: __("All", "project-prepper"),
				onclick: function () { activeStatus = ""; renderPills(); load(); }
			}));
			Object.keys(PROJECT_STATUS).forEach(function (key) {
				pillBox.appendChild(el("button", {
					class: "pp-pill" + (activeStatus === key ? " is-active" : ""), text: PROJECT_STATUS[key],
					onclick: function () { activeStatus = key; renderPills(); load(); }
				}));
			});
		}

		function load() {
			api("/projects" + (activeStatus ? "?status=" + activeStatus : "")).then(function (projects) {
				listBox.innerHTML = "";
				var table = el("table", { class: "pp-table" });
				table.appendChild(el("thead", {
					html: "<tr><th>" + __("Number", "project-prepper") + "</th><th>" + __("Name", "project-prepper") + "</th><th>" + __("Period", "project-prepper") + "</th><th>" + __("Owner", "project-prepper") + "</th><th>" + __("Bookings", "project-prepper") + "</th><th>" + __("Status", "project-prepper") + "</th><th></th></tr>"
				}));
				var tbody = el("tbody");
				projects.forEach(function (project) {
					var owner = project.owner_group_id ? (groupNames[project.owner_group_id] || ("#" + project.owner_group_id)) : __("Site level", "project-prepper");
					tbody.appendChild(el("tr", null, [
						el("td", null, [el("code", { text: project.project_number })]),
						el("td", { text: project.name }),
						el("td", { text: rangeText(project.date_start, project.date_end) }),
						el("td", { text: owner }),
						el("td", { text: project.item_count }),
						el("td", null, [projectBadge(project.status)]),
						el("td", null, [el("button", {
							class: "pp-link pp-link-danger", text: __("delete", "project-prepper"),
							onclick: function () {
								/* translators: %s: project name */
								if (!confirm(sprintf(__('Delete project "%s"? This cannot be undone.', "project-prepper"), project.name))) return;
								api("/projects/" + project.id, { method: "DELETE" }).then(function () { toast(__("Project deleted.", "project-prepper")); load(); }).catch(function (e) { toast(e.message, "error"); });
							}
						})])
					]));
				});
				if (!projects.length) tbody.appendChild(el("tr", { html: '<td colspan="7" class="pp-muted">' + __("No projects yet.", "project-prepper") + "</td>" }));
				table.appendChild(tbody);
				listBox.appendChild(el("div", { class: "pp-table-wrap" }, [table]));
			}).catch(function (e) { toast(e.message, "error"); });
		}

		root.appendChild(pillBox);
		root.appendChild(listBox);
		renderPills();
		// Gruppennamen für die Eigentümer-Spalte laden, dann die Projekte.
		api("/groups").then(function (result) {
			(result || []).forEach(function (g) { groupNames[g.id] = g.name; });
		}).catch(function () {}).then(function () { load(); });
	}

	/* ================= Seite: Kategorien ================= */

	function renderCategories() {
		root.innerHTML = "";
		root.appendChild(el("p", { class: "pp-muted", text: __("These are the template categories. Members get them suggested in their portal and can adopt them or create their own. Categories members create themselves are not shown here.", "project-prepper") }));
		var listBox = el("div");

		// Zusammenführen (App-Pendant: Migration 097): Items → Ziel, Quelle wird gelöscht.
		function openMergeModal(cat, cats) {
			var targets = cats.filter(function (c) { return c.id !== cat.id; });
			if (!targets.length) { toast(__("No other category available as target.", "project-prepper"), "error"); return; }

			var targetSelect = el("select", null, targets.map(function (c) {
				return el("option", { value: c.id, text: (c.icon ? c.icon + " " : "") + c.name });
			}));
			var info = el("p", { class: "pp-muted", text: __("Counting items …", "project-prepper") });
			api("/items?category_id=" + cat.id).then(function (items) {
				/* translators: 1: number of items, 2: category name */
				info.textContent = sprintf(__('%1$d items will be moved to the target category, then "%2$s" will be deleted.', "project-prepper"), items.length, cat.name);
			}).catch(function () {
				/* translators: %s: category name */
				info.textContent = sprintf(__('All items will be moved to the target category, then "%s" will be deleted.', "project-prepper"), cat.name);
			});

			var body = el("div", null, [
				/* translators: %s: category name */
				field(sprintf(__('Target category for "%s"', "project-prepper"), cat.name), targetSelect),
				info
			]);
			var close;
			var footer = el("div", { class: "pp-modal-footer" }, [
				el("span"),
				el("div", { class: "pp-right" }, [
					el("button", { class: "pp-btn", text: __("Cancel", "project-prepper"), onclick: function () { close(); } }),
					el("button", {
						class: "pp-btn pp-btn-primary", text: __("Merge", "project-prepper"),
						onclick: function () {
							api("/categories/" + cat.id + "/merge", {
								method: "POST",
								body: JSON.stringify({ target_id: parseInt(targetSelect.value, 10) })
							}).then(function (result) {
								/* translators: %d: number of moved items */
								toast(sprintf(__("%d items moved, category deleted.", "project-prepper"), result.moved));
								close(); load();
							}).catch(function (e) { toast(e.message, "error"); });
						}
					})
				])
			]);
			close = openModal(__("Merge category", "project-prepper"), body, footer);
		}

		function load() {
			api("/categories").then(function (cats) {
				listBox.innerHTML = "";
				var table = el("table", { class: "pp-table" });
				table.appendChild(el("thead", { html: "<tr><th>" + __("Icon", "project-prepper") + "</th><th>" + __("Name", "project-prepper") + "</th><th>" + __("Prefix", "project-prepper") + "</th><th></th></tr>" }));
				var tbody = el("tbody");
				cats.forEach(function (cat) {
					var icon = el("input", { type: "text", value: cat.icon || "", class: "pp-input-sm" });
					var name = el("input", { type: "text", value: cat.name, class: "pp-input-md" });
					var prefix = el("input", { type: "text", value: cat.prefix || "", class: "pp-input-sm" });
					[icon, name, prefix].forEach(function (input) {
						input.addEventListener("change", function () {
							api("/categories/" + cat.id, {
								method: "PUT",
								body: JSON.stringify({ name: name.value.trim(), icon: icon.value.trim(), prefix: prefix.value.trim() })
							}).then(function () { toast(__("Saved.", "project-prepper")); }).catch(function (e) { toast(e.message, "error"); });
						});
					});
					tbody.appendChild(el("tr", null, [
						el("td", null, [icon]),
						el("td", null, [name]),
						el("td", null, [prefix]),
						el("td", null, [
							el("button", {
								class: "pp-link", text: __("Merge…", "project-prepper"), style: "margin-right:12px",
								onclick: function () { openMergeModal(cat, cats); }
							}),
							el("button", {
								class: "pp-link pp-link-danger", text: __("delete", "project-prepper"),
								onclick: function () {
									/* translators: %s: category name */
									if (!confirm(sprintf(__('Delete category "%s"? Items will be kept.', "project-prepper"), cat.name))) return;
									api("/categories/" + cat.id, { method: "DELETE" }).then(load).catch(function (e) { toast(e.message, "error"); });
								}
							})
						])
					]));
				});
				table.appendChild(tbody);
				listBox.appendChild(el("div", { class: "pp-table-wrap" }, [table]));
			}).catch(function (e) { toast(e.message, "error"); });
		}

		var nName = el("input", { type: "text", placeholder: __("Name *", "project-prepper"), class: "pp-input-md" });
		var nIcon = el("input", { type: "text", placeholder: __("Icon (emoji)", "project-prepper"), class: "pp-input-sm" });
		var nPrefix = el("input", { type: "text", placeholder: __("Prefix", "project-prepper"), class: "pp-input-sm" });
		root.appendChild(el("div", { class: "pp-card" }, [
			el("h2", { text: __("New category", "project-prepper") }),
			el("form", {
				class: "pp-row",
				onsubmit: function (e) {
					e.preventDefault();
					if (!nName.value.trim()) return;
					api("/categories", {
						method: "POST",
						body: JSON.stringify({ name: nName.value.trim(), icon: nIcon.value.trim(), prefix: nPrefix.value.trim() })
					}).then(function () {
						nName.value = nIcon.value = nPrefix.value = "";
						toast(__("Category created.", "project-prepper")); load();
					}).catch(function (e2) { toast(e2.message, "error"); });
				}
			}, [nName, nIcon, nPrefix, el("button", { class: "pp-btn pp-btn-primary", text: __("Create", "project-prepper") })])
		]));
		root.appendChild(listBox);
		load();
	}

	/* ================= Seite: Anfragen ================= */

	function renderInquiries() {
		root.innerHTML = "";
		root.appendChild(el("p", { class: "pp-muted", text: __("Inquiries from the public request form (without a member owner). Inquiries that belong to a member or group are managed in the member portal and only counted here.", "project-prepper") }));
		var aggBox = el("p", { class: "pp-muted", style: "margin-top:-4px" });
		root.appendChild(aggBox);
		api("/inquiries/member-aggregate").then(function (a) {
			if (a && a.total > 0) {
				/* translators: 1: number of inquiries, 2: number of member workspaces. */
				aggBox.textContent = sprintf(__("%1$d inquiries are managed by members across %2$d workspaces.", "project-prepper"), a.total, a.owners);
			}
		}).catch(function () {});
		// Pipeline wie die App (§11): new → contacted → offer → won | lost.
		// 'closed' bleibt als Legacy-Endstatus lesbar (Bestandsdaten vor v0.7.0).
		var INQUIRY_STATUS = { new: __("New", "project-prepper"), contacted: __("Contacted", "project-prepper"), offer: __("Offer", "project-prepper"), won: __("Won", "project-prepper"), lost: __("Lost", "project-prepper"), closed: __("Closed", "project-prepper") };
		var INQUIRY_ACTIONS = { new: ["contacted", "offer", "won", "lost"], contacted: ["offer", "won", "lost"], offer: ["won", "lost"], won: [], lost: [], closed: [] };
		var INQUIRY_BADGE = { new: "reserved", contacted: "active", offer: "offer", won: "returned", lost: "cancelled", closed: "returned" };
		var INQUIRY_END_STATES = ["won", "lost", "closed"];
		var listBox = el("div");

		function inquiryBadge(status) {
			return el("span", { class: "pp-badge pp-badge-" + (INQUIRY_BADGE[status] || status), text: INQUIRY_STATUS[status] || status });
		}

		// Aktions-Buttons (Konvertieren, Status, Löschen) — für Zeile UND Modal-Footer.
		function inquiryActions(inquiry, small, done) {
			var box = el("span");
			if (!ppConfig.canEdit.inquiries) return box;
			// Anfrage → Verleih (braucht beide Edit-Caps; ohne Zeitraum deaktiviert).
			if (ppConfig.canEdit.rentals && INQUIRY_END_STATES.indexOf(inquiry.status) === -1) {
				var convertBtn = el("button", {
					class: "pp-btn pp-btn-primary" + (small ? " pp-btn-sm" : ""), text: __("Convert to rental", "project-prepper"), style: "margin-right:4px",
					onclick: function (e) {
						e.stopPropagation();
						/* translators: %s: name of the person who sent the inquiry */
						if (!confirm(sprintf(__('Convert the inquiry from "%s" into a rental? The inquiry will be marked as won.', "project-prepper"), inquiry.name))) return;
						api("/inquiries/" + inquiry.id + "/convert", { method: "POST" }).then(function (rental) {
							/* translators: %s: rental number */
							toast(sprintf(__("Rental %s created.", "project-prepper"), rental.rental_number));
							done();
						}).catch(function (err) { toast(err.message, "error"); });
					}
				});
				if (!inquiry.date_from || !inquiry.date_to) {
					convertBtn.disabled = true;
					convertBtn.title = __("Date range missing — cannot convert", "project-prepper");
				}
				box.appendChild(convertBtn);
			}
			(INQUIRY_ACTIONS[inquiry.status] || []).forEach(function (next) {
				box.appendChild(el("button", {
					class: "pp-btn" + (small ? " pp-btn-sm" : ""), text: INQUIRY_STATUS[next], style: "margin-right:4px",
					onclick: function (e) {
						e.stopPropagation();
						api("/inquiries/" + inquiry.id + "/status", { method: "POST", body: JSON.stringify({ status: next }) })
							.then(done).catch(function (err) { toast(err.message, "error"); });
					}
				}));
			});
			box.appendChild(el("button", {
				class: "pp-link pp-link-danger", text: __("delete", "project-prepper"),
				onclick: function (e) {
					e.stopPropagation();
					/* translators: %s: name of the person who sent the inquiry */
					if (!confirm(sprintf(__('Delete the inquiry from "%s"?', "project-prepper"), inquiry.name))) return;
					api("/inquiries/" + inquiry.id, { method: "DELETE" }).then(done);
				}
			}));
			return box;
		}

		/* ----- Detail-Modal (Pendant zu inquiries/[id] der App) ----- */

		function openInquiryModal(inquiryId) {
			api("/inquiries/" + inquiryId).then(function (inquiry) {
				function dd(label, valueNode) {
					return el("div", { class: "pp-field" }, [
						el("label", { text: label }),
						typeof valueNode === "string" ? el("div", { text: valueNode || "—" }) : valueNode
					]);
				}

				var emailNode = inquiry.email
					? el("a", { class: "pp-link", href: "mailto:" + inquiry.email, text: inquiry.email })
					: el("div", { text: "—" });
				var range = inquiry.date_from ? dateDe(inquiry.date_from) + " – " + dateDe(inquiry.date_to) : "—";

				var body = el("div", null, [
					el("div", { class: "pp-row" }, [inquiryBadge(inquiry.status)]),
					el("div", { class: "pp-modal-grid" }, [
						dd(__("Name", "project-prepper"), inquiry.name),
						dd(__("Email", "project-prepper"), emailNode),
						dd(__("Phone", "project-prepper"), inquiry.phone),
						dd(__("Period", "project-prepper"), range),
						dd(__("Received on", "project-prepper"), dateDe(inquiry.created_at))
					])
				]);

				// Vollständige Nachricht
				var messageNode = el("div", { class: "pp-inquiry-message", text: inquiry.message || "—" });
				body.appendChild(el("div", { class: "pp-modal-section" }, [
					el("h3", { text: __("Message", "project-prepper") }),
					messageNode
				]));

				// Equipment-Liste
				var itemList = el("ul", { class: "pp-lines" });
				(inquiry.items || []).forEach(function (line) {
					itemList.appendChild(el("li", null, [
						/* translators: %d: item ID */
						el("span", { text: (line.name || sprintf(__("Item #%d", "project-prepper"), line.item_id)) + " × " + (line.quantity || 1) })
					]));
				});
				if (!(inquiry.items || []).length) itemList.appendChild(el("li", { class: "pp-muted", text: __("No equipment requested.", "project-prepper") }));
				body.appendChild(el("div", { class: "pp-modal-section" }, [
					el("h3", { text: __("Equipment", "project-prepper") }),
					itemList
				]));

				var close;
				var footer = el("div", { class: "pp-modal-footer" }, [
					inquiryActions(inquiry, false, function () { close(); load(); }),
					el("div", { class: "pp-right" }, [
						el("button", { class: "pp-btn", text: __("Close", "project-prepper"), onclick: function () { close(); } })
					])
				]);
				/* translators: %s: name of the person who sent the inquiry */
				close = openModal(sprintf(__("Inquiry from %s", "project-prepper"), inquiry.name), body, footer);
			}).catch(function (e) { toast(e.message, "error"); });
		}

		function load() {
			api("/inquiries").then(function (inquiries) {
				listBox.innerHTML = "";
				var table = el("table", { class: "pp-table" });
				table.appendChild(el("thead", {
					html: "<tr><th>" + __("Date", "project-prepper") + "</th><th>" + __("Name", "project-prepper") + "</th><th>" + __("Contact", "project-prepper") + "</th><th>" + __("Period", "project-prepper") + "</th><th>" + __("Equipment", "project-prepper") + "</th><th>" + __("Message", "project-prepper") + "</th><th>" + __("Status", "project-prepper") + "</th><th></th></tr>"
				}));
				var tbody = el("tbody");
				inquiries.forEach(function (inquiry) {
					var contact = [inquiry.email, inquiry.phone].filter(Boolean).join(" · ") || "—";
					var range = inquiry.date_from ? dateDe(inquiry.date_from) + " – " + dateDe(inquiry.date_to) : "—";
					var equipment = (inquiry.items || []).map(function (line) { return line.name; }).join(", ") || "—";
					tbody.appendChild(el("tr", { class: "pp-clickable", onclick: function () { openInquiryModal(inquiry.id); } }, [
						el("td", { text: dateDe(inquiry.created_at) }),
						el("td", { text: inquiry.name }),
						el("td", { text: contact }),
						el("td", { text: range }),
						el("td", { text: equipment }),
						el("td", { text: inquiry.message ? (inquiry.message.length > 80 ? inquiry.message.slice(0, 80) + "…" : inquiry.message) : "—" }),
						el("td", null, [inquiryBadge(inquiry.status)]),
						el("td", null, [inquiryActions(inquiry, true, load)])
					]));
				});
				if (!inquiries.length) tbody.appendChild(el("tr", { html: '<td colspan="8" class="pp-muted">' + __("No inquiries yet. Add the form to any page with the [pp_request_form] shortcode.", "project-prepper") + "</td>" }));
				table.appendChild(tbody);
				listBox.appendChild(el("div", { class: "pp-table-wrap" }, [table]));
			}).catch(function (e) { toast(e.message, "error"); });
		}

		root.appendChild(listBox);
		load();
	}

	/* ================= Seite: Einstellungen ================= */

	function renderSettings() {
		root.innerHTML = "";
		root.classList.add("pp-settings");

		api("/settings").then(function (settings) {
			// E-Mail (Templates leben jetzt auf der eigenen Seite „E-Mail-Templates").
			var emailToggle = el("input", { type: "checkbox" });
			emailToggle.checked = settings.email_notifications;

			var saveBtn = el("button", {
				class: "pp-btn pp-btn-primary", text: __("Save", "project-prepper"),
				onclick: function () {
					api("/settings", {
						method: "PUT",
						body: JSON.stringify({
							email_notifications: emailToggle.checked,
							delete_data_on_uninstall: deleteToggle.checked,
							public_show_rates: ratesToggle.checked
						})
					}).then(function () { toast(__("Settings saved.", "project-prepper")); }).catch(function (e) { toast(e.message, "error"); });
				}
			});

			var mailTestResult = el("span", { class: "pp-muted", style: "margin-left:8px" });
			var mailTestBtn = el("button", {
				class: "pp-btn pp-btn-sm", text: __("Send test email", "project-prepper"),
				onclick: function () {
					mailTestBtn.disabled = true;
					mailTestResult.textContent = __("Sending …", "project-prepper");
					api("/settings/test-email", { method: "POST" }).then(function (r) {
						mailTestBtn.disabled = false;
						if (r.sent) {
							/* translators: %s: recipient email address. */
							mailTestResult.textContent = sprintf(__("Sent to %s — check your inbox.", "project-prepper"), r.to);
						} else {
							mailTestResult.textContent = __("WordPress could not send the email. Check your server mail / SMTP setup.", "project-prepper");
						}
					}).catch(function (e) { mailTestBtn.disabled = false; mailTestResult.textContent = e.message; });
				}
			});
			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Email notifications", "project-prepper") }),
				el("label", { class: "pp-toggle" }, [emailToggle, el("span", { text: __("Send notification emails (rentals, invitations, borrow requests, login codes)", "project-prepper") })]),
				el("div", { style: "margin-top:8px" }, [el("a", { class: "pp-link", href: "admin.php?page=pp-email-templates", text: __("Edit email templates →", "project-prepper") })]),
				el("div", { class: "pp-muted", style: "margin-top:8px", text: __("Deliverability check: send a test email to your own address.", "project-prepper") }),
				el("div", { class: "pp-row", style: "margin-top:4px" }, [mailTestBtn, mailTestResult])
			]));

			// iCal
			var icalUrl = el("code", { class: "pp-ical-url", text: settings.ical_url });
			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Calendar feed (iCal)", "project-prepper") }),
				el("div", { class: "pp-muted", text: __("Read-only feed of all reserved/active rentals — subscribe in Apple/Google/Outlook.", "project-prepper") }),
				icalUrl,
				el("div", { class: "pp-row", style: "margin-top:8px" }, [
					el("button", {
						class: "pp-btn pp-btn-sm", text: __("Copy URL", "project-prepper"),
						onclick: function () {
							navigator.clipboard.writeText(settings.ical_url).then(function () { toast(__("Copied.", "project-prepper")); });
						}
					}),
					el("button", {
						class: "pp-btn pp-btn-sm", text: __("Regenerate token", "project-prepper"),
						onclick: function () {
							if (!confirm(__("Regenerate token? Existing calendar subscriptions will lose access.", "project-prepper"))) return;
							api("/settings/regenerate-ical-token", { method: "POST" }).then(function (updated) {
								settings.ical_url = updated.ical_url;
								icalUrl.textContent = updated.ical_url;
								toast(__("Token regenerated.", "project-prepper"));
							});
						}
					})
				])
			]));

			// Öffentliches Frontend
			var ratesToggle = el("input", { type: "checkbox" });
			ratesToggle.checked = settings.public_show_rates;
			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Public frontend", "project-prepper") }),
				el("label", { class: "pp-toggle" }, [ratesToggle, el("span", { text: __("Show daily rates publicly (item detail page /equipment-item/…)", "project-prepper") })]),
				el("div", { class: "pp-muted", style: "margin-top:6px", text: __("Inventory cards link to a public detail page per item. Purchase price and serial number are never shown there.", "project-prepper") })
			]));

			// Daten
			var deleteToggle = el("input", { type: "checkbox" });
			deleteToggle.checked = settings.delete_data_on_uninstall;
			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Data", "project-prepper") }),
				el("label", { class: "pp-toggle" }, [deleteToggle, el("span", { text: __("Delete all plugin data on uninstall", "project-prepper") })]),
				el("div", { class: "pp-muted", style: "margin-top:6px", text: __("GDPR: export & anonymization of borrower data via Tools → Personal Data (search by email address).", "project-prepper") })
			]));

			root.appendChild(el("div", { class: "pp-row" }, [saveBtn]));
		}).catch(function (e) { toast(e.message, "error"); });
	}

	/* ================= Seite: Gruppen ================= */

	function renderGroups() {
		root.innerHTML = "";
		var GROUP_ROLES = { founder: __("Founder", "project-prepper"), member: __("Member", "project-prepper") };
		var siteUsers = [];
		var listBox = el("div");

		function load() {
			api("/groups").then(function (groups) {
				listBox.innerHTML = "";
				var table = el("table", { class: "pp-table" });
				table.appendChild(el("thead", {
					html: "<tr><th>" + __("Name", "project-prepper") + "</th><th>" + __("Description", "project-prepper") + "</th><th>" + __("Members", "project-prepper") + "</th></tr>"
				}));
				var tbody = el("tbody");
				groups.forEach(function (group) {
					tbody.appendChild(el("tr", { class: "pp-clickable", onclick: function () { openGroupModal(group.id); } }, [
						el("td", { text: group.name }),
						el("td", { text: group.description || "—" }),
						el("td", { text: group.member_count })
					]));
				});
				if (!groups.length) tbody.appendChild(el("tr", { html: '<td colspan="3" class="pp-muted">' + __("No groups yet.", "project-prepper") + "</td>" }));
				table.appendChild(tbody);
				listBox.appendChild(el("div", { class: "pp-table-wrap" }, [table]));
			}).catch(function (e) { toast(e.message, "error"); });
		}

		function openGroupModal(groupId) {
			api("/groups/" + groupId).then(function (group) {
				var nameInput = el("input", { type: "text", value: group.name });
				var descInput = el("textarea", { rows: "2" });
				descInput.value = group.description || "";

				var info = el("div", { class: "pp-modal-grid" }, [
					field(__("Group name", "project-prepper"), nameInput),
					field(__("Description", "project-prepper"), descInput)
				]);

				// Mitglieder-Sektion.
				var membersSection = el("div", { class: "pp-modal-section" });
				function renderMembers() {
					membersSection.innerHTML = "";
					membersSection.appendChild(el("h3", { text: __("Members", "project-prepper") }));
					var list = el("ul", { class: "pp-lines" });
					(group.members || []).forEach(function (m) {
						list.appendChild(el("li", null, [
							el("span", { text: m.display_name }),
							el("span", { class: "pp-muted", text: m.user_email || "" }),
							el("span", { class: "pp-badge pp-badge-" + (m.member_role === "founder" ? "offer" : "draft"), text: GROUP_ROLES[m.member_role] || m.member_role }),
							el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/groups/" + groupId + "/members/" + m.user_id, { method: "DELETE" })
										.then(function (updated) { group = updated; renderMembers(); load(); })
										.catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					});
					if (!(group.members || []).length) list.appendChild(el("li", { class: "pp-muted", text: __("No members.", "project-prepper") }));
					membersSection.appendChild(list);

					// Mitglied hinzufügen: WP-User-Select + Rolle.
					var memberIds = (group.members || []).map(function (m) { return m.user_id; });
					var userSelect = el("select", { class: "pp-input-lg" });
					userSelect.appendChild(el("option", { value: "", text: __("— select user —", "project-prepper") }));
					siteUsers.forEach(function (u) {
						if (memberIds.indexOf(u.id) !== -1) return;
						userSelect.appendChild(el("option", { value: u.id, text: u.display_name + (u.email ? " (" + u.email + ")" : "") }));
					});
					var roleSelect = el("select", null, Object.keys(GROUP_ROLES).map(function (key) {
						return el("option", { value: key, text: GROUP_ROLES[key] });
					}));
					membersSection.appendChild(el("div", { class: "pp-row" }, [
						userSelect, roleSelect,
						el("button", {
							class: "pp-btn pp-btn-sm", text: __("Add member", "project-prepper"), type: "button",
							onclick: function () {
								if (!userSelect.value) return;
								api("/groups/" + groupId + "/members", {
									method: "POST",
									body: JSON.stringify({ user_id: parseInt(userSelect.value, 10), role: roleSelect.value })
								}).then(function (updated) { group = updated; renderMembers(); load(); })
									.catch(function (e) { toast(e.message, "error"); });
							}
						})
					]));
				}
				renderMembers();

				var body = el("div", null, [info, membersSection]);

				var close;
				var footerButtons = el("div", { class: "pp-right" }, [
					el("button", {
						class: "pp-btn pp-btn-primary", text: __("Save", "project-prepper"),
						onclick: function () {
							api("/groups/" + groupId, {
								method: "PUT",
								body: JSON.stringify({ name: nameInput.value.trim(), description: descInput.value })
							}).then(function () { toast(__("Saved.", "project-prepper")); close(); load(); })
								.catch(function (e) { toast(e.message, "error"); });
						}
					}),
					el("button", { class: "pp-btn", text: __("Close", "project-prepper"), onclick: function () { close(); } })
				]);
				var footer = el("div", { class: "pp-modal-footer" }, [
					el("button", {
						class: "pp-btn pp-btn-danger", text: __("Delete group", "project-prepper"),
						onclick: function () {
							/* translators: %s: group name */
							if (!confirm(sprintf(__('Delete group "%s"? Projects of this group return to site level.', "project-prepper"), group.name))) return;
							api("/groups/" + groupId, { method: "DELETE" }).then(function () { close(); load(); }).catch(function (e) { toast(e.message, "error"); });
						}
					}),
					footerButtons
				]);
				close = openModal(group.name, body, footer);
			}).catch(function (e) { toast(e.message, "error"); });
		}

		// Neue Gruppe.
		var gName = el("input", { type: "text", placeholder: __("Group name", "project-prepper"), class: "pp-input-lg" });
		var gDesc = el("input", { type: "text", placeholder: __("Description", "project-prepper"), class: "pp-input-md" });
		root.appendChild(el("div", { class: "pp-card" }, [
			el("h2", { text: __("New group", "project-prepper") }),
			el("form", {
				onsubmit: function (e) {
					e.preventDefault();
					if (!gName.value.trim()) return;
					api("/groups", { method: "POST", body: JSON.stringify({ name: gName.value.trim(), description: gDesc.value.trim() }) })
						.then(function () { gName.value = ""; gDesc.value = ""; load(); })
						.catch(function (e2) { toast(e2.message, "error"); });
				}
			}, [
				el("div", { class: "pp-row" }, [
					field(__("Group name", "project-prepper"), gName),
					field(__("Description", "project-prepper"), gDesc),
					el("button", { class: "pp-btn pp-btn-primary", text: __("Create group", "project-prepper") })
				])
			])
		]));

		api("/groups/site-users").then(function (users) { siteUsers = users; }).catch(function () {});

		root.appendChild(listBox);
		load();
	}

	/* ================= Seite: Dashboard ================= */

	function renderDashboard() {
		root.innerHTML = "";

		// "So funktioniert Project Prepper" — dismissibler Banner wie in der
		// Web-App (Status nur clientseitig via localStorage, kein Server-State).
		if (localStorage.getItem("pp_dash_hiw_dismissed") !== "1") {
			var hiwSteps = [
				[__("Build your inventory", "project-prepper"), __("Items with number ranges, conditions and daily rates.", "project-prepper")],
				[__("Plan projects", "project-prepper"), __("Bookings, schedule, checklists, tasks and a cost breakdown.", "project-prepper")],
				[__("Work as a group", "project-prepper"), __("Share a project with a team: members, decisions and polls.", "project-prepper")],
				[__("Settle the profit", "project-prepper"), __("Distribute the profit and record a signed agreement.", "project-prepper")]
			];
			var hiw = el("div", { class: "pp-hiw" }, [
				el("div", { class: "pp-hiw-head" }, [
					el("h2", { text: __("How Project Prepper works", "project-prepper") }),
					el("button", {
						class: "pp-link pp-hiw-dismiss", text: __("Got it, hide", "project-prepper"),
						onclick: function () { localStorage.setItem("pp_dash_hiw_dismissed", "1"); hiw.remove(); }
					})
				]),
				el("div", { class: "pp-hiw-steps" }, hiwSteps.map(function (s, i) {
					return el("div", { class: "pp-hiw-step" }, [
						el("div", { class: "pp-hiw-num", text: String(i + 1) }),
						el("div", null, [
							el("div", { class: "pp-hiw-step-title", text: s[0] }),
							el("div", { class: "pp-hiw-step-desc", text: s[1] })
						])
					]);
				}))
			]);
			root.appendChild(hiw);
		}

		// Menschenlesbare Labels für die Aktivitäts-Keys (Fallback = roher Key).
		var ACTION_LABELS = {
			item_created: __("Item created", "project-prepper"),
			item_updated: __("Item updated", "project-prepper"),
			item_deleted: __("Item deleted", "project-prepper"),
			category_created: __("Category created", "project-prepper"),
			category_updated: __("Category updated", "project-prepper"),
			category_deleted: __("Category deleted", "project-prepper"),
			category_merged: __("Categories merged", "project-prepper"),
			rental_created: __("Rental created", "project-prepper"),
			rental_updated: __("Rental updated", "project-prepper"),
			rental_status_changed: __("Rental status changed", "project-prepper"),
			rental_deleted: __("Rental deleted", "project-prepper"),
			project_created: __("Project created", "project-prepper"),
			project_updated: __("Project updated", "project-prepper"),
			project_deleted: __("Project deleted", "project-prepper"),
			inquiry_created: __("Inquiry created", "project-prepper"),
			inquiry_status_changed: __("Inquiry status changed", "project-prepper"),
			inquiry_converted: __("Inquiry converted", "project-prepper"),
			inquiry_deleted: __("Inquiry deleted", "project-prepper"),
			group_created: __("Group created", "project-prepper"),
			group_updated: __("Group updated", "project-prepper"),
			group_deleted: __("Group deleted", "project-prepper"),
			project_member_added: __("Participant added", "project-prepper"),
			decision_created: __("Decision created", "project-prepper"),
			profit_share_added: __("Profit share added", "project-prepper"),
			agreement_created: __("Agreement created", "project-prepper"),
			agreement_opened: __("Agreement opened for signing", "project-prepper"),
			agreement_signed: __("Agreement signed", "project-prepper"),
			agreement_declined: __("Agreement declined", "project-prepper"),
			agreement_revised: __("Agreement revised", "project-prepper"),
			agreement_terminated: __("Agreement terminated", "project-prepper")
		};
		var ENTITY_LABELS = {
			item: __("Item", "project-prepper"),
			category: __("Category", "project-prepper"),
			rental: __("Rental", "project-prepper"),
			project: __("Project", "project-prepper"),
			inquiry: __("Inquiry", "project-prepper"),
			group: __("Group", "project-prepper"),
			decision: __("Decision", "project-prepper"),
			agreement: __("Agreement", "project-prepper")
		};

		function kpiCard(value, label) {
			return el("div", { class: "pp-kpi" }, [
				el("div", { class: "pp-kpi-value", text: String(value) }),
				el("div", { class: "pp-kpi-label", text: label })
			]);
		}

		api("/dashboard").then(function (d) {
			var inv = d.inventory || {};
			var rentals = d.rentals || {};
			var projects = d.projects || {};
			var inquiries = d.inquiries || {};

			// Reihe 1: Inventar-KPIs (wie auf der Inventarseite).
			var kpiInv = el("div", { class: "pp-kpis" }, [
				kpiCard(inv.item_count || 0, __("Items", "project-prepper")),
				kpiCard(inv.total_pieces || 0, __("Total pieces", "project-prepper")),
				kpiCard(inv.out_today || 0, __("Out today", "project-prepper")),
				kpiCard(money(inv.daily_value), __("Daily inventory value", "project-prepper"))
			]);
			root.appendChild(kpiInv);

			// Reihe 2: Verleih / Anfragen / Projekte.
			var runningPlanned = (projects.running || 0) + (projects.confirmed || 0) + (projects.planned || 0);
			var kpiOps = el("div", { class: "pp-kpis" }, [
				kpiCard(rentals.active || 0, __("Active rentals", "project-prepper")),
				kpiCard(rentals.reserved || 0, __("Reserved rentals", "project-prepper")),
				kpiCard(inquiries.new || 0, __("Open inquiries", "project-prepper")),
				kpiCard(runningPlanned, __("Running / planned projects", "project-prepper"))
			]);
			root.appendChild(kpiOps);

			// Abschnitt "Anstehend" (nächste 14 Tage): Verleihe + Projekte.
			var upcomingSection = el("div", { class: "pp-modal-section" }, [
				el("h3", { text: __("Upcoming (next 14 days)", "project-prepper") })
			]);
			var upRentals = rentals.upcoming || [];
			var upProjects = projects.upcoming || [];
			if (!upRentals.length && !upProjects.length) {
				upcomingSection.appendChild(el("p", { class: "pp-muted", text: __("Nothing scheduled.", "project-prepper") }));
			} else {
				var upList = el("ul", { class: "pp-lines pp-lines-block" });
				upProjects.forEach(function (p) {
					var range = dateDe(p.date_start) + (p.date_end ? " – " + dateDe(p.date_end) : "");
					upList.appendChild(el("li", null, [
						el("div", null, [
							el("a", {
								href: "admin.php?page=pp-manage&tab=projects",
								class: "pp-link",
								text: p.name || __("(untitled)", "project-prepper")
							}),
							el("span", { class: "pp-muted", text: " · " + __("Project", "project-prepper") + " · " + range })
						])
					]));
				});
				upRentals.forEach(function (r) {
					var range = dateDe(r.date_from) + (r.date_to ? " – " + dateDe(r.date_to) : "");
					upList.appendChild(el("li", null, [
						el("div", null, [
							el("span", { text: r.borrower_name || __("—", "project-prepper") }),
							el("span", { class: "pp-muted", text: " · " + __("Rental", "project-prepper") + " · " + range })
						])
					]));
				});
				upcomingSection.appendChild(upList);
			}
			root.appendChild(upcomingSection);

			// Abschnitt "Letzte Aktivität".
			var activitySection = el("div", { class: "pp-modal-section" }, [
				el("h3", { text: __("Recent activity", "project-prepper") })
			]);
			var activity = d.recent_activity || [];
			if (!activity.length) {
				activitySection.appendChild(el("p", { class: "pp-muted", text: __("No activity yet.", "project-prepper") }));
			} else {
				var actList = el("ul", { class: "pp-lines" });
				activity.forEach(function (a) {
					var actionLabel = ACTION_LABELS[a.action] || a.action;
					var entityLabel = ENTITY_LABELS[a.entity_type] || a.entity_type;
					var line = a.actor + " · " + actionLabel + " · " + entityLabel + " · " + dateDe(a.created_at);
					actList.appendChild(el("li", null, [el("span", { text: line })]));
				});
				activitySection.appendChild(actList);
			}
			root.appendChild(activitySection);
		}).catch(function (err) {
			toast(err.message, "error");
		});
	}

	/* ================= Calendar (mechanic + read-only moderation) ================= */

	// Steuerzentrale-Linse (docs/06 §4): Mechanik = iCal-Feed; das Editieren von
	// Terminen passiert im Mitglieder-Portal. Das frühere Voll-Monatsraster ist
	// einer schlanken read-only Moderationsliste der nächsten Einträge gewichen.
	function renderCalendar() {
		root.innerHTML = "";
		root.appendChild(el("p", { class: "pp-muted", text: __("Calendar mechanic and oversight. The iCal feed below publishes all scheduled entries; the schedule itself (rentals, projects) is maintained where those records live.", "project-prepper") }));

		// Lokales Y-m-d (kein UTC-Versatz wie bei toISOString).
		function ymd(d) {
			var m = String(d.getMonth() + 1);
			var day = String(d.getDate());
			return d.getFullYear() + "-" + (m.length < 2 ? "0" + m : m) + "-" + (day.length < 2 ? "0" + day : day);
		}

		// --- Mechanik: iCal-Feed (URL + Kopieren + Token erneuern) ---
		if (ppConfig.canEdit && ppConfig.canEdit.settings) {
			var icalUrl = el("code", { class: "pp-ical-url", text: __("Loading …", "project-prepper") });
			var feedCard = el("div", { class: "pp-card" }, [
				el("h2", { text: __("Calendar feed (iCal)", "project-prepper") }),
				el("p", { class: "pp-muted", text: __("Subscribe to this read-only feed in any calendar app. Regenerating the token revokes existing subscriptions.", "project-prepper") }),
				el("div", { class: "pp-cal-feed" }, [icalUrl]),
				el("div", { class: "pp-row", style: "margin-top:8px" }, [
					el("button", {
						class: "pp-btn pp-btn-sm", text: __("Copy URL", "project-prepper"),
						onclick: function () {
							if (icalUrl.dataset.url) navigator.clipboard.writeText(icalUrl.dataset.url).then(function () { toast(__("Copied.", "project-prepper")); });
						}
					}),
					el("button", {
						class: "pp-btn pp-btn-sm", text: __("Regenerate token", "project-prepper"),
						onclick: function () {
							if (!confirm(__("Regenerate token? Existing calendar subscriptions will lose access.", "project-prepper"))) return;
							api("/settings/regenerate-ical-token", { method: "POST" }).then(function (updated) {
								icalUrl.dataset.url = updated.ical_url;
								icalUrl.textContent = updated.ical_url;
								toast(__("Token regenerated.", "project-prepper"));
							}).catch(function (e) { toast(e.message, "error"); });
						}
					})
				])
			]);
			root.appendChild(feedCard);
			api("/settings").then(function (s) {
				if (s && s.ical_url) { icalUrl.dataset.url = s.ical_url; icalUrl.textContent = s.ical_url; }
			}).catch(function () { icalUrl.textContent = __("available in the settings.", "project-prepper"); });
		}

		// --- Read-only Moderation: kommende Einträge (heute … +90 Tage) ---
		var TYPE = { rental: __("Rental", "project-prepper"), project: __("Project", "project-prepper"), schedule: __("Schedule", "project-prepper") };
		var today = new Date();
		var until = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 90);
		var from = ymd(today);
		var to = ymd(until);

		var mount = el("div");
		root.appendChild(mount);

		api("/calendar-events?from=" + from + "&to=" + to).then(function (events) {
			var rows = (events || []).map(function (ev) {
				var start = ev.type === "schedule" ? ev.date : ev.date_from;
				var end = ev.type === "schedule" ? ev.date : (ev.date_to || ev.date_from);
				var when = start === end ? start : start + " – " + end;
				var title = ev.title || __("(untitled)", "project-prepper");
				if (ev.type === "schedule" && ev.time_start) title = String(ev.time_start).slice(0, 5) + " " + title;
				return { sort: start, cells: [when, TYPE[ev.type] || ev.type, title] };
			}).sort(function (a, b) { return a.sort < b.sort ? -1 : a.sort > b.sort ? 1 : 0; });

			mount.appendChild(tableCard(
				__("Upcoming entries (next 90 days)", "project-prepper"),
				[__("Date", "project-prepper"), __("Type", "project-prepper"), __("Title", "project-prepper")],
				rows.map(function (r) { return r.cells; }),
				__("No scheduled entries in the next 90 days.", "project-prepper")
			));
		}).catch(function (err) {
			toast(err.message, "error");
		});
	}

	/* ================= Seite: Plattform ================= */

	// Kleine Tabellen-Karte (h2 + wp-list-table) — für die read-only Übersichten.
	function tableCard(title, headers, rows, emptyText) {
		var thead = el("thead", null, [el("tr", null, headers.map(function (h) { return el("th", { text: h }); }))]);
		var body;
		if (rows.length) {
			body = el("tbody", null, rows.map(function (cells) {
				return el("tr", null, cells.map(function (c) { return el("td", { text: c === null || c === undefined || c === "" ? "—" : String(c) }); }));
			}));
		} else {
			body = el("tbody", null, [el("tr", null, [el("td", { colspan: String(headers.length), text: emptyText })])]);
		}
		return el("div", { class: "pp-card" }, [
			el("h2", { text: title }),
			el("table", { class: "wp-list-table widefat fixed striped" }, [thead, body])
		]);
	}

	function renderPlatform() {
		root.innerHTML = "";
		var STATUS = {
			pending: __("Pending acceptance", "project-prepper"), voting: __("In voting", "project-prepper"),
			requested: __("Requested", "project-prepper"), approved: __("Approved", "project-prepper"),
			declined: __("Declined", "project-prepper"), cancelled: __("Cancelled", "project-prepper"),
			returned: __("Returned", "project-prepper")
		};
		var ACTIONS = {
			group_created: __("founded a collective", "project-prepper"),
			group_invited: __("invited someone", "project-prepper"),
			group_invitation_accepted: __("accepted an invitation", "project-prepper"),
			group_invitation_approved: __("was approved to join", "project-prepper"),
			group_invitation_rejected: __("was rejected", "project-prepper"),
			group_member_added: __("added a member", "project-prepper"),
			group_member_removed: __("removed a member", "project-prepper"),
			member_item_created: __("added an item", "project-prepper"),
			member_item_deleted: __("deleted an item", "project-prepper"),
			item_shared: __("shared an item", "project-prepper"),
			item_unshared: __("stopped sharing an item", "project-prepper"),
			borrow_requested: __("requested to borrow an item", "project-prepper"),
			borrow_approved: __("approved a borrow request", "project-prepper"),
			borrow_declined: __("declined a borrow request", "project-prepper"),
			borrow_returned: __("marked a loan returned", "project-prepper"),
			item_created: __("added an item", "project-prepper"),
			item_updated: __("updated an item", "project-prepper"),
			item_deleted: __("deleted an item", "project-prepper"),
			group_updated: __("updated a collective", "project-prepper"),
			group_deleted: __("deleted a collective", "project-prepper"),
			group_invitation_declined: __("declined an invitation", "project-prepper"),
			group_invitation_cancelled: __("cancelled an invitation", "project-prepper"),
			inventory_imported: __("imported inventory", "project-prepper"),
			inventory_exported: __("exported inventory", "project-prepper"),
			gdpr_erasure: __("ran a GDPR erasure", "project-prepper"),
			fed_borrow_received: __("received a network request", "project-prepper"),
			fed_borrow_decided: __("decided a network request", "project-prepper"),
			fed_borrow_returned: __("marked a network loan returned", "project-prepper"),
			fed_borrow_sent: __("sent a network request", "project-prepper")
		};

		root.appendChild(el("p", { class: "pp-muted", text: __("Where the member portal comes together: collectives, join voting, member inventory and borrow requests. Manage members under Groups.", "project-prepper") }));

		api("/platform").then(function (d) {
			var att = d.attention || {};
			var attCards = [
				[att.open_votings || 0, __("Open join votes", "project-prepper")],
				[att.open_requests || 0, __("Open borrow requests", "project-prepper")],
				[att.overdue || 0, __("Overdue loans", "project-prepper")],
				[att.fed_incoming || 0, __("Incoming network requests", "project-prepper")],
				[att.partners_unreachable || 0, __("Unreachable partners", "project-prepper")]
			];
			var totalAtt = attCards.reduce(function (s, c) { return s + c[0]; }, 0);
			root.appendChild(el("h2", { text: __("Needs attention", "project-prepper") }));
			if (!totalAtt) {
				root.appendChild(el("p", { class: "pp-muted", text: __("Nothing needs your attention right now.", "project-prepper") }));
			}
			root.appendChild(el("div", { class: "pp-kpis" }, attCards.map(function (c) {
				return el("div", { class: "pp-kpi" }, [
					el("div", { class: "pp-kpi-value", style: c[0] > 0 ? "color:#b45309" : "", text: String(c[0]) }),
					el("div", { class: "pp-kpi-label", text: c[1] })
				]);
			})));

			root.appendChild(el("h2", { text: __("At a glance", "project-prepper"), style: "margin-top:1.5rem" }));
			var kpis = [
				[__("Collectives", "project-prepper"), d.kpis.collectives],
				[__("Member inventory", "project-prepper"), d.kpis.member_items],
				[__("Open invitations", "project-prepper"), d.kpis.open_invites],
				[__("Active loans", "project-prepper"), d.kpis.active_loans],
				[__("Open borrow requests", "project-prepper"), d.kpis.open_requests]
			];
			root.appendChild(el("div", { class: "pp-kpis" }, kpis.map(function (k) {
				return el("div", { class: "pp-kpi" }, [
					el("div", { class: "pp-kpi-value", text: String(k[1]) }),
					el("div", { class: "pp-kpi-label", text: k[0] })
				]);
			})));

			root.appendChild(tableCard(
				__("Open join invitations", "project-prepper"),
				[__("Collective", "project-prepper"), __("Invited", "project-prepper"), __("Status", "project-prepper"), __("Approvals", "project-prepper")],
				d.invitations.map(function (i) { return [i.group, i.email, STATUS[i.status] || i.status, i.approvals]; }),
				__("No open invitations.", "project-prepper")
			));

			root.appendChild(tableCard(
				__("Recent borrow requests", "project-prepper"),
				[__("Item", "project-prepper"), __("Owner", "project-prepper"), __("Borrower", "project-prepper"), __("Period", "project-prepper"), __("Status", "project-prepper")],
				d.borrows.map(function (b) { return [b.item, b.owner, b.borrower, b.period, STATUS[b.status] || b.status]; }),
				__("No borrow requests yet.", "project-prepper")
			));

			if (d.overdue && d.overdue.length) {
				root.appendChild(tableCard(
					__("Overdue loans", "project-prepper"),
					[__("Item", "project-prepper"), __("Owner", "project-prepper"), __("Borrower", "project-prepper"), __("Due", "project-prepper")],
					d.overdue.map(function (o) { return [o.item, o.owner, o.borrower, o.due]; }),
					__("None.", "project-prepper")
				));
			}

			root.appendChild(tableCard(
				__("Recent activity", "project-prepper"),
				[__("When", "project-prepper"), __("Who", "project-prepper"), __("Action", "project-prepper")],
				d.activity.map(function (a) { return [a.when, a.who, ACTIONS[a.action] || String(a.action).replace(/_/g, " ")]; }),
				__("No activity recorded yet.", "project-prepper")
			));

			root.appendChild(tableCard(
				__("Collectives", "project-prepper"),
				[__("Name", "project-prepper"), __("Members", "project-prepper")],
				d.collectives.map(function (g) { return [g.name, g.members]; }),
				__("No collectives yet.", "project-prepper")
			));
		}).catch(function (e) { toast(e.message, "error"); });
	}

	/* ================= Seite: Sicherheit ================= */

	function renderSecurity() {
		root.innerHTML = "";
		root.appendChild(el("p", { class: "pp-muted", text: __("Frontend hardening for the member portal. Everything is OFF by default — nothing changes until you enable it here.", "project-prepper") }));

		api("/security").then(function (s) {
			function check(checked) { var c = el("input", { type: "checkbox" }); c.checked = !!checked; return c; }
			function num(value, min) { var n = el("input", { type: "number", min: String(min), value: String(value), style: "width:80px" }); return n; }

			var loginThrottle = check(s.login_throttle);
			var maxAttempts = num(s.login_max_attempts, 1);
			var lockoutMin = num(s.login_lockout_minutes, 1);
			var groupsPerUser = num(s.groups_per_user, 0);
			var invitesPerDay = num(s.invites_per_day, 0);
			var selfReg = check(s.allow_self_registration);
			var member2fa = check(s.member_2fa);

			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Member login & sign-up", "project-prepper") }),
				el("label", { class: "pp-toggle" }, [loginThrottle, el("span", { text: __("Lock out repeated failed logins (per IP).", "project-prepper") })]),
				el("div", { class: "pp-row", style: "margin-top:8px;gap:18px" }, [
					field(__("Max attempts", "project-prepper"), maxAttempts),
					field(__("Lockout (minutes)", "project-prepper"), lockoutMin)
				]),
				el("label", { class: "pp-toggle", style: "margin-top:12px" }, [selfReg, el("span", { text: __("Allow open sign-up (new accounts become members). Off = invitation only.", "project-prepper") })]),
				el("label", { class: "pp-toggle", style: "margin-top:12px" }, [member2fa, el("span", { text: __("Require a second factor at member login.", "project-prepper") })]),
				el("div", { class: "pp-muted", style: "margin-top:6px", text: __("When on, members get a one-time code by email at portal sign-in. Admins and managers keep the normal wp-admin login.", "project-prepper") })
			]));

			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Anti-snowball limits", "project-prepper") }),
				el("div", { class: "pp-row", style: "gap:18px" }, [
					field(__("Collectives per user", "project-prepper"), groupsPerUser),
					field(__("Invitations per day", "project-prepper"), invitesPerDay)
				]),
				el("div", { class: "pp-muted", style: "margin-top:6px", text: __("0 = unlimited. Invitations counted per member over a rolling 24 hours.", "project-prepper") })
			]));

			var saveBtn = el("button", {
				class: "pp-btn pp-btn-primary", text: __("Save security settings", "project-prepper"),
				onclick: function () {
					api("/security", {
						method: "PUT",
						body: JSON.stringify({
							login_throttle: loginThrottle.checked,
							login_max_attempts: parseInt(maxAttempts.value, 10) || 1,
							login_lockout_minutes: parseInt(lockoutMin.value, 10) || 1,
							groups_per_user: parseInt(groupsPerUser.value, 10) || 0,
							invites_per_day: parseInt(invitesPerDay.value, 10) || 0,
							allow_self_registration: selfReg.checked,
							member_2fa: member2fa.checked
						})
					}).then(function () { toast(__("Security settings saved.", "project-prepper")); }).catch(function (e) { toast(e.message, "error"); });
				}
			});
			root.appendChild(el("div", { class: "pp-row" }, [saveBtn]));
		}).catch(function (e) { toast(e.message, "error"); });
	}

	/* ================= Seite: Föderation ================= */

	function renderFederation() {
		root.innerHTML = "";
		root.appendChild(el("p", { class: "pp-muted", text: __("Make this instance discoverable to other Project Prepper instances by postal code and topic. Opt-in and OFF by default — while off, nothing is published and the discovery endpoint returns 404. Only coarse, non-personal data is shared (name, postal code, topic, counts).", "project-prepper") }));

		var dirBox = el("div");

		function load() {
			api("/federation/admin").then(function (f) {
				root.innerHTML = "";
				root.appendChild(el("p", { class: "pp-muted", text: __("Make this instance discoverable to other Project Prepper instances by postal code and topic. Opt-in and OFF by default — while off, nothing is published and the discovery endpoint returns 404. Only coarse, non-personal data is shared (name, postal code, topic, counts).", "project-prepper") }));

				var enabled = el("input", { type: "checkbox" }); enabled.checked = !!f.enabled;
				var acceptBorrows = el("input", { type: "checkbox" }); acceptBorrows.checked = !!f.accept_borrows;
				var partners = el("textarea", { rows: "4", style: "width:100%;font-family:monospace", placeholder: "https://other-collective.example" });
				partners.value = f.partners;

				root.appendChild(el("div", { class: "pp-card" }, [
					el("h2", { text: __("Public profile", "project-prepper") }),
					el("label", { class: "pp-toggle" }, [enabled, el("span", { text: __("List this instance in the federation (publish the public profile).", "project-prepper") })]),
					el("label", { class: "pp-toggle", style: "margin-top:10px" }, [acceptBorrows, el("span", { text: __("Accept borrow requests from partner instances (members moderate each request).", "project-prepper") })]),
					el("div", { class: "pp-muted", style: "margin-top:8px" }, [
						document.createTextNode(__("The published name, topic, postal code and contact come from the", "project-prepper") + " "),
						el("a", { class: "pp-link", href: "admin.php?page=pp-instance", text: __("Instance page", "project-prepper") }),
						document.createTextNode(".")
					]),
					el("div", { class: "pp-field", style: "margin-top:8px" }, [
						el("label", { text: __("Discovery endpoint", "project-prepper") }),
						el("code", { text: f.discovery_url })
					])
				]));

				root.appendChild(el("div", { class: "pp-card" }, [
					el("h2", { text: __("Partner instances", "project-prepper") }),
					partners,
					el("div", { class: "pp-muted", style: "margin-top:6px", text: __("One instance URL per line. Their public profiles are fetched and listed below (cached for an hour).", "project-prepper") })
				]));

				var saveBtn = el("button", {
					class: "pp-btn pp-btn-primary", text: __("Save federation settings", "project-prepper"),
					onclick: function () {
						api("/federation/admin", {
							method: "PUT",
							body: JSON.stringify({
								enabled: enabled.checked,
								accept_borrows: acceptBorrows.checked,
								partners: partners.value
							})
						}).then(function () { toast(__("Federation settings saved.", "project-prepper")); load(); }).catch(function (e) { toast(e.message, "error"); });
					}
				});
				root.appendChild(el("div", { class: "pp-row" }, [saveBtn]));

				// Health: Partner-Erreichbarkeit live prüfen (umgeht den 1h-Cache).
				var checkResult = el("div", { style: "margin-top:8px" });
				var checkBtn = el("button", {
					class: "pp-btn pp-btn-sm", text: __("Check partners now", "project-prepper"),
					onclick: function () {
						checkBtn.disabled = true;
						checkResult.innerHTML = "";
						checkResult.appendChild(el("span", { class: "pp-muted", text: __("Checking …", "project-prepper") }));
						api("/federation/check", { method: "POST" }).then(function (r) {
							checkBtn.disabled = false;
							var list = r.partners || [];
							if (!list.length) {
								checkResult.innerHTML = "";
								checkResult.appendChild(el("span", { class: "pp-muted", text: __("No partner instances configured yet.", "project-prepper") }));
								return;
							}
							checkResult.innerHTML = "";
							checkResult.appendChild(tableCard(
								__("Reachability", "project-prepper"),
								[__("Instance", "project-prepper"), __("Status", "project-prepper"), __("Detail", "project-prepper")],
								list.map(function (p) {
									return [
										p.name || p.url,
										p.reachable ? __("Reachable", "project-prepper") : __("Unreachable", "project-prepper"),
										p.reachable ? p.url : (p.error || p.url)
									];
								}),
								""
							));
						}).catch(function (e) { checkBtn.disabled = false; checkResult.innerHTML = ""; checkResult.appendChild(el("span", { class: "pp-muted", text: e.message })); });
					}
				});
				root.appendChild(el("div", { class: "pp-card" }, [
					el("h2", { text: __("Partner health", "project-prepper") }),
					el("div", { class: "pp-muted", style: "margin-bottom:8px", text: __("Ping every configured partner right now to see which instances are reachable.", "project-prepper") }),
					el("div", { class: "pp-row" }, [checkBtn]),
					checkResult
				]));

				root.appendChild(tableCard(
					__("Known instances", "project-prepper"),
					[__("Instance", "project-prepper"), __("Postal code", "project-prepper"), __("Topic", "project-prepper"), __("Collectives", "project-prepper"), __("Members", "project-prepper")],
					f.directory.map(function (d) {
						var label = d.reachable ? (d.name || d.url) : (d.url + " (" + __("unreachable", "project-prepper") + ")");
						return [label, d.postal_code, d.topic, d.collectives, d.members];
					}),
					__("No partner instances configured yet.", "project-prepper")
				));
			}).catch(function (e) { toast(e.message, "error"); });
		}
		load();
	}

	/* ================= Seite: Benutzer & Rechte ================= */

	function renderUsers() {
		root.innerHTML = "";
		root.appendChild(el("p", { class: "pp-muted", text: __("Manage roles and fine-grained permissions for every account. Members edit their own inventory and projects in the front-end portal — here you steer who may do what.", "project-prepper") }));

		var search = el("input", { type: "search", placeholder: __("Search name or email …", "project-prepper"), style: "width:100%; margin-bottom:12px" });
		var listBox = el("div");
		root.appendChild(search);
		root.appendChild(listBox);

		var data = null;

		function draw() {
			var q = (search.value || "").toLowerCase();
			var roleKeys = Object.keys(data.roles);
			var capKeys = Object.keys(data.caps);
			listBox.innerHTML = "";

			var shown = data.users.filter(function (u) {
				return !q || (u.name + " " + u.email).toLowerCase().indexOf(q) >= 0;
			});

			shown.forEach(function (u) {
				var roleSel = el("select", null, roleKeys.map(function (rk) {
					return el("option", { value: rk, text: data.roles[rk] });
				}));
				if (u.role && roleKeys.indexOf(u.role) < 0) roleSel.appendChild(el("option", { value: u.role, text: u.role }));
				roleSel.value = u.role;
				if (u.is_self) roleSel.disabled = true;

				var capInputs = {};
				roleSel.addEventListener("change", function () {
					var rc = data.role_caps[roleSel.value];
					if (!rc) return;
					capKeys.forEach(function (ck) {
						if (capInputs[ck] && !capInputs[ck].disabled) capInputs[ck].checked = !!rc[ck];
					});
				});
				var capGrid = el("div", { style: "display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:6px 16px; margin-top:8px" });
				capKeys.forEach(function (ck) {
					var cb = el("input", { type: "checkbox" });
					cb.checked = !!u.caps[ck];
					if (u.is_admin) { cb.checked = true; cb.disabled = true; }
					capInputs[ck] = cb;
					capGrid.appendChild(el("label", { class: "pp-toggle" }, [cb, el("span", { text: data.caps[ck] })]));
				});

				var perms = el("details", { style: "margin-top:10px" });
				perms.appendChild(el("summary", { class: "pp-btn pp-btn-sm", text: __("Permissions", "project-prepper") }));
				perms.appendChild(u.is_admin
					? el("div", { class: "pp-muted", style: "margin-top:6px", text: __("Administrators always have every permission.", "project-prepper") })
					: capGrid);

				var saveBtn = el("button", {
					class: "pp-btn pp-btn-sm pp-btn-primary", text: __("Save", "project-prepper"),
					onclick: function () {
						var caps = {};
						capKeys.forEach(function (ck) { caps[ck] = capInputs[ck].checked; });
						var body = { caps: caps };
						if (!u.is_self) body.role = roleSel.value;
						api("/users/" + u.id, { method: "PUT", body: JSON.stringify(body) })
							.then(function (d) { data = d; toast(__("Saved.", "project-prepper")); draw(); })
							.catch(function (e) { toast(e.message, "error"); });
					}
				});

				var meta = [];
				if (u.registered) meta.push(__("registered", "project-prepper") + ": " + u.registered);
				meta.push(__("last login", "project-prepper") + ": " + (u.last_login || "—"));

				var card = el("div", { class: "pp-card" }, [
					el("div", { style: "display:flex; align-items:center; gap:12px; flex-wrap:wrap" }, [
						el("div", { style: "flex:1; min-width:160px" }, [
							el("div", { style: "font-weight:500" }, [
								document.createTextNode(u.name + " "),
								u.is_self ? el("span", { class: "pp-badge", text: __("you", "project-prepper") }) : null
							]),
							el("div", { class: "pp-muted", text: u.email })
						]),
						field(__("Role", "project-prepper"), roleSel)
					]),
					el("div", { class: "pp-muted", style: "margin-top:6px", text: meta.join(" · ") }),
					u.groups.length
						? el("div", { style: "margin-top:6px; display:flex; gap:6px; flex-wrap:wrap" }, u.groups.map(function (g) {
							return el("span", { class: "pp-badge", text: g.name + " (" + g.role + ")" });
						}))
						: null,
					perms,
					el("div", { class: "pp-row", style: "margin-top:10px" }, [
						saveBtn,
						u.impersonate_url
							? el("a", { class: "pp-btn pp-btn-sm", href: u.impersonate_url, title: __("Open the member portal as this user — a banner lets you switch back.", "project-prepper"), text: __("View as", "project-prepper") })
							: null
					])
				]);
				listBox.appendChild(card);
			});

			if (!shown.length) listBox.appendChild(el("p", { class: "pp-muted", text: __("No users match.", "project-prepper") }));
		}

		search.addEventListener("input", draw);
		api("/users").then(function (d) { data = d; draw(); }).catch(function (e) { toast(e.message, "error"); });
	}

	/* ================= Seite: E-Mail-Templates ================= */

	function renderEmailTemplates() {
		root.innerHTML = "";
		root.appendChild(el("p", { class: "pp-muted", text: __("Every email the plugin sends, in one place. Keep the {{placeholders}} — they get replaced with the real values.", "project-prepper") }));

		api("/email-templates").then(function (d) {
			if (!d.enabled) {
				root.appendChild(el("div", { class: "pp-card" }, [
					el("div", { class: "pp-muted", text: __("Email sending is currently off — turn it on under Settings → Email notifications for these templates to take effect.", "project-prepper") })
				]));
			}

			var inputs = {};
			d.catalog.forEach(function (c) {
				var tpl = d.templates[c.key] || { subject: "", body: "" };
				var subject = el("input", { type: "text", value: tpl.subject, style: "width:100%" });
				var body = el("textarea", { style: "width:100%; min-height:150px; font-family:monospace" });
				body.value = tpl.body;
				inputs[c.key] = { subject: subject, body: body };

				var card = [el("h2", { text: c.label })];
				if (c.vars.length) {
					card.push(el("div", { class: "pp-muted", style: "margin-bottom:8px" }, [
						document.createTextNode(__("Placeholders:", "project-prepper") + " "),
						el("code", { text: c.vars.map(function (v) { return "{{" + v + "}}"; }).join(" ") })
					]));
				}
				card.push(field(__("Subject", "project-prepper"), subject));
				card.push(field(__("Text", "project-prepper"), body));
				root.appendChild(el("div", { class: "pp-card" }, card));
			});

			var saveBtn = el("button", {
				class: "pp-btn pp-btn-primary", text: __("Save templates", "project-prepper"),
				onclick: function () {
					var templates = {};
					Object.keys(inputs).forEach(function (k) {
						templates[k] = { subject: inputs[k].subject.value, body: inputs[k].body.value };
					});
					api("/email-templates", { method: "PUT", body: JSON.stringify({ templates: templates }) })
						.then(function () { toast(__("Templates saved.", "project-prepper")); })
						.catch(function (e) { toast(e.message, "error"); });
				}
			});
			root.appendChild(el("div", { class: "pp-row" }, [saveBtn]));
		}).catch(function (e) { toast(e.message, "error"); });
	}

	/* ================= Seite: Instanz ================= */

	function renderInstance() {
		root.innerHTML = "";
		root.appendChild(el("p", { class: "pp-muted", text: __("Who you are, how your instance is funded, and your terms. The federation publishes your identity from here.", "project-prepper") }));

		api("/instance").then(function (d) {
			var name = el("input", { type: "text", value: d.name, style: "width:100%" });
			var purpose = el("textarea", { style: "width:100%; min-height:70px" }); purpose.value = d.purpose;
			var topic = el("input", { type: "text", value: d.topic, style: "width:100%" });
			var postal = el("input", { type: "text", value: d.postal_code, style: "width:140px" });
			var contact = el("input", { type: "email", value: d.contact_email, style: "width:100%" });
			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Identity & purpose", "project-prepper") }),
				field(__("Name", "project-prepper"), name),
				field(__("Purpose", "project-prepper"), purpose),
				field(__("Topic", "project-prepper"), topic),
				field(__("Postal code", "project-prepper"), postal),
				field(__("Contact email", "project-prepper"), contact)
			]));

			var model = el("select", null, d.economy_models.map(function (m) { return el("option", { value: m.value, text: m.label }); }));
			model.value = d.economy.model;
			var amount = el("input", { type: "number", min: "0", step: "0.01", value: d.economy.amount, style: "width:110px" });
			var interval = el("select", null, [el("option", { value: "year", text: __("per year", "project-prepper") }), el("option", { value: "month", text: __("per month", "project-prepper") })]);
			interval.value = d.economy.interval;
			var currency = el("input", { type: "text", value: d.economy.currency, style: "width:70px" });
			var note = el("textarea", { style: "width:100%; min-height:60px" }); note.value = d.economy.note;
			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Economy model", "project-prepper") }),
				el("div", { class: "pp-muted", style: "margin-bottom:8px", text: __("Declare how your instance is funded. Payment processing and tracking are a separate, later step — this just records the model.", "project-prepper") }),
				field(__("Model", "project-prepper"), model),
				el("div", { class: "pp-row", style: "gap:14px; flex-wrap:wrap" }, [field(__("Amount", "project-prepper"), amount), field(__("Interval", "project-prepper"), interval), field(__("Currency", "project-prepper"), currency)]),
				field(__("Note for members", "project-prepper"), note)
			]));

			var agb = el("textarea", {
				style: "width:100%; min-height:320px; line-height:1.6; padding:12px; font-size:14px; resize:vertical",
				placeholder: __("Write your terms of use here. Plain text — line breaks are kept exactly as members will see them.", "project-prepper")
			});
			agb.value = d.legal.agb_text;
			var requireAcc = el("input", { type: "checkbox" }); requireAcc.checked = !!d.legal.require_acceptance;

			function escHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
			var preview = el("div", { style: "display:none; min-height:320px; padding:12px; border:1px solid #e3e3e3; border-radius:8px; line-height:1.6; background:#fafafa" });
			var counter = el("span", { class: "pp-muted" });
			function updateMeta() { counter.textContent = agb.value.length + " " + __("characters", "project-prepper"); }
			agb.addEventListener("input", updateMeta); updateMeta();

			var tabEdit = el("button", { type: "button", class: "pp-btn" });
			var tabPrev = el("button", { type: "button", class: "pp-btn" });
			tabEdit.textContent = __("Edit", "project-prepper");
			tabPrev.textContent = __("Preview", "project-prepper");
			function showEdit() { agb.style.display = ""; preview.style.display = "none"; tabEdit.classList.add("pp-btn-primary"); tabPrev.classList.remove("pp-btn-primary"); }
			function showPrev() {
				var body = escHtml(agb.value).replace(/\n/g, "<br>");
				preview.innerHTML = body || ('<span class="pp-muted">' + escHtml(__("Nothing to preview yet.", "project-prepper")) + "</span>");
				agb.style.display = "none"; preview.style.display = ""; tabPrev.classList.add("pp-btn-primary"); tabEdit.classList.remove("pp-btn-primary");
			}
			tabEdit.addEventListener("click", showEdit);
			tabPrev.addEventListener("click", showPrev);
			showEdit();

			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Terms (legal basis)", "project-prepper") }),
				el("div", { class: "pp-muted", style: "margin-bottom:10px", text: __("Your terms of use. When enforcement is on, members must accept the current version before using the portal. Line breaks are shown to members exactly as typed.", "project-prepper") }),
				el("div", { class: "pp-row", style: "gap:8px; align-items:center; margin-bottom:8px" }, [
					tabEdit, tabPrev,
					el("span", { style: "flex:1" }),
					el("span", { class: "pp-muted", text: __("Current version:", "project-prepper") + " " + (d.legal.agb_version || 0) }),
					counter
				]),
				agb,
				preview,
				el("label", { class: "pp-toggle", style: "margin-top:10px" }, [requireAcc, el("span", { text: __("Members must accept these terms before using the portal.", "project-prepper") })])
			]));

			var imp = d.imprint || {};
			var imOperator = el("input", { type: "text", value: imp.operator || "", style: "width:100%" });
			var imStreet = el("input", { type: "text", value: imp.street || "", style: "width:100%" });
			var imPostal = el("input", { type: "text", value: imp.postal_code || "", style: "width:140px" });
			var imCity = el("input", { type: "text", value: imp.city || "", style: "width:100%" });
			var imCountry = el("input", { type: "text", value: imp.country || "", style: "width:100%" });
			var imEmail = el("input", { type: "email", value: imp.email || "", style: "width:100%" });
			var imPhone = el("input", { type: "text", value: imp.phone || "", style: "width:100%" });
			var imForm = el("textarea", { style: "width:100%; min-height:60px" }); imForm.value = imp.legal_form || "";
			var imHosting = el("input", { type: "text", value: imp.hosting || "", style: "width:100%" });
			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Legal details (imprint & privacy)", "project-prepper") }),
				el("div", { class: "pp-muted", style: "margin-bottom:8px", text: __("These details generate your imprint and privacy policy via the [pp_impressum] and [pp_datenschutz] shortcodes. Stored in the database, not hardcoded.", "project-prepper") }),
				field(__("Operator (name / company / association)", "project-prepper"), imOperator),
				field(__("Street and number", "project-prepper"), imStreet),
				el("div", { class: "pp-row", style: "gap:14px; flex-wrap:wrap" }, [field(__("Postal code", "project-prepper"), imPostal), field(__("City", "project-prepper"), imCity), field(__("Country", "project-prepper"), imCountry)]),
				field(__("Contact email", "project-prepper"), imEmail),
				field(__("Phone (optional)", "project-prepper"), imPhone),
				field(__("Legal form / register / representation (optional)", "project-prepper"), imForm),
				field(__("Hosting provider (optional, for privacy policy)", "project-prepper"), imHosting)
			]));

			var saveBtn = el("button", {
				class: "pp-btn pp-btn-primary", text: __("Save", "project-prepper"),
				onclick: function () {
					api("/instance", {
						method: "PUT",
						body: JSON.stringify({
							name: name.value, purpose: purpose.value, topic: topic.value, postal_code: postal.value, contact_email: contact.value,
							economy: { model: model.value, amount: amount.value, interval: interval.value, currency: currency.value, note: note.value },
							legal: { agb_text: agb.value, require_acceptance: requireAcc.checked },
							imprint: { operator: imOperator.value, street: imStreet.value, postal_code: imPostal.value, city: imCity.value, country: imCountry.value, email: imEmail.value, phone: imPhone.value, legal_form: imForm.value, hosting: imHosting.value }
						})
					}).then(function () { toast(__("Saved.", "project-prepper")); }).catch(function (e) { toast(e.message, "error"); });
				}
			});
			root.appendChild(el("div", { class: "pp-row" }, [saveBtn]));
		}).catch(function (e) { toast(e.message, "error"); });
	}

	/* ================= Routing ================= */

	if (page === "calendar") renderCalendar();
	else if (page === "dashboard") renderDashboard();
	else if (page === "categories") renderCategories();
	else if (page === "projects") renderProjects();
	else if (page === "groups") renderGroups();
	else if (page === "rentals") renderRentals();
	else if (page === "inquiries") renderInquiries();
	else if (page === "settings") renderSettings();
	else if (page === "instance") renderInstance();
	else if (page === "platform") renderPlatform();
	else if (page === "security") renderSecurity();
	else if (page === "federation") renderFederation();
	else if (page === "users") renderUsers();
	else if (page === "email-templates") renderEmailTemplates();
	else renderInventory();
})();
