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
		var categories = [];
		var activeCategory = "";
		var outOnly = false;
		var kpiBox = el("div", { class: "pp-kpis" });
		var pillBox = el("div", { class: "pp-pills" });
		var listBox = el("div");
		var search = el("input", { type: "search", class: "pp-search", placeholder: __("Search: name, number, manufacturer, serial number, tags …", "project-prepper") });

		function loadStats() {
			api("/stats").then(function (s) {
				kpiBox.innerHTML = "";
				[
					{ value: s.item_count, label: __("Items", "project-prepper") },
					{ value: s.total_pieces, label: __("Total pieces", "project-prepper") },
					{ value: s.out_today, label: __("Out today", "project-prepper") },
					{ value: money(s.daily_value), label: __("Daily inventory value", "project-prepper") }
				].forEach(function (kpi) {
					kpiBox.appendChild(el("div", { class: "pp-kpi" }, [
						el("div", { class: "pp-kpi-value", text: String(kpi.value) }),
						el("div", { class: "pp-kpi-label", text: kpi.label })
					]));
				});
			}).catch(function () {});
		}

		function renderPills() {
			pillBox.innerHTML = "";
			// Toggle "Ausgeliehen" (§8.5) — Artikel, die heute in reserved/active-Verleihen stecken.
			pillBox.appendChild(el("button", {
				class: "pp-pill pp-pill-out" + (outOnly ? " is-active" : ""),
				text: __("Out now", "project-prepper"),
				onclick: function () { outOnly = !outOnly; renderPills(); loadItems(); }
			}));
			var all = el("button", { class: "pp-pill" + (activeCategory === "" ? " is-active" : ""), text: __("All", "project-prepper"), onclick: function () { activeCategory = ""; renderPills(); loadItems(); } });
			pillBox.appendChild(all);
			categories.forEach(function (cat) {
				pillBox.appendChild(el("button", {
					class: "pp-pill" + (activeCategory === String(cat.id) ? " is-active" : ""),
					text: (cat.icon ? cat.icon + " " : "") + cat.name,
					onclick: function () { activeCategory = String(cat.id); renderPills(); loadItems(); }
				}));
			});
		}

		function loadItems() {
			var params = [];
			if (search.value.trim()) params.push("search=" + encodeURIComponent(search.value.trim()));
			if (activeCategory) params.push("category_id=" + activeCategory);
			if (outOnly) params.push("out_only=1");
			api("/items" + (params.length ? "?" + params.join("&") : "")).then(function (items) {
				listBox.innerHTML = "";
				var table = el("table", { class: "pp-table" });
				table.appendChild(el("thead", {
					html: "<tr><th></th><th>" + __("Name", "project-prepper") + "</th><th>" + __("Category", "project-prepper") + "</th><th>" + __("Quantity", "project-prepper") + "</th><th>" + __("Condition", "project-prepper") + "</th><th>" + __("Daily rate", "project-prepper") + "</th><th>" + __("Location", "project-prepper") + "</th><th>" + __("Docs", "project-prepper") + "</th></tr>"
				}));
				var tbody = el("tbody");
				items.forEach(function (item) {
					var thumb = item.image_url
						? el("img", { class: "pp-thumb", src: item.image_url, alt: "" })
						: el("div", { class: "pp-thumb-empty", text: item.category_icon || "📦" });
					// Name + Inventarnummer als Monospace-Badge darunter (App-Layout).
					// Badge "n unterwegs" wenn der Artikel heute in Verleihen oder
					// bestätigten Projekten steckt (gleiche Semantik wie out_now).
					var nameLine = el("div", null, [el("span", { text: item.name })]);
					if (item.out_now > 0) {
						nameLine.appendChild(el("span", { class: "pp-badge pp-badge-active pp-badge-out", text: item.out_now + " " + __("out", "project-prepper") }));
					}
					var nameCell = el("td", null, [nameLine]);
					if (item.inventory_number) {
						nameCell.appendChild(el("div", null, [el("span", { class: "pp-inv-number", text: item.inventory_number })]));
					}
					// Doku-Spalte: 1 PDF → direkt öffnen, mehrere → Detail-Modal (wie App, Commit e9fe5b8).
					var docsCell = el("td");
					var docs = item.documents || [];
					if (docs.length === 1) {
						docsCell.appendChild(el("a", {
							class: "pp-link", href: docs[0].url, target: "_blank", rel: "noopener noreferrer",
							text: __("View PDF", "project-prepper"), title: __("View PDF", "project-prepper"),
							onclick: function (e) { e.stopPropagation(); }
						}));
					} else if (docs.length > 1) {
						docsCell.appendChild(el("button", {
							class: "pp-link", type: "button",
							text: "PDFs (" + docs.length + ")", title: __("View documents", "project-prepper"),
							onclick: function (e) { e.stopPropagation(); openItemModal(item.id); }
						}));
					} else {
						docsCell.textContent = "—";
					}
					var row = el("tr", { class: "pp-clickable", onclick: function () { openItemModal(item.id); } }, [
						el("td", null, [thumb]),
						nameCell,
						el("td", { text: (item.category_icon ? item.category_icon + " " : "") + (item.category_name || "—") }),
						el("td", { text: item.quantity }),
						el("td", null, [el("span", { class: "pp-cond pp-cond-" + item.condition, text: CONDITIONS[item.condition] || item.condition })]),
						el("td", { text: money(item.cost_per_day) }),
						el("td", { text: item.location || "—" }),
						docsCell
					]);
					tbody.appendChild(row);
				});
				if (!items.length) tbody.appendChild(el("tr", { html: '<td colspan="8" class="pp-muted">' + __("No items found.", "project-prepper") + "</td>" }));
				table.appendChild(tbody);
				listBox.appendChild(el("div", { class: "pp-table-wrap" }, [table]));
			}).catch(function (e) { toast(e.message, "error"); });
		}

		function loadCategories(then) {
			api("/categories").then(function (cats) {
				categories = cats;
				renderPills();
				if (then) then();
			});
		}

		/* ----- Detail-Modal (Pendant zu inventory-detail-modal.tsx) ----- */

		function openItemModal(itemId) {
			api("/items/" + itemId).then(function (item) {
				var f = {};
				f.name = el("input", { type: "text", value: item.name || "" });
				f.category = el("select", null, [el("option", { value: "", text: __("— category —", "project-prepper") })].concat(categories.map(function (cat) {
					return el("option", { value: cat.id, text: cat.name });
				})));
				f.category.value = item.category_id || "";
				f.quantity = el("input", { type: "number", min: "1", value: item.quantity });
				f.condition = conditionSelect(item.condition);
				f.location = el("input", { type: "text", value: item.location || "" });
				f.manufacturer = el("input", { type: "text", value: item.manufacturer || "" });
				f.model = el("input", { type: "text", value: item.model || "" });
				f.serial = el("input", { type: "text", value: item.serial_number || "" });
				f.costPerDay = el("input", { type: "number", step: "0.01", value: item.cost_per_day || "" });
				f.purchasePrice = el("input", { type: "number", step: "0.01", value: item.purchase_price || "" });
				f.purchaseDate = el("input", { type: "date", value: item.purchase_date || "" });
				f.currentValue = el("input", { type: "number", step: "0.01", value: item.current_value || "" });
				f.dimensions = el("input", { type: "text", value: item.dimensions || "" });
				f.powerWatts = el("input", { type: "number", value: item.power_watts || "" });
				f.manufacturerUrl = el("input", { type: "url", value: item.manufacturer_url || "" });
				f.manualUrl = el("input", { type: "url", value: item.manual_url || "" });
				f.tags = el("input", { type: "text", value: (item.tags || []).join(", "), placeholder: __("Comma-separated", "project-prepper") });
				f.description = el("textarea", { rows: "2" }); f.description.value = item.description || "";
				f.accessories = el("textarea", { rows: "2" }); f.accessories.value = item.accessories || "";
				f.notes = el("textarea", { rows: "2" }); f.notes.value = item.notes || "";
				// Eigentum & Abschreibung (§8.7 — reine Dokumentation, keine Buchung)
				var OWNERSHIP_TYPES = { "": "—", own: __("Own", "project-prepper"), loaned: __("Loaned", "project-prepper"), funded: __("Funded", "project-prepper"), other: __("Other", "project-prepper") };
				var DEPRECIATION_METHODS = { "": "—", linear: __("Linear", "project-prepper"), degressive: __("Declining balance", "project-prepper"), none: __("None", "project-prepper") };
				f.ownershipType = el("select", null, Object.keys(OWNERSHIP_TYPES).map(function (key) {
					return el("option", { value: key, text: OWNERSHIP_TYPES[key] });
				}));
				f.ownershipType.value = item.ownership_type || "";
				f.fundingSource = el("input", { type: "text", value: item.funding_source || "", placeholder: __("e.g. funding program, donation", "project-prepper") });
				f.depreciationMethod = el("select", null, Object.keys(DEPRECIATION_METHODS).map(function (key) {
					return el("option", { value: key, text: DEPRECIATION_METHODS[key] });
				}));
				f.depreciationMethod.value = item.depreciation_method || "";
				f.depreciationYears = el("input", { type: "number", min: "1", max: "30", placeholder: "7", value: item.depreciation_years || "" });
				f.residualValue = el("input", { type: "number", step: "0.01", min: "0", value: item.residual_value === null || item.residual_value === undefined ? "" : item.residual_value });

				var body = el("div", null, [
					el("div", { class: "pp-modal-grid" }, [
						field(__("Name *", "project-prepper"), f.name), field(__("Category", "project-prepper"), f.category), field(__("Quantity", "project-prepper"), f.quantity),
						field(__("Condition", "project-prepper"), f.condition), field(__("Location", "project-prepper"), f.location), field(__("Manufacturer", "project-prepper"), f.manufacturer),
						field(__("Model", "project-prepper"), f.model), field(__("Serial number", "project-prepper"), f.serial), field(__("Daily rate €", "project-prepper"), f.costPerDay),
						field(__("Purchase price €", "project-prepper"), f.purchasePrice), field(__("Purchase date", "project-prepper"), f.purchaseDate), field(__("Current value €", "project-prepper"), f.currentValue),
						field(__("Dimensions", "project-prepper"), f.dimensions), field(__("Power (W)", "project-prepper"), f.powerWatts),
						field(__("Manufacturer URL", "project-prepper"), f.manufacturerUrl), field(__("Manual URL", "project-prepper"), f.manualUrl)
					]),
					el("div", { class: "pp-modal-section" }, [
						el("h3", { text: __("Texts", "project-prepper") }),
						el("div", { class: "pp-modal-grid" }, [
							field(__("Description", "project-prepper"), f.description), field(__("Accessories", "project-prepper"), f.accessories),
							field(__("Tags", "project-prepper"), f.tags), field(__("Notes", "project-prepper"), f.notes)
						])
					]),
					el("div", { class: "pp-modal-section", "data-section": "ownership" }, [
						el("h3", { text: __("Ownership & depreciation", "project-prepper") }),
						el("div", { class: "pp-modal-grid" }, [
							field(__("Ownership", "project-prepper"), f.ownershipType), field(__("Funding source", "project-prepper"), f.fundingSource),
							field(__("Depreciation", "project-prepper"), f.depreciationMethod), field(__("Useful life (years)", "project-prepper"), f.depreciationYears),
							field(__("Residual value €", "project-prepper"), f.residualValue)
						])
					])
				]);

				// Foto-Sektion
				var photoSection = el("div", { class: "pp-modal-section" });
				function renderPhoto(current) {
					photoSection.innerHTML = "";
					photoSection.appendChild(el("h3", { text: __("Photo", "project-prepper") }));
					if (current.image_url) photoSection.appendChild(el("img", { class: "pp-item-photo", src: current.image_url, alt: "" }));
					var fileInput = el("input", { type: "file", accept: "image/*" });
					fileInput.addEventListener("change", function () {
						if (!fileInput.files.length) return;
						apiUpload("/items/" + itemId + "/image", fileInput.files[0]).then(function (updated) {
							toast(__("Photo saved.", "project-prepper"));
							renderPhoto(updated);
							loadItems();
						}).catch(function (e) { toast(e.message, "error"); });
					});
					var row = el("div", { class: "pp-row" }, [fileInput]);
					if (current.image_url) {
						row.appendChild(el("button", {
							class: "pp-link pp-link-danger", text: __("Remove photo", "project-prepper"),
							onclick: function () {
								api("/items/" + itemId + "/image", { method: "DELETE" }).then(function (updated) {
									renderPhoto(updated); loadItems();
								});
							}
						}));
					}
					photoSection.appendChild(row);
				}
				renderPhoto(item);
				body.appendChild(photoSection);

				// PDF-Dokumente
				var docsSection = el("div", { class: "pp-modal-section" });
				function renderDocs(current) {
					docsSection.innerHTML = "";
					docsSection.appendChild(el("h3", { text: __("PDF documents", "project-prepper") }));
					var list = el("ul", { class: "pp-lines" });
					(current.documents || []).forEach(function (doc) {
						list.appendChild(el("li", null, [
							el("a", { href: doc.url, target: "_blank", text: doc.title || __("Document", "project-prepper"), class: "pp-link" }),
							el("span", { class: "pp-spacer" }),
							el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"),
								onclick: function () {
									api("/items/" + itemId + "/documents/" + doc.id, { method: "DELETE" }).then(function (updated) {
										renderDocs(updated); loadItems();
									});
								}
							})
						]));
					});
					if (!(current.documents || []).length) list.appendChild(el("li", { class: "pp-muted", text: __("No documents.", "project-prepper") }));
					docsSection.appendChild(list);
					var fileInput = el("input", { type: "file", accept: "application/pdf" });
					fileInput.addEventListener("change", function () {
						if (!fileInput.files.length) return;
						apiUpload("/items/" + itemId + "/documents", fileInput.files[0]).then(function (updated) {
							toast(__("PDF uploaded.", "project-prepper"));
							renderDocs(updated); loadItems();
						}).catch(function (e) { toast(e.message, "error"); });
					});
					docsSection.appendChild(fileInput);
				}
				renderDocs(item);
				body.appendChild(docsSection);

				// Einzelstücke (§8.4)
				var unitsSection = el("div", { class: "pp-modal-section" });
				function renderUnits() {
					api("/items/" + itemId + "/units").then(function (units) {
						unitsSection.innerHTML = "";
						unitsSection.appendChild(el("h3", { text: __("Units", "project-prepper") + " (" + units.length + "/" + item.quantity + ")" }));
						var list = el("ul", { class: "pp-lines" });
						units.forEach(function (unit) {
							var cond = conditionSelect(unit.unit_condition);
							cond.addEventListener("change", function () {
								api("/units/" + unit.id, { method: "PUT", body: JSON.stringify({ condition: cond.value }) });
							});
							var notes = el("input", { type: "text", value: unit.notes || "", placeholder: __("Notes", "project-prepper") });
							notes.addEventListener("change", function () {
								api("/units/" + unit.id, { method: "PUT", body: JSON.stringify({ notes: notes.value }) });
							});
							list.appendChild(el("li", null, [
								el("code", { text: "#" + unit.unit_number }),
								cond, notes,
								el("button", {
									class: "pp-link pp-link-danger", text: __("delete", "project-prepper"),
									onclick: function () { api("/units/" + unit.id, { method: "DELETE" }).then(renderUnits); }
								})
							]));
						});
						if (!units.length) list.appendChild(el("li", { class: "pp-muted", text: __("No unit tracking.", "project-prepper") }));
						unitsSection.appendChild(list);
						if (units.length < item.quantity) {
							unitsSection.appendChild(el("button", {
								class: "pp-btn pp-btn-sm", text: __("+ Unit", "project-prepper"),
								onclick: function () {
									api("/items/" + itemId + "/units", { method: "POST", body: JSON.stringify({}) })
										.then(renderUnits).catch(function (e) { toast(e.message, "error"); });
								}
							}));
						}
					});
				}
				renderUnits();
				body.appendChild(unitsSection);

				// Projekt-Buchungen (read-only): in welchen Projekten der Artikel
				// gebucht ist — namentlich, Pendant zum aggregierten out_now-Zähler.
				var bookings = item.project_bookings || [];
				if (bookings.length) {
					var PB_STATUS = { draft: __("Draft", "project-prepper"), planned: __("Planned", "project-prepper"), confirmed: __("Confirmed", "project-prepper"), running: __("Running", "project-prepper"), done: __("Done", "project-prepper"), cancelled: __("Cancelled", "project-prepper") };
					var PB_BADGE = { draft: "draft", planned: "reserved", confirmed: "offer", running: "active", done: "returned", cancelled: "cancelled" };
					var bookSection = el("div", { class: "pp-modal-section" }, [el("h3", { text: __("Booked in projects", "project-prepper") })]);
					var blist = el("ul", { class: "pp-lines" });
					bookings.forEach(function (b) {
						var period = b.date_from ? (dateDe(b.date_from) + (b.date_to ? " – " + dateDe(b.date_to) : "")) : __("no date", "project-prepper");
						blist.appendChild(el("li", null, [
							el("span", { class: "pp-badge pp-badge-" + (PB_BADGE[b.status] || b.status), text: PB_STATUS[b.status] || b.status }),
							el("a", { href: "admin.php?page=pp-projects#pp-project-" + b.project_id, class: "pp-link", text: b.project_name }),
							el("span", { class: "pp-muted", text: " · " + b.quantity + "× · " + period })
						]));
					});
					bookSection.appendChild(blist);
					body.appendChild(bookSection);
				}

				var close;
				var footer = el("div", { class: "pp-modal-footer" }, [
					el("button", {
						class: "pp-btn pp-btn-danger", text: __("Delete item", "project-prepper"),
						onclick: function () {
							/* translators: %s: item name */
							if (!confirm(sprintf(__('Delete item "%s"?', "project-prepper"), item.name))) return;
							api("/items/" + itemId, { method: "DELETE" }).then(function () {
								toast(__("Item deleted.", "project-prepper")); close(); loadItems(); loadStats();
							});
						}
					}),
					el("div", { class: "pp-right" }, [
						el("button", { class: "pp-btn", text: __("Close", "project-prepper"), onclick: function () { close(); } }),
						el("button", {
							class: "pp-btn pp-btn-primary", text: __("Save", "project-prepper"),
							onclick: function () {
								api("/items/" + itemId, {
									method: "PUT",
									body: JSON.stringify({
										name: f.name.value.trim(),
										category_id: f.category.value ? parseInt(f.category.value, 10) : 0,
										quantity: parseInt(f.quantity.value, 10) || 1,
										condition: f.condition.value,
										location: f.location.value.trim(),
										manufacturer: f.manufacturer.value.trim(),
										model: f.model.value.trim(),
										serial_number: f.serial.value.trim(),
										cost_per_day: f.costPerDay.value,
										purchase_price: f.purchasePrice.value,
										purchase_date: f.purchaseDate.value,
										current_value: f.currentValue.value,
										dimensions: f.dimensions.value.trim(),
										power_watts: f.powerWatts.value,
										manufacturer_url: f.manufacturerUrl.value.trim(),
										manual_url: f.manualUrl.value.trim(),
										ownership_type: f.ownershipType.value,
										funding_source: f.fundingSource.value.trim(),
										depreciation_method: f.depreciationMethod.value,
										depreciation_years: f.depreciationYears.value,
										residual_value: f.residualValue.value,
										tags: f.tags.value.split(",").map(function (t) { return t.trim(); }).filter(Boolean),
										description: f.description.value,
										accessories: f.accessories.value,
										notes: f.notes.value
									})
								}).then(function () {
									toast(__("Saved.", "project-prepper")); close(); loadItems(); loadStats();
								}).catch(function (e) { toast(e.message, "error"); });
							}
						})
					])
				]);
				close = openModal(item.inventory_number + " — " + item.name, body, ppConfig.canEdit.inventory ? footer : null);
			}).catch(function (e) { toast(e.message, "error"); });
		}

		/* ----- Anlegen (Inline-Form wie App §8.1) ----- */

		var createCard = null;
		if (ppConfig.canEdit.inventory) {
			var cName = el("input", { type: "text", placeholder: __("Name *", "project-prepper"), class: "pp-input-lg" });
			var cCat = el("select", { class: "pp-input-md" });
			var cQty = el("input", { type: "number", value: "1", min: "1", class: "pp-input-sm" });
			var cCondition = conditionSelect("good"); cCondition.classList.add("pp-input-sm");
			var cRate = el("input", { type: "number", step: "0.01", placeholder: __("Daily rate €", "project-prepper"), class: "pp-input-sm" });
			var cLocation = el("input", { type: "text", placeholder: __("Location", "project-prepper"), class: "pp-input-md" });
			// Foto + PDFs direkt beim Anlegen (wie App, Commit 60eb81b):
			// erst POST /items, danach die Media-Endpoints mit der neuen Artikel-ID.
			var cPhoto = el("input", { type: "file", accept: "image/*" });
			var cPdfs = el("input", { type: "file", accept: "application/pdf,.pdf", multiple: "multiple" });

			function uploadCreateMedia(itemId) {
				var chain = Promise.resolve();
				if (cPhoto.files.length) {
					var photoFile = cPhoto.files[0];
					chain = chain.then(function () {
						return apiUpload("/items/" + itemId + "/image", photoFile);
					}).catch(function (e) {
						toast(__("Photo upload failed:", "project-prepper") + " " + e.message, "error");
					});
				}
				// PDFs sequentiell hochladen (Dokumentliste wird serverseitig fortgeschrieben).
				Array.prototype.slice.call(cPdfs.files).forEach(function (file) {
					chain = chain.then(function () {
						return apiUpload("/items/" + itemId + "/documents", file);
					}).catch(function (e) {
						/* translators: 1: file name, 2: error message */
						toast(sprintf(__('PDF "%1$s" failed: %2$s', "project-prepper"), file.name, e.message), "error");
					});
				});
				return chain;
			}

			createCard = el("div", { class: "pp-card" }, [
				el("h2", { text: __("New item", "project-prepper") }),
				el("form", {
					onsubmit: function (e) {
						e.preventDefault();
						if (!cName.value.trim()) return;
						api("/items", {
							method: "POST",
							body: JSON.stringify({
								name: cName.value.trim(),
								category_id: cCat.value ? parseInt(cCat.value, 10) : 0,
								quantity: parseInt(cQty.value, 10) || 1,
								condition: cCondition.value,
								cost_per_day: cRate.value,
								location: cLocation.value.trim()
							})
						}).then(function (item) {
							/* translators: %s: inventory number */
							toast(sprintf(__("Item %s created.", "project-prepper"), item.inventory_number));
							// Artikel bleibt auch bei Upload-Fehlern angelegt.
							uploadCreateMedia(item.id).then(function () {
								cPhoto.value = ""; cPdfs.value = "";
								loadItems(); loadStats();
							});
							cName.value = cRate.value = cLocation.value = ""; cQty.value = "1";
						}).catch(function (e2) { toast(e2.message, "error"); });
					}
				}, [
					el("div", { class: "pp-row" }, [cName, cCat, cQty, cCondition, cRate, cLocation]),
					el("div", { class: "pp-row" }, [
						field(__("Photo", "project-prepper"), cPhoto),
						field(__("PDF documents", "project-prepper"), cPdfs),
						el("button", { class: "pp-btn pp-btn-primary", text: __("Create", "project-prepper") })
					])
				])
			]);
			loadCategories(function () {
				cCat.innerHTML = "";
				cCat.appendChild(el("option", { value: "", text: __("— category —", "project-prepper") }));
				categories.forEach(function (cat) { cCat.appendChild(el("option", { value: cat.id, text: cat.name })); });
			});
		} else {
			loadCategories();
		}

		/* ----- Export / Import (§8.6) ----- */

		// Export-Spalten = EXPORT_COLUMNS des CSV-Endpoints (ImportExportController), 19 Spalten, deutsche Header.
		var EXPORT_COLUMNS = [
			["inventory_number", __("Inventory number", "project-prepper")], ["name", __("Name", "project-prepper")], ["category_name", __("Category", "project-prepper")],
			["description", __("Description", "project-prepper")], ["manufacturer", __("Manufacturer", "project-prepper")], ["model", __("Model", "project-prepper")],
			["serial_number", __("Serial number", "project-prepper")], ["quantity", __("Quantity", "project-prepper")], ["condition", __("Condition", "project-prepper")],
			["location", __("Location", "project-prepper")], ["cost_per_day", __("Daily rate", "project-prepper")], ["purchase_price", __("Purchase price", "project-prepper")],
			["purchase_date", __("Purchase date", "project-prepper")], ["current_value", __("Current value", "project-prepper")], ["dimensions", __("Dimensions", "project-prepper")],
			["power_watts", __("Power (W)", "project-prepper")], ["accessories", __("Accessories", "project-prepper")], ["tags", __("Tags", "project-prepper")], ["notes", __("Notes", "project-prepper")]
		];
		var CONDITION_EXPORT_LABELS = CONDITIONS;

		function currentFilterParams() {
			var params = [];
			if (search.value.trim()) params.push("search=" + encodeURIComponent(search.value.trim()));
			if (activeCategory) params.push("category_id=" + activeCategory);
			if (outOnly) params.push("out_only=1");
			return params;
		}

		function exportXlsx() {
			var params = currentFilterParams();
			api("/items" + (params.length ? "?" + params.join("&") : "")).then(function (items) {
				var headers = EXPORT_COLUMNS.map(function (col) { return col[1]; });
				var rows = items.map(function (item) {
					return EXPORT_COLUMNS.map(function (col) {
						var key = col[0];
						if (key === "condition") return CONDITION_EXPORT_LABELS[item.condition] || item.condition || "";
						if (key === "tags") return (item.tags || []).join(", ");
						var value = item[key];
						return value === null || typeof value === "undefined" ? "" : value;
					});
				});
				var ws = XLSX.utils.aoa_to_sheet([headers].concat(rows));
				var wb = XLSX.utils.book_new();
				XLSX.utils.book_append_sheet(wb, ws, __("Inventory", "project-prepper"));
				XLSX.writeFile(wb, __("inventory", "project-prepper") + "-" + new Date().toISOString().slice(0, 10) + ".xlsx");
			}).catch(function (e) { toast(e.message, "error"); });
		}

		var toolbar = el("div", { class: "pp-toolbar" }, [search]);
		if (ppConfig.canEdit.importExport) {
			toolbar.appendChild(el("button", { class: "pp-btn", text: __("Export", "project-prepper"), onclick: exportXlsx }));
			toolbar.appendChild(el("button", {
				class: "pp-btn pp-btn-sm", text: __("CSV export", "project-prepper"),
				onclick: function () {
					var params = currentFilterParams();
					fetch(ppConfig.restUrl + "/export" + (params.length ? "?" + params.join("&") : ""), {
						headers: { "X-WP-Nonce": ppConfig.nonce }
					}).then(function (res) {
						if (!res.ok) throw new Error(__("Export failed", "project-prepper"));
						return res.blob();
					}).then(function (blob) {
						var a = el("a", { href: URL.createObjectURL(blob), download: __("inventory", "project-prepper") + "-" + new Date().toISOString().slice(0, 10) + ".csv" });
						a.click();
						URL.revokeObjectURL(a.href);
					}).catch(function (e) { toast(e.message, "error"); });
				}
			}));
			toolbar.appendChild(el("button", { class: "pp-btn", text: __("Import", "project-prepper"), onclick: openImportModal }));
		}

		function openImportModal() {
			var FIELD_OPTIONS = {
				"": __("— ignore —", "project-prepper"), inventory_number: __("Inventory number", "project-prepper"), name: __("Name", "project-prepper"), category: __("Category", "project-prepper"),
				description: __("Description", "project-prepper"), manufacturer: __("Manufacturer", "project-prepper"), model: __("Model", "project-prepper"), serial_number: __("Serial number", "project-prepper"),
				quantity: __("Quantity", "project-prepper"), condition: __("Condition", "project-prepper"), location: __("Location", "project-prepper"), cost_per_day: __("Daily rate", "project-prepper"),
				purchase_price: __("Purchase price", "project-prepper"), purchase_date: __("Purchase date", "project-prepper"), current_value: __("Current value", "project-prepper"),
				dimensions: __("Dimensions", "project-prepper"), power_watts: __("Power (W)", "project-prepper"), accessories: __("Accessories", "project-prepper"), tags: __("Tags", "project-prepper"), notes: __("Notes", "project-prepper")
			};
			var AUTO_MAP = [
				[/inventar|inventory|^nummer$|^number$/i, "inventory_number"], [/^name|bezeichnung|artikel|^item/i, "name"], [/kategorie|category/i, "category"],
				[/beschreibung|description/i, "description"], [/hersteller$|manufacturer$/i, "manufacturer"], [/modell|typ|model|type/i, "model"],
				[/serie|serial/i, "serial_number"], [/menge|anzahl|stück|quantity|qty/i, "quantity"], [/zustand|condition/i, "condition"],
				[/lager|ort|location/i, "location"], [/tagessatz|tagespreis|miete|daily/i, "cost_per_day"], [/kaufpreis|purchase price/i, "purchase_price"],
				[/kaufdatum|purchase date/i, "purchase_date"], [/wert|value/i, "current_value"], [/maße|abmessung|dimension/i, "dimensions"],
				[/leistung|watt|power/i, "power_watts"], [/zubehör|accessor/i, "accessories"], [/tags|schlagwort/i, "tags"],
				[/notiz|bemerkung|note/i, "notes"]
			];

			var body = el("div");
			var fileInput = el("input", {
				type: "file",
				accept: ".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
			});
			body.appendChild(el("div", { class: "pp-field" }, [el("label", { text: __("File (.xlsx, .xls or .csv — first row = headers)", "project-prepper") }), fileInput]));
			var stage = el("div");
			body.appendChild(stage);
			var close = openModal(__("Import inventory", "project-prepper"), body);

			fileInput.addEventListener("change", function () {
				if (!fileInput.files.length) return;
				var file = fileInput.files[0];
				var isExcel = /\.xlsx?$/i.test(file.name);
				var reader = new FileReader();
				reader.onload = function () {
					var rows;
					try {
						rows = isExcel ? parseXlsx(reader.result) : parseCsv(String(reader.result));
					} catch (e) {
						toast(__("Could not read file:", "project-prepper") + " " + e.message, "error");
						return;
					}
					if (rows.length < 2) { toast(__("File contains no data rows.", "project-prepper"), "error"); return; }
					showMapping(rows[0], rows.slice(1));
				};
				if (isExcel) reader.readAsArrayBuffer(file);
				else reader.readAsText(file, "utf-8");
			});

			function showMapping(headers, dataRows) {
				stage.innerHTML = "";
				var selects = headers.map(function (header) {
					var select = el("select", null, Object.keys(FIELD_OPTIONS).map(function (key) {
						return el("option", { value: key, text: FIELD_OPTIONS[key] });
					}));
					var hit = AUTO_MAP.find(function (pair) { return pair[0].test(header); });
					select.value = hit ? hit[1] : "";
					return select;
				});

				var table = el("table", { class: "pp-table" });
				var thead = el("thead");
				thead.appendChild(el("tr", null, headers.map(function (header) { return el("th", { text: header }); })));
				thead.appendChild(el("tr", null, selects.map(function (select) { return el("th", null, [select]); })));
				table.appendChild(thead);
				var tbody = el("tbody");
				dataRows.slice(0, 5).forEach(function (row) {
					tbody.appendChild(el("tr", null, headers.map(function (_, i) { return el("td", { text: row[i] || "" }); })));
				});
				table.appendChild(tbody);
				stage.appendChild(el("div", { class: "pp-modal-section" }, [
					/* translators: %d: number of data rows */
					el("h3", { text: sprintf(__("Map columns (preview: first 5 of %d rows)", "project-prepper"), dataRows.length) }),
					el("div", { class: "pp-import-preview" }, [el("div", { class: "pp-table-wrap" }, [table])])
				]));

				/* translators: %d: number of data rows */
				var importBtn = el("button", { class: "pp-btn pp-btn-primary", text: sprintf(__("Import %d rows", "project-prepper"), dataRows.length) });
				var result = el("div", { class: "pp-import-errors" });
				importBtn.addEventListener("click", function () {
					var mapped = dataRows.map(function (row) {
						var obj = {};
						selects.forEach(function (select, i) {
							if (select.value) obj[select.value] = row[i] || "";
						});
						return obj;
					}).filter(function (obj) { return Object.keys(obj).length; });
					importBtn.disabled = true;
					api("/import", { method: "POST", body: JSON.stringify({ rows: mapped }) }).then(function (res) {
						/* translators: %d: number of imported items */
						var importedMsg = sprintf(__("%d items imported", "project-prepper"), res.created);
						if (res.errors.length) {
							/* translators: %d: number of failed rows */
							importedMsg += ", " + sprintf(__("%d errors", "project-prepper"), res.errors.length);
						}
						toast(importedMsg + ".");
						result.innerHTML = "";
						res.errors.forEach(function (err) {
							/* translators: %d: row number */
							result.appendChild(el("div", { text: sprintf(__("Row %d:", "project-prepper"), err.row) + " " + err.message }));
						});
						loadItems(); loadStats(); loadCategories();
						if (!res.errors.length) close();
					}).catch(function (e) {
						toast(e.message, "error");
						importBtn.disabled = false;
					});
				});
				stage.appendChild(el("div", { class: "pp-row", style: "margin-top:12px" }, [importBtn]));
				stage.appendChild(result);
			}
		}

		// XLSX/XLS → Zeilen-Arrays (SheetJS, erstes Sheet). Datums-Zellen werden als YYYY-MM-DD normalisiert.
		function parseXlsx(arrayBuffer) {
			var wb = XLSX.read(new Uint8Array(arrayBuffer), { type: "array", cellDates: true });
			var ws = wb.Sheets[wb.SheetNames[0]];
			if (!ws) return [];
			var raw = XLSX.utils.sheet_to_json(ws, { header: 1, raw: true, defval: "" });
			function pad2(n) { return n < 10 ? "0" + n : String(n); }
			return raw.map(function (row) {
				return row.map(function (cell) {
					if (cell instanceof Date) {
						return cell.getFullYear() + "-" + pad2(cell.getMonth() + 1) + "-" + pad2(cell.getDate());
					}
					return cell === null || typeof cell === "undefined" ? "" : String(cell);
				});
			}).filter(function (row) {
				return row.some(function (cell) { return cell.trim() !== ""; });
			});
		}

		function parseCsv(text) {
			text = text.replace(/^﻿/, "");
			var firstLine = text.split(/\r?\n/)[0] || "";
			var delim = (firstLine.match(/;/g) || []).length >= (firstLine.match(/,/g) || []).length ? ";" : ",";
			var rows = [], row = [], cell = "", inQuotes = false;
			for (var i = 0; i < text.length; i++) {
				var ch = text[i];
				if (inQuotes) {
					if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++; }
					else if (ch === '"') inQuotes = false;
					else cell += ch;
				} else if (ch === '"') inQuotes = true;
				else if (ch === delim) { row.push(cell); cell = ""; }
				else if (ch === "\n" || ch === "\r") {
					if (ch === "\r" && text[i + 1] === "\n") i++;
					row.push(cell); cell = "";
					if (row.some(function (c) { return c.trim() !== ""; })) rows.push(row);
					row = [];
				} else cell += ch;
			}
			if (cell !== "" || row.length) { row.push(cell); if (row.some(function (c) { return c.trim() !== ""; })) rows.push(row); }
			return rows;
		}

		/* ----- Aufbau ----- */
		root.appendChild(kpiBox);
		if (createCard) root.appendChild(createCard);
		root.appendChild(toolbar);
		root.appendChild(pillBox);
		root.appendChild(listBox);
		search.addEventListener("input", debounce(loadItems, 300));
		loadStats();
		loadItems();

		// Deep-Link aufs Item-Detail (z. B. aus „In Projekten gebucht"): #pp-item-{id}.
		var itemHash = window.location.hash.match(/^#pp-item-(\d+)$/);
		if (itemHash) openItemModal(parseInt(itemHash[1], 10));
	}

	/* ================= Seite: Verleih ================= */

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
		// Status-Flow (bewusst einfach): jeder Wechsel erlaubt außer weg von 'cancelled'.
		var PROJECT_STATUS = { draft: __("Draft", "project-prepper"), planned: __("Planned", "project-prepper"), confirmed: __("Confirmed", "project-prepper"), running: __("Running", "project-prepper"), done: __("Done", "project-prepper"), cancelled: __("Cancelled", "project-prepper") };
		var PROJECT_BADGE = { draft: "draft", planned: "reserved", confirmed: "offer", running: "active", done: "returned", cancelled: "cancelled" };
		var TASK_STATUS = { open: __("Open", "project-prepper"), doing: __("In progress", "project-prepper"), done: __("Done", "project-prepper") };
		var TASK_NEXT = { open: "doing", doing: "done", done: "open" };
		var TASK_PRIORITY = { low: __("Low", "project-prepper"), normal: __("Normal", "project-prepper"), high: __("High", "project-prepper") };
		var COST_CATEGORIES = { personnel: __("Personnel", "project-prepper"), material: __("Material", "project-prepper"), inventory: __("Inventory", "project-prepper"), external: __("External services", "project-prepper"), other: __("Other", "project-prepper") };
		var items = [];
		var groups = []; // Gruppen, die der User sehen darf (für die Gruppen-Auswahl).
		var activeStatus = "";
		var pillBox = el("div", { class: "pp-pills" });
		var listBox = el("div");

		// Gruppen-Select (owner_group_id) — „— site level —" als leerer Default.
		function groupSelect(value) {
			var select = el("select", null, [el("option", { value: "", text: __("— site level —", "project-prepper") })]);
			groups.forEach(function (group) {
				select.appendChild(el("option", { value: group.id, text: group.name }));
			});
			select.value = value ? String(value) : "";
			return select;
		}

		function projectBadge(status) {
			return el("span", { class: "pp-badge pp-badge-" + (PROJECT_BADGE[status] || status), text: PROJECT_STATUS[status] || status });
		}

		function statusSelect(value) {
			var select = el("select", null, Object.keys(PROJECT_STATUS).map(function (key) {
				return el("option", { value: key, text: PROJECT_STATUS[key] });
			}));
			select.value = value || "draft";
			return select;
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
					html: "<tr><th>" + __("Number", "project-prepper") + "</th><th>" + __("Name", "project-prepper") + "</th><th>" + __("Period", "project-prepper") + "</th><th>" + __("Venue", "project-prepper") + "</th><th>" + __("Client", "project-prepper") + "</th><th>" + __("Bookings", "project-prepper") + "</th><th>" + __("Status", "project-prepper") + "</th></tr>"
				}));
				var tbody = el("tbody");
				projects.forEach(function (project) {
					tbody.appendChild(el("tr", { class: "pp-clickable", onclick: function () { openProjectModal(project.id); } }, [
						el("td", null, [el("code", { text: project.project_number })]),
						el("td", { text: project.name }),
						el("td", { text: rangeText(project.date_start, project.date_end) }),
						el("td", { text: project.venue_name || "—" }),
						el("td", { text: project.client_name || "—" }),
						el("td", { text: project.item_count }),
						el("td", null, [projectBadge(project.status)])
					]));
				});
				if (!projects.length) tbody.appendChild(el("tr", { html: '<td colspan="7" class="pp-muted">' + __("No projects yet.", "project-prepper") + "</td>" }));
				table.appendChild(tbody);
				listBox.appendChild(el("div", { class: "pp-table-wrap" }, [table]));
			}).catch(function (e) { toast(e.message, "error"); });
		}

		/* ----- Detail-Modal: Stammdaten + Buchungen + Checklisten + Aufgaben ----- */

		function openProjectModal(projectId) {
			api("/projects/" + projectId).then(function (project) {
				var editable = ppConfig.canEdit.projects;

				function input(type, value) {
					var attrs = { type: type, value: value === null || value === undefined ? "" : value };
					if (!editable) attrs.disabled = "disabled";
					return el("input", attrs);
				}

				var f = {
					name: input("text", project.name),
					start: input("date", project.date_start),
					end: input("date", project.date_end),
					venueName: input("text", project.venue_name),
					venueAddress: el("textarea", { rows: "2" }),
					clientName: input("text", project.client_name),
					clientEmail: input("email", project.client_email),
					clientPhone: input("text", project.client_phone),
					notes: el("textarea", { rows: "2" }),
					budgetPlanned: input("number", project.budget_planned),
					revenueActual: input("number", project.revenue_actual),
					group: groupSelect(project.owner_group_id)
				};
				if (!editable) f.group.disabled = true;
				f.budgetPlanned.setAttribute("step", "0.01");
				f.budgetPlanned.setAttribute("min", "0");
				f.revenueActual.setAttribute("step", "0.01");
				f.revenueActual.setAttribute("min", "0");
				f.venueAddress.value = project.venue_address || "";
				f.notes.value = project.notes || "";
				if (!editable) { f.venueAddress.disabled = true; f.notes.disabled = true; }

				// Status-Zeile: Badge + Wechsel-Buttons (alle außer aktuellem; cancelled = Endstatus).
				var statusRow = el("div", { class: "pp-row" }, [projectBadge(project.status)]);
				if (editable && project.status !== "cancelled") {
					Object.keys(PROJECT_STATUS).forEach(function (key) {
						if (key === project.status) return;
						statusRow.appendChild(el("button", {
							class: "pp-btn pp-btn-sm", text: PROJECT_STATUS[key], type: "button",
							onclick: function () {
								api("/projects/" + projectId + "/status", { method: "POST", body: JSON.stringify({ status: key }) })
									.then(function () { close(); load(); }).catch(function (e) { toast(e.message, "error"); });
							}
						}));
					});
				}

				var info = el("div", { class: "pp-modal-grid" }, [
					field(__("Name *", "project-prepper"), f.name), field(__("From", "project-prepper"), f.start), field(__("To", "project-prepper"), f.end),
					field(__("Venue", "project-prepper"), f.venueName), field(__("Venue address", "project-prepper"), f.venueAddress),
					field(__("Client", "project-prepper"), f.clientName), field(__("Email", "project-prepper"), f.clientEmail), field(__("Phone", "project-prepper"), f.clientPhone),
					field(__("Budget (net)", "project-prepper"), f.budgetPlanned), field(__("Revenue (net)", "project-prepper"), f.revenueActual),
					field(__("Group", "project-prepper"), f.group),
					field(__("Notes", "project-prepper"), f.notes)
				]);

				// Nach Sektions-Mutationen das Projekt neu laden (eine Quelle der Wahrheit).
				function reload(then) {
					api("/projects/" + projectId).then(function (updated) {
						project = updated;
						if (then) then();
					}).catch(function (e) { toast(e.message, "error"); });
				}

				/* --- Buchungen (Equipment) --- */

				var bookingsSection = el("div", { class: "pp-modal-section" });
				function renderBookings() {
					bookingsSection.innerHTML = "";
					bookingsSection.appendChild(el("h3", { text: __("Bookings", "project-prepper") }));
					var list = el("ul", { class: "pp-lines" });
					(project.items || []).forEach(function (line) {
						if (!editable) {
							list.appendChild(el("li", null, [
								el("code", { text: line.inventory_number || "#" + line.item_id }),
								el("span", { text: (line.item_name || "") + " × " + line.quantity }),
								el("span", { class: "pp-muted", text: line.date_from ? rangeText(line.date_from, line.date_to) : __("project period", "project-prepper") })
							]));
							return;
						}
						var qty = el("input", { type: "number", min: "1", value: line.quantity, class: "pp-input-sm", title: __("Quantity", "project-prepper") });
						var from = el("input", { type: "date", value: line.date_from || "", title: __("From", "project-prepper") });
						var to = el("input", { type: "date", value: line.date_to || "", title: __("To", "project-prepper") });
						function saveLine() {
							api("/projects/" + projectId + "/items/" + line.id, {
								method: "PUT",
								body: JSON.stringify({ quantity: parseInt(qty.value, 10) || 1, date_from: from.value, date_to: to.value })
							}).then(function () {
								reload(renderBookings); load();
							}).catch(function (e) {
								toast(e.message, "error");
								reload(renderBookings);
							});
						}
						[qty, from, to].forEach(function (control) { control.addEventListener("change", saveLine); });
						list.appendChild(el("li", null, [
							el("code", { text: line.inventory_number || "#" + line.item_id }),
							el("span", { text: line.item_name || "" }),
							qty, from, to,
							el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/items/" + line.id, { method: "DELETE" })
										.then(function () { reload(renderBookings); load(); })
										.catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					});
					if (!(project.items || []).length) list.appendChild(el("li", { class: "pp-muted", text: __("No bookings.", "project-prepper") }));
					bookingsSection.appendChild(list);

					if (!editable) return;
					var addItem = el("select", { class: "pp-input-lg" });
					addItem.appendChild(el("option", { value: "", text: __("— select item —", "project-prepper") }));
					items.forEach(function (item) {
						addItem.appendChild(el("option", { value: item.id, text: item.inventory_number + " — " + item.name }));
					});
					var addQty = el("input", { type: "number", value: "1", min: "1", class: "pp-input-sm", title: __("Quantity", "project-prepper") });
					var addFrom = el("input", { type: "date", title: __("From", "project-prepper") });
					var addTo = el("input", { type: "date", title: __("To", "project-prepper") });
					var availInfo = el("span");
					// Verfügbarkeit für den effektiven Zeitraum (Zeile, sonst Projekt) anzeigen.
					function checkAvailability() {
						availInfo.textContent = "";
						availInfo.className = "";
						var effFrom = addFrom.value || project.date_start || "";
						var effTo = addTo.value || project.date_end || "";
						if (!addItem.value || !effFrom || !effTo) return;
						api("/items/" + addItem.value + "/availability?from=" + effFrom + "&to=" + effTo).then(function (result) {
							availInfo.textContent = result.available + "× " + __("available", "project-prepper");
							availInfo.className = result.available > 0 ? "pp-avail-ok" : "pp-avail-none";
						}).catch(function () {});
					}
					[addItem, addFrom, addTo].forEach(function (control) { control.addEventListener("change", checkAvailability); });
					bookingsSection.appendChild(el("div", { class: "pp-row" }, [
						addItem, addQty, addFrom, addTo,
						el("button", {
							class: "pp-btn pp-btn-sm", text: __("+ Booking", "project-prepper"), type: "button",
							onclick: function () {
								if (!addItem.value) return;
								api("/projects/" + projectId + "/items", {
									method: "POST",
									body: JSON.stringify({ item_id: parseInt(addItem.value, 10), quantity: parseInt(addQty.value, 10) || 1, date_from: addFrom.value, date_to: addTo.value })
								}).then(function () {
									reload(renderBookings); load();
								}).catch(function (e) { toast(e.message, "error"); });
							}
						}),
						availInfo
					]));
					bookingsSection.appendChild(el("div", { class: "pp-muted", text: __("Without dates the booking inherits the project period.", "project-prepper") }));
				}
				renderBookings();

				/* --- Kosten (Pendant zu tab-costs der App) ---
				   Direkt nach den Buchungen platziert: die finanzielle Dimension der
				   physischen Ressourcen, vor den operativen Sektionen (Zeitplan,
				   Checklisten, Aufgaben). */

				var costsSection = el("div", { class: "pp-modal-section" });
				function renderCosts() {
					costsSection.innerHTML = "";
					costsSection.appendChild(el("h3", { text: __("Costs", "project-prepper") }));

					var table = el("table", { class: "pp-table" });
					table.appendChild(el("thead", {
						html: "<tr><th>" + __("Category", "project-prepper") + "</th><th>" + __("Description", "project-prepper") + "</th><th>" + __("Planned (net)", "project-prepper") + "</th><th>" + __("Actual (net)", "project-prepper") + "</th><th>" + __("VAT %", "project-prepper") + "</th><th>" + __("Not in profit", "project-prepper") + "</th><th></th></tr>"
					}));
					var tbody = el("tbody");
					(project.cost_items || []).forEach(function (cost) {
						tbody.appendChild(el("tr", null, [
							el("td", { text: COST_CATEGORIES[cost.category] || cost.category }),
							el("td", { text: cost.description || "—" }),
							el("td", { class: "pp-num", text: money(cost.amount_planned) }),
							el("td", { class: "pp-num", text: cost.amount_actual === null ? "—" : money(cost.amount_actual) }),
							el("td", { class: "pp-num", text: Number(cost.vat_rate).toLocaleString("de-DE", { maximumFractionDigits: 2 }) + " %" }),
							el("td", { text: Number(cost.exclude_from_profit) ? "✓" : "" }),
							editable ? el("td", null, [el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/costs/" + cost.id, { method: "DELETE" })
										.then(function (updated) { project = updated; renderCosts(); }).catch(function (e) { toast(e.message, "error"); });
								}
							})]) : el("td")
						]));
					});
					if (!(project.cost_items || []).length) tbody.appendChild(el("tr", { html: '<td colspan="7" class="pp-muted">' + __("No cost items.", "project-prepper") + "</td>" }));
					table.appendChild(tbody);
					costsSection.appendChild(el("div", { class: "pp-table-wrap" }, [table]));

					if (editable) {
						var cat = el("select", null, Object.keys(COST_CATEGORIES).map(function (key) {
							return el("option", { value: key, text: COST_CATEGORIES[key] });
						}));
						cat.value = "material";
						var desc = el("input", { type: "text", placeholder: __("Description", "project-prepper"), class: "pp-input-md" });
						var planned = el("input", { type: "number", step: "0.01", min: "0", placeholder: __("Planned (net)", "project-prepper"), class: "pp-input-sm" });
						var actual = el("input", { type: "number", step: "0.01", min: "0", placeholder: __("Actual (net)", "project-prepper"), class: "pp-input-sm" });
						var vat = el("input", { type: "number", step: "0.01", min: "0", value: "19", placeholder: __("VAT %", "project-prepper"), class: "pp-input-sm" });
						var excl = el("input", { type: "checkbox" });
						costsSection.appendChild(el("div", { class: "pp-row" }, [
							cat, desc, planned, actual, vat,
							el("label", { class: "pp-check" }, [excl, el("span", { text: __("Not in profit", "project-prepper") })]),
							el("button", {
								class: "pp-btn pp-btn-sm", text: __("+ Cost item", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/costs", {
										method: "POST",
										body: JSON.stringify({
											category: cat.value, description: desc.value.trim(),
											amount_planned: planned.value, amount_actual: actual.value,
											vat_rate: vat.value, exclude_from_profit: excl.checked
										})
									}).then(function (updated) {
										project = updated;
										desc.value = ""; planned.value = ""; actual.value = ""; vat.value = "19"; excl.checked = false; cat.value = "material";
										renderCosts();
									}).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					}

					// Summen-Block aus cost_summary.
					var s = project.cost_summary || {};
					var sums = el("div", { class: "pp-cost-summary" });
					function sumRow(label, value, extraClass) {
						return el("div", { class: "pp-cost-sum-row" + (extraClass ? " " + extraClass : "") }, [
							el("span", { text: label }), el("span", { class: "pp-num", text: value })
						]);
					}
					sums.appendChild(sumRow(__("Planned net", "project-prepper"), money(s.planned_net)));
					sums.appendChild(sumRow(__("Planned gross", "project-prepper"), money(s.planned_gross)));
					sums.appendChild(sumRow(__("Actual net", "project-prepper"), money(s.actual_net)));
					sums.appendChild(sumRow(__("Actual gross", "project-prepper"), money(s.actual_gross)));
					if (s.budget_planned !== null && s.budget_planned !== undefined) {
						sums.appendChild(sumRow(__("Budget", "project-prepper"), money(s.budget_planned)));
						// Abweichung: negativ = Budget überschritten (Geplant > Budget) → rot.
						var overBudget = s.budget_variance < 0;
						sums.appendChild(sumRow(__("Variance", "project-prepper"), money(s.budget_variance), overBudget ? "pp-cost-over" : ""));
					}
					if (s.revenue_actual !== null && s.revenue_actual !== undefined) {
						sums.appendChild(sumRow(__("Revenue", "project-prepper"), money(s.revenue_actual)));
						sums.appendChild(sumRow(__("Profit", "project-prepper"), money(s.profit), s.profit < 0 ? "pp-cost-over" : ""));
					}
					costsSection.appendChild(sums);
				}
				renderCosts();

				// Budget/Umsatz live speichern und Summen aktualisieren (nicht nur beim Save).
				if (editable) {
					[f.budgetPlanned, f.revenueActual].forEach(function (control) {
						control.addEventListener("change", function () {
							api("/projects/" + projectId, {
								method: "PUT",
								body: JSON.stringify({ budget_planned: f.budgetPlanned.value, revenue_actual: f.revenueActual.value })
							}).then(function (updated) { project = updated; renderCosts(); renderProfitShares(); }).catch(function (e) { toast(e.message, "error"); });
						});
					});
				}

				/* --- Gewinnverteilung (Gruppen-Phase 4, Pendant zu tab-profit der App) ---
				   Direkt nach den Kosten platziert: die Verteilung baut auf dem Gewinn-
				   Pool aus den Kosten auf (Costs::summary.profit). Anteile je Mitglied der
				   besitzenden Gruppe als Prozent (auf den Pool) oder fester Betrag. Site-
				   Projekte (ohne Gruppe) zeigen nur einen Hinweis; ohne Umsatz steht der
				   Pool nicht fest -> berechnete Beträge bei Prozent-Zeilen sind "—". */

				var SHARE_TYPES = { percentage: __("Percentage", "project-prepper"), fixed: __("Fixed", "project-prepper") };
				var profitSection = el("div", { class: "pp-modal-section" });
				var profitGroupCache = null;
				function renderProfitShares() {
					profitSection.innerHTML = "";
					profitSection.appendChild(el("h3", { text: __("Profit sharing", "project-prepper") }));

					// Kein Gruppen-Projekt -> Hinweis, keine Verteilung.
					if (!project.owner_group_id) {
						profitSection.appendChild(el("p", { class: "pp-muted", text: __("Assign a group to distribute profit.", "project-prepper") }));
						return;
					}

					var s = project.profit_summary || {};
					var poolKnown = s.pool !== null && s.pool !== undefined;

					// Gewinn-Pool oben.
					if (poolKnown) {
						profitSection.appendChild(el("div", { class: "pp-cost-summary" }, [
							el("div", { class: "pp-cost-sum-row" }, [
								el("span", { text: __("Profit pool", "project-prepper") }),
								el("span", { class: "pp-num", text: money(s.pool) })
							])
						]));
					} else {
						profitSection.appendChild(el("p", { class: "pp-muted", text: __("Set project revenue to calculate amounts.", "project-prepper") }));
					}

					var table = el("table", { class: "pp-table" });
					table.appendChild(el("thead", {
						html: "<tr><th>" + __("Participant", "project-prepper") + "</th><th>" + __("Type", "project-prepper") + "</th><th>" + __("Share", "project-prepper") + "</th><th>" + __("Calculated", "project-prepper") + "</th><th></th></tr>"
					}));
					var tbody = el("tbody");
					(project.profit_shares || []).forEach(function (sh) {
						var name = sh.missing ? __("(removed user)", "project-prepper") : sh.display_name;
						var shareText = sh.share_type === "percentage"
							? Number(sh.share_value).toLocaleString("de-DE", { maximumFractionDigits: 2 }) + " %"
							: money(sh.share_value);
						var calcText = sh.calculated_amount === null || sh.calculated_amount === undefined ? "—" : money(sh.calculated_amount);
						tbody.appendChild(el("tr", null, [
							el("td", { text: name }),
							el("td", { text: SHARE_TYPES[sh.share_type] || sh.share_type }),
							el("td", { class: "pp-num", text: shareText }),
							el("td", { class: "pp-num", text: calcText }),
							editable ? el("td", null, [el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/profit-shares/" + sh.id, { method: "DELETE" })
										.then(function (updated) { project = updated; profitGroupCache = null; renderProfitShares(); }).catch(function (e) { toast(e.message, "error"); });
								}
							})]) : el("td")
						]));
					});
					if (!(project.profit_shares || []).length) tbody.appendChild(el("tr", { html: '<td colspan="5" class="pp-muted">' + __("No profit shares yet.", "project-prepper") + "</td>" }));
					table.appendChild(tbody);
					profitSection.appendChild(el("div", { class: "pp-table-wrap" }, [table]));

					// Summen-Block: zugeteilt gesamt / nicht zugeteilt; Über-Verteilung rot.
					var sums = el("div", { class: "pp-cost-summary" });
					function sumRow(label, value, extraClass) {
						return el("div", { class: "pp-cost-sum-row" + (extraClass ? " " + extraClass : "") }, [
							el("span", { text: label }), el("span", { class: "pp-num", text: value })
						]);
					}
					sums.appendChild(sumRow(__("Allocated", "project-prepper"), money(s.total_allocated), s.over_allocated ? "pp-cost-over" : ""));
					if (poolKnown) {
						sums.appendChild(sumRow(__("Unallocated", "project-prepper"), money(s.unallocated), s.unallocated < 0 ? "pp-cost-over" : ""));
						if (s.over_allocated) {
							sums.appendChild(el("div", { class: "pp-cost-sum-row pp-cost-over" }, [
								el("span", { text: __("Over-allocated", "project-prepper") }), el("span")
							]));
						}
					}
					profitSection.appendChild(sums);

					if (!editable) return;

					// Anlege-Zeile: Gruppenmitglieder (noch nicht zugeteilt) + Typ + Wert.
					function buildAddRow(groupMembers) {
						var assignedIds = (project.profit_shares || []).map(function (sh) { return sh.user_id; });
						var available = (groupMembers || []).filter(function (gm) { return assignedIds.indexOf(gm.user_id) === -1; });
						if (!available.length) {
							profitSection.appendChild(el("p", { class: "pp-muted", text: __("All group members already have a share.", "project-prepper") }));
							return;
						}
						var userSelect = el("select", { class: "pp-input-lg" });
						userSelect.appendChild(el("option", { value: "", text: __("— select group member —", "project-prepper") }));
						available.forEach(function (gm) {
							userSelect.appendChild(el("option", { value: gm.user_id, text: gm.display_name + (gm.user_email ? " (" + gm.user_email + ")" : "") }));
						});
						var typeSelect = el("select", null, Object.keys(SHARE_TYPES).map(function (key) {
							return el("option", { value: key, text: SHARE_TYPES[key] });
						}));
						typeSelect.value = "percentage";
						var valueInput = el("input", { type: "number", step: "0.01", min: "0", placeholder: __("Value", "project-prepper"), class: "pp-input-sm" });
						profitSection.appendChild(el("div", { class: "pp-row" }, [
							userSelect, typeSelect, valueInput,
							el("button", {
								class: "pp-btn pp-btn-sm", text: __("Add share", "project-prepper"), type: "button",
								onclick: function () {
									if (!userSelect.value) return;
									api("/projects/" + projectId + "/profit-shares", {
										method: "POST",
										body: JSON.stringify({ user_id: parseInt(userSelect.value, 10), share_type: typeSelect.value, share_value: valueInput.value })
									}).then(function (updated) {
										project = updated; profitGroupCache = null; valueInput.value = ""; typeSelect.value = "percentage";
										renderProfitShares();
									}).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					}

					if (profitGroupCache) {
						buildAddRow(profitGroupCache);
					} else {
						api("/groups/" + project.owner_group_id).then(function (group) {
							profitGroupCache = group.members || [];
							buildAddRow(profitGroupCache);
						}).catch(function () {
							// Gruppe nicht ladbar (z. B. keine Berechtigung) -> kein Formular.
						});
					}
				}
				renderProfitShares();

				/* --- Material / Verbrauchsmaterial (Pendant zu tab-materials der App) ---
				   Direkt nach den Kosten platziert: ebenfalls eine finanzielle Liste
				   (Material mit Mengen + Kosten), vor den operativen Sektionen. */

				var consumablesSection = el("div", { class: "pp-modal-section" });
				function renderConsumables() {
					consumablesSection.innerHTML = "";
					consumablesSection.appendChild(el("h3", { text: __("Materials", "project-prepper") }));

					var table = el("table", { class: "pp-table" });
					table.appendChild(el("thead", {
						html: "<tr><th>" + __("Name", "project-prepper") + "</th><th>" + __("Quantity", "project-prepper") + "</th><th>" + __("Unit", "project-prepper") + "</th><th>" + __("Cost", "project-prepper") + "</th><th></th></tr>"
					}));
					var tbody = el("tbody");
					var totalCost = 0;
					(project.consumables || []).forEach(function (c) {
						if (c.cost !== null && c.cost !== undefined && c.cost !== "") totalCost += Number(c.cost);
						tbody.appendChild(el("tr", null, [
							el("td", { text: c.name }),
							el("td", { class: "pp-num", text: Number(c.quantity).toLocaleString("de-DE", { maximumFractionDigits: 2 }) }),
							el("td", { text: c.unit || "—" }),
							el("td", { class: "pp-num", text: c.cost === null || c.cost === undefined || c.cost === "" ? "—" : money(c.cost) }),
							editable ? el("td", null, [el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/consumables/" + c.id, { method: "DELETE" })
										.then(function () { reload(renderConsumables); }).catch(function (e) { toast(e.message, "error"); });
								}
							})]) : el("td")
						]));
					});
					if (!(project.consumables || []).length) tbody.appendChild(el("tr", { html: '<td colspan="5" class="pp-muted">' + __("No materials.", "project-prepper") + "</td>" }));
					table.appendChild(tbody);
					consumablesSection.appendChild(el("div", { class: "pp-table-wrap" }, [table]));

					if ((project.consumables || []).length) {
						var sums = el("div", { class: "pp-cost-summary" }, [
							el("div", { class: "pp-cost-sum-row" }, [
								el("span", { text: __("Total material cost", "project-prepper") }),
								el("span", { class: "pp-num", text: money(totalCost) })
							])
						]);
						consumablesSection.appendChild(sums);
					}

					if (!editable) return;
					var cName = el("input", { type: "text", placeholder: __("Name", "project-prepper"), class: "pp-input-md" });
					var cQty = el("input", { type: "number", step: "0.01", min: "0", value: "1", placeholder: __("Quantity", "project-prepper"), class: "pp-input-sm" });
					var cUnit = el("input", { type: "text", placeholder: __("Unit", "project-prepper"), class: "pp-input-sm" });
					var cCost = el("input", { type: "number", step: "0.01", min: "0", placeholder: __("Cost", "project-prepper"), class: "pp-input-sm" });
					consumablesSection.appendChild(el("div", { class: "pp-row" }, [
						cName, cQty, cUnit, cCost,
						el("button", {
							class: "pp-btn pp-btn-sm", text: __("+ Material", "project-prepper"), type: "button",
							onclick: function () {
								if (!cName.value.trim()) return;
								api("/projects/" + projectId + "/consumables", {
									method: "POST",
									body: JSON.stringify({ name: cName.value.trim(), quantity: cQty.value, unit: cUnit.value.trim(), cost: cCost.value })
								}).then(function () {
									cName.value = ""; cQty.value = "1"; cUnit.value = ""; cCost.value = "";
									reload(renderConsumables);
								}).catch(function (e) { toast(e.message, "error"); });
							}
						})
					]));
				}
				renderConsumables();

				/* --- Dateien (Pendant zu tab-files der App) ---
				   Direkt nach dem Material platziert: begleitende Dokumente
				   (Grundrisse, Angebote, Pläne) zur Material-/Kostenplanung, vor
				   den operativen Sektionen. Dateien hängen über die WP-Medien-
				   bibliothek am Projekt (wp.media-Frame), nicht als Freitext. */

				var filesSection = el("div", { class: "pp-modal-section" });
				function renderFiles() {
					filesSection.innerHTML = "";
					filesSection.appendChild(el("h3", { text: __("Files", "project-prepper") }));
					var list = el("ul", { class: "pp-lines" });
					(project.files || []).forEach(function (f) {
						var label;
						if (f.missing) {
							label = el("span", { class: "pp-muted", text: (f.title || f.filename || "#" + f.attachment_id) + " " + __("(file missing)", "project-prepper") });
						} else {
							label = el("a", { href: f.url, target: "_blank", rel: "noopener", text: f.title || f.filename || f.url });
						}
						var meta = el("span", { class: "pp-muted", text: f.mime || "" });
						if (!editable) {
							list.appendChild(el("li", null, [label, meta]));
							return;
						}
						list.appendChild(el("li", null, [
							label, meta,
							el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/files/" + f.id, { method: "DELETE" })
										.then(function () { reload(renderFiles); }).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					});
					if (!(project.files || []).length) list.appendChild(el("li", { class: "pp-muted", text: __("No files.", "project-prepper") }));
					filesSection.appendChild(list);

					if (!editable) return;
					filesSection.appendChild(el("div", { class: "pp-row" }, [
						el("button", {
							class: "pp-btn pp-btn-sm", text: __("Add file", "project-prepper"), type: "button",
							onclick: function () {
								if (typeof wp === "undefined" || !wp.media) {
									toast(__("Media library is not available.", "project-prepper"), "error");
									return;
								}
								var frame = wp.media({
									title: __("Select files", "project-prepper"),
									multiple: true,
									library: {},
									button: { text: __("Add file", "project-prepper") }
								});
								frame.on("select", function () {
									var selection = frame.state().get("selection").toJSON();
									// Je gewähltem Attachment eine Verknüpfung anlegen (sequentiell),
									// danach EINMAL neu laden.
									var chain = Promise.resolve();
									selection.forEach(function (att) {
										chain = chain.then(function () {
											return api("/projects/" + projectId + "/files", {
												method: "POST",
												body: JSON.stringify({ attachment_id: att.id })
											});
										}).catch(function (e) { toast(e.message, "error"); });
									});
									chain.then(function () { reload(renderFiles); });
								});
								frame.open();
							}
						})
					]));
				}
				renderFiles();

				/* --- Zeitplan --- */

				var scheduleSection = el("div", { class: "pp-modal-section" });
				function renderSchedule() {
					scheduleSection.innerHTML = "";
					scheduleSection.appendChild(el("h3", { text: __("Schedule", "project-prepper") }));
					var schedList = el("ul", { class: "pp-lines" });
					(project.schedule || []).forEach(function (entry) {
						var meta = el("span", { class: "pp-muted", text: dateDe(entry.schedule_date) + timeRange(entry.time_start, entry.time_end) });
						var label = el("span", { text: entry.title + (entry.location ? " · " + entry.location : "") });
						if (!editable) {
							schedList.appendChild(el("li", null, [meta, label]));
							return;
						}
						schedList.appendChild(el("li", null, [
							meta, label,
							el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/schedule/" + entry.id, { method: "DELETE" })
										.then(function () { reload(renderSchedule); }).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					});
					if (!(project.schedule || []).length) schedList.appendChild(el("li", { class: "pp-muted", text: __("No schedule entries.", "project-prepper") }));
					scheduleSection.appendChild(schedList);

					if (!editable) return;
					var schedDate = el("input", { type: "date", title: __("Date", "project-prepper") });
					var schedStart = el("input", { type: "time", title: __("From", "project-prepper") });
					var schedEnd = el("input", { type: "time", title: __("To", "project-prepper") });
					var schedTitle = el("input", { type: "text", placeholder: __("Title", "project-prepper"), class: "pp-input-md" });
					var schedLocation = el("input", { type: "text", placeholder: __("Location", "project-prepper"), class: "pp-input-md" });
					scheduleSection.appendChild(el("div", { class: "pp-row" }, [
						schedDate, schedStart, schedEnd, schedTitle, schedLocation,
						el("button", {
							class: "pp-btn pp-btn-sm", text: __("+ Entry", "project-prepper"), type: "button",
							onclick: function () {
								if (!schedTitle.value.trim()) return;
								api("/projects/" + projectId + "/schedule", {
									method: "POST",
									body: JSON.stringify({
										schedule_date: schedDate.value, time_start: schedStart.value, time_end: schedEnd.value,
										title: schedTitle.value.trim(), location: schedLocation.value.trim()
									})
								}).then(function () {
									schedDate.value = ""; schedStart.value = ""; schedEnd.value = ""; schedTitle.value = ""; schedLocation.value = "";
									reload(renderSchedule);
								}).catch(function (e) { toast(e.message, "error"); });
							}
						})
					]));
				}
				renderSchedule();

				/* --- Checklisten --- */

				var checklistsSection = el("div", { class: "pp-modal-section" });
				function renderChecklists() {
					checklistsSection.innerHTML = "";
					checklistsSection.appendChild(el("h3", { text: __("Checklists", "project-prepper") }));
					(project.checklists || []).forEach(function (checklist) {
						var header = el("div", { class: "pp-row" });
						if (editable) {
							var nameInput = el("input", { type: "text", value: checklist.name, class: "pp-input-md" });
							nameInput.addEventListener("change", function () {
								api("/checklists/" + checklist.id, { method: "PUT", body: JSON.stringify({ name: nameInput.value }) })
									.then(function () { reload(null); }).catch(function (e) { toast(e.message, "error"); });
							});
							header.appendChild(nameInput);
							header.appendChild(el("button", {
								class: "pp-link pp-link-danger", text: __("delete", "project-prepper"), type: "button",
								onclick: function () {
									/* translators: %s: checklist name */
									if (!confirm(sprintf(__('Delete checklist "%s"?', "project-prepper"), checklist.name))) return;
									api("/checklists/" + checklist.id, { method: "DELETE" })
										.then(function () { reload(renderChecklists); }).catch(function (e) { toast(e.message, "error"); });
								}
							}));
						} else {
							header.appendChild(el("strong", { text: checklist.name }));
						}
						checklistsSection.appendChild(header);

						var list = el("ul", { class: "pp-lines" });
						(checklist.items || []).forEach(function (item) {
							var checkbox = el("input", { type: "checkbox" });
							checkbox.checked = !!parseInt(item.is_checked, 10);
							if (!editable) checkbox.disabled = true;
							checkbox.addEventListener("change", function () {
								api("/checklist-items/" + item.id, { method: "PUT", body: JSON.stringify({ is_checked: checkbox.checked }) })
									.then(function () { reload(null); }).catch(function (e) { toast(e.message, "error"); });
							});
							var entry = el("li", null, [el("label", { class: "pp-toggle" }, [checkbox, el("span", { text: item.label })])]);
							if (editable) {
								entry.appendChild(el("button", {
									class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
									onclick: function () {
										api("/checklist-items/" + item.id, { method: "DELETE" })
											.then(function () { reload(renderChecklists); }).catch(function (e) { toast(e.message, "error"); });
									}
								}));
							}
							list.appendChild(entry);
						});
						if (!(checklist.items || []).length) list.appendChild(el("li", { class: "pp-muted", text: __("No entries.", "project-prepper") }));
						checklistsSection.appendChild(list);

						if (editable) {
							var newLabel = el("input", { type: "text", placeholder: __("New entry", "project-prepper"), class: "pp-input-md" });
							checklistsSection.appendChild(el("div", { class: "pp-row" }, [
								newLabel,
								el("button", {
									class: "pp-btn pp-btn-sm", text: __("+ Entry", "project-prepper"), type: "button",
									onclick: function () {
										if (!newLabel.value.trim()) return;
										api("/checklists/" + checklist.id + "/items", { method: "POST", body: JSON.stringify({ label: newLabel.value.trim() }) })
											.then(function () { reload(renderChecklists); }).catch(function (e) { toast(e.message, "error"); });
									}
								})
							]));
						}
					});
					if (!(project.checklists || []).length) {
						checklistsSection.appendChild(el("div", { class: "pp-muted", text: __("No checklists.", "project-prepper") }));
					}
					if (editable) {
						var newName = el("input", { type: "text", placeholder: __("Checklist name", "project-prepper"), class: "pp-input-md" });
						checklistsSection.appendChild(el("div", { class: "pp-row", style: "margin-top:8px" }, [
							newName,
							el("button", {
								class: "pp-btn pp-btn-sm", text: __("+ Checklist", "project-prepper"), type: "button",
								onclick: function () {
									if (!newName.value.trim()) return;
									api("/projects/" + projectId + "/checklists", { method: "POST", body: JSON.stringify({ name: newName.value.trim() }) })
										.then(function () { reload(renderChecklists); }).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					}
				}
				renderChecklists();

				/* --- Aufgaben --- */

				var tasksSection = el("div", { class: "pp-modal-section" });
				function renderTasks() {
					tasksSection.innerHTML = "";
					tasksSection.appendChild(el("h3", { text: __("Tasks", "project-prepper") }));
					var list = el("ul", { class: "pp-lines" });
					(project.tasks || []).forEach(function (task) {
						if (!editable) {
							list.appendChild(el("li", null, [
								el("span", { class: "pp-badge pp-badge-" + (task.task_status === "done" ? "returned" : task.task_status === "doing" ? "active" : "reserved"), text: TASK_STATUS[task.task_status] || task.task_status }),
								el("span", { text: task.title }),
								el("span", { class: "pp-muted", text: TASK_PRIORITY[task.priority] + (task.due_date ? " · " + dateDe(task.due_date) : "") })
							]));
							return;
						}
						// Status-Toggle open→doing→done (→open).
						var statusBtn = el("button", {
							class: "pp-btn pp-btn-sm", text: TASK_STATUS[task.task_status] || task.task_status, type: "button",
							title: __("Change status", "project-prepper"),
							onclick: function () {
								api("/tasks/" + task.id, { method: "PUT", body: JSON.stringify({ task_status: TASK_NEXT[task.task_status] || "open" }) })
									.then(function () { reload(renderTasks); }).catch(function (e) { toast(e.message, "error"); });
							}
						});
						var prio = el("select", { title: __("Priority", "project-prepper") }, Object.keys(TASK_PRIORITY).map(function (key) {
							return el("option", { value: key, text: TASK_PRIORITY[key] });
						}));
						prio.value = task.priority || "normal";
						prio.addEventListener("change", function () {
							api("/tasks/" + task.id, { method: "PUT", body: JSON.stringify({ priority: prio.value }) })
								.then(function () { reload(null); }).catch(function (e) { toast(e.message, "error"); });
						});
						var due = el("input", { type: "date", value: task.due_date || "", title: __("Due date", "project-prepper") });
						due.addEventListener("change", function () {
							api("/tasks/" + task.id, { method: "PUT", body: JSON.stringify({ due_date: due.value }) })
								.then(function () { reload(null); }).catch(function (e) { toast(e.message, "error"); });
						});
						list.appendChild(el("li", null, [
							statusBtn,
							el("span", { text: task.title }),
							prio, due,
							el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/tasks/" + task.id, { method: "DELETE" })
										.then(function () { reload(renderTasks); }).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					});
					if (!(project.tasks || []).length) list.appendChild(el("li", { class: "pp-muted", text: __("No tasks.", "project-prepper") }));
					tasksSection.appendChild(list);

					if (!editable) return;
					var newTitle = el("input", { type: "text", placeholder: __("Task title", "project-prepper"), class: "pp-input-md" });
					var newPrio = el("select", { title: __("Priority", "project-prepper") }, Object.keys(TASK_PRIORITY).map(function (key) {
						return el("option", { value: key, text: TASK_PRIORITY[key] });
					}));
					newPrio.value = "normal";
					var newDue = el("input", { type: "date", title: __("Due date", "project-prepper") });
					tasksSection.appendChild(el("div", { class: "pp-row" }, [
						newTitle, newPrio, newDue,
						el("button", {
							class: "pp-btn pp-btn-sm", text: __("+ Task", "project-prepper"), type: "button",
							onclick: function () {
								if (!newTitle.value.trim()) return;
								api("/projects/" + projectId + "/tasks", {
									method: "POST",
									body: JSON.stringify({ title: newTitle.value.trim(), priority: newPrio.value, due_date: newDue.value })
								}).then(function () {
									newTitle.value = ""; newDue.value = ""; newPrio.value = "normal";
									reload(renderTasks);
								}).catch(function (e) { toast(e.message, "error"); });
							}
						})
					]));
				}
				renderTasks();

				/* --- Beteiligte (Gruppen-Phase 2, Pendant zu project-members-panel der App) ---
				   Roster der am Projekt beteiligten WP-Benutzer AUS der besitzenden
				   Gruppe + freie Rolle. Rein dokumentarisch (gewaehrt keine Rechte —
				   Zugriff bleibt gruppen-basiert). Site-Projekte (ohne Gruppe) zeigen
				   nur einen Hinweis. Eigene Sektion neben „Team & Kontakte“: jene ist
				   Freitext-Roster, diese der Gruppen-Beteiligten-Roster. */

				var membersSection = el("div", { class: "pp-modal-section" });
				// Gruppenmitglieder fuers Select werden lazy einmal geladen + gecacht.
				var groupMembersCache = null;
				function renderProjectMembers() {
					membersSection.innerHTML = "";
					membersSection.appendChild(el("h3", { text: __("Project members", "project-prepper") }));

					// Kein Gruppen-Projekt -> Hinweis, kein Roster/Formular.
					if (!project.owner_group_id) {
						membersSection.appendChild(el("p", { class: "pp-muted", text: __("Assign a group to add members.", "project-prepper") }));
						return;
					}

					var list = el("ul", { class: "pp-lines" });
					(project.members || []).forEach(function (m) {
						var name = m.missing ? __("(removed user)", "project-prepper") : m.display_name;
						var bits = [];
						if (m.role_title) bits.push(m.role_title);
						if (m.user_email && !m.missing) bits.push(m.user_email);
						if (m.note) bits.push(m.note);
						var meta = el("span", { class: "pp-muted", text: bits.join(" · ") });
						if (!editable) {
							list.appendChild(el("li", null, [el("span", { text: name }), meta]));
							return;
						}
						list.appendChild(el("li", null, [
							el("span", { text: name }), meta,
							el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/members/" + m.id, { method: "DELETE" })
										.then(function () { reload(renderProjectMembers); }).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					});
					if (!(project.members || []).length) list.appendChild(el("li", { class: "pp-muted", text: __("No members yet.", "project-prepper") }));
					membersSection.appendChild(list);

					if (!editable) return;

					// Anlege-Zeile: Select der Gruppenmitglieder, die noch NICHT im Roster
					// sind, + freies Rollen-Feld. Gruppenmitglieder aus /groups/{owner_group_id}
					// (fuer Gruppenmitglieder erlaubt), Roster-Mitglieder herausgefiltert.
					function buildAddRow(groupMembers) {
						var rosterIds = (project.members || []).map(function (m) { return m.user_id; });
						var available = (groupMembers || []).filter(function (gm) { return rosterIds.indexOf(gm.user_id) === -1; });
						if (!available.length) {
							membersSection.appendChild(el("p", { class: "pp-muted", text: __("All group members are already listed.", "project-prepper") }));
							return;
						}
						var userSelect = el("select", { class: "pp-input-lg" });
						userSelect.appendChild(el("option", { value: "", text: __("— select group member —", "project-prepper") }));
						available.forEach(function (gm) {
							userSelect.appendChild(el("option", { value: gm.user_id, text: gm.display_name + (gm.user_email ? " (" + gm.user_email + ")" : "") }));
						});
						var roleInput = el("input", { type: "text", placeholder: __("Role", "project-prepper"), class: "pp-input-md" });
						membersSection.appendChild(el("div", { class: "pp-row" }, [
							userSelect, roleInput,
							el("button", {
								class: "pp-btn pp-btn-sm", text: __("Add member", "project-prepper"), type: "button",
								onclick: function () {
									if (!userSelect.value) return;
									api("/projects/" + projectId + "/members", {
										method: "POST",
										body: JSON.stringify({ user_id: parseInt(userSelect.value, 10), role_title: roleInput.value.trim() })
									}).then(function () {
										groupMembersCache = null; // Roster aenderte sich -> Select neu aufbauen.
										reload(renderProjectMembers);
									}).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					}

					if (groupMembersCache) {
						buildAddRow(groupMembersCache);
					} else {
						api("/groups/" + project.owner_group_id).then(function (group) {
							groupMembersCache = group.members || [];
							buildAddRow(groupMembersCache);
						}).catch(function () {
							// Gruppe nicht ladbar (z. B. keine Berechtigung) -> kein Formular.
						});
					}
				}
				renderProjectMembers();

				/* --- Beschlüsse (Gruppen-Phase 3, Pendant zu tab-polls / org_decisions
				   der App) --- Abstimmungen unter den aktiven Mitgliedern der
				   besitzenden Gruppe: Zustimmen/Ablehnen/Enthalten, Mehrheits- oder
				   Einstimmigkeits-Auflösung. Governance der Beteiligten, daher
				   direkt nach „Beteiligte". Site-Projekte (ohne Gruppe) zeigen nur
				   einen Hinweis. Sichtbarkeit „darf ich abstimmen" + meine Stimme
				   kommen aus dem Backend (can_vote/my_vote), nicht aus Frontend-User-
				   Logik. */

				var DECISION_STATUS = { open: __("Open", "project-prepper"), approved: __("Approved", "project-prepper"), rejected: __("Rejected", "project-prepper"), cancelled: __("Cancelled", "project-prepper") };
				var DECISION_BADGE = { open: "reserved", approved: "returned", rejected: "cancelled", cancelled: "draft" };
				var VOTE_LABELS = { approve: __("Approve", "project-prepper"), reject: __("Reject", "project-prepper"), abstain: __("Abstain", "project-prepper") };

				var decisionsSection = el("div", { class: "pp-modal-section" });
				function renderDecisions() {
					decisionsSection.innerHTML = "";
					decisionsSection.appendChild(el("h3", { text: __("Decisions", "project-prepper") }));

					// Kein Gruppen-Projekt -> Hinweis, keine Beschlüsse/Abstimmung.
					if (!project.owner_group_id) {
						decisionsSection.appendChild(el("p", { class: "pp-muted", text: __("Assign a group to hold votes.", "project-prepper") }));
						return;
					}

					var list = el("ul", { class: "pp-lines pp-lines-block" });
					(project.decisions || []).forEach(function (d) {
						var row = el("li", { class: "pp-decision" });

						// Kopfzeile: Titel + Status-Badge + Modus-Hinweis.
						var head = el("div", { class: "pp-decision-head" }, [
							el("strong", { text: d.title }),
							el("span", { class: "pp-badge pp-badge-" + (DECISION_BADGE[d.status] || d.status), text: DECISION_STATUS[d.status] || d.status }),
							el("span", { class: "pp-muted", text: d.requires_unanimous ? __("Unanimous", "project-prepper") : __("Majority", "project-prepper") })
						]);
						row.appendChild(head);

						if (d.description) row.appendChild(el("div", { class: "pp-muted", text: d.description }));

						// Tally „✓ n · ✗ n · ○ n von m" (Text, keine Icons).
						/* translators: 1: approvals, 2: rejections, 3: abstentions, 4: total eligible */
						var tallyTpl = __("Approve %1$d · Reject %2$d · Abstain %3$d of %4$d", "project-prepper");
						row.appendChild(el("div", { class: "pp-decision-tally", text:
							sprintf(tallyTpl, d.approve_count, d.reject_count, d.abstain_count, d.total_active) }));

						// Vote-Buttons nur für offene Beschlüsse + stimmberechtigte
						// Gruppenmitglieder (Backend liefert can_vote).
						if (d.status === "open" && d.can_vote) {
							var voteRow = el("div", { class: "pp-row pp-decision-votes" });
							["approve", "reject", "abstain"].forEach(function (v) {
								var isMine = d.my_vote === v;
								voteRow.appendChild(el("button", {
									class: "pp-btn pp-btn-sm" + (isMine ? " pp-btn-primary" : ""),
									text: VOTE_LABELS[v], type: "button",
									onclick: function () {
										api("/projects/" + projectId + "/decisions/" + d.id + "/vote", {
											method: "POST", body: JSON.stringify({ vote: v })
										}).then(function () { reload(renderDecisions); }).catch(function (e) { toast(e.message, "error"); });
									}
								}));
							});
							row.appendChild(voteRow);
						}

						// Stimmen-Detail (wer wie) als ausklappbares Element. Die
						// namentliche Liste (voters) ist in for_project eingebettet.
						if ((d.voters || []).length) {
							var details = el("details", { class: "pp-decision-detail" });
							/* translators: %d: number of votes cast */
							details.appendChild(el("summary", { text: sprintf(__("%d votes", "project-prepper"), d.total_votes) }));
							var voteList = el("ul", { class: "pp-lines" });
							d.voters.forEach(function (vr) {
								var who = vr.missing ? __("(removed user)", "project-prepper") : vr.display_name;
								voteList.appendChild(el("li", null, [
									el("span", { text: who }),
									el("span", { class: "pp-muted", text: VOTE_LABELS[vr.vote] || vr.vote })
								]));
							});
							details.appendChild(voteList);
							row.appendChild(details);
						}

						// Verwaltungs-Aktionen (Schließen/Löschen) — Backend prüft
						// Ersteller/Admin; wir zeigen sie editierbaren Nutzern.
						if (editable) {
							var actions = el("div", { class: "pp-row pp-decision-actions" });
							if (d.status === "open") {
								actions.appendChild(el("button", {
									class: "pp-link", text: __("Close", "project-prepper"), type: "button",
									onclick: function () {
										if (!confirm(__("Close this decision early?", "project-prepper"))) return;
										api("/projects/" + projectId + "/decisions/" + d.id + "/cancel", { method: "POST" })
											.then(function () { reload(renderDecisions); }).catch(function (e) { toast(e.message, "error"); });
									}
								}));
							}
							actions.appendChild(el("button", {
								class: "pp-link pp-link-danger", text: __("delete", "project-prepper"), type: "button",
								onclick: function () {
									if (!confirm(__("Delete this decision and all votes?", "project-prepper"))) return;
									api("/projects/" + projectId + "/decisions/" + d.id, { method: "DELETE" })
										.then(function () { reload(renderDecisions); }).catch(function (e) { toast(e.message, "error"); });
								}
							}));
							row.appendChild(actions);
						}
						list.appendChild(row);
					});
					if (!(project.decisions || []).length) list.appendChild(el("li", { class: "pp-muted", text: __("No decisions yet.", "project-prepper") }));
					decisionsSection.appendChild(list);

					// Anlege-Formular: Titel, Beschreibung, Einstimmigkeit (default an).
					if (!editable) return;
					var dTitle = el("input", { type: "text", placeholder: __("Title", "project-prepper"), class: "pp-input-lg" });
					var dDesc = el("input", { type: "text", placeholder: __("Description", "project-prepper"), class: "pp-input-lg" });
					var dUnanimous = el("input", { type: "checkbox" });
					dUnanimous.checked = true;
					decisionsSection.appendChild(el("div", { class: "pp-row" }, [
						dTitle, dDesc,
						el("label", { class: "pp-check" }, [dUnanimous, el("span", { text: __("Requires unanimous", "project-prepper") })]),
						el("button", {
							class: "pp-btn pp-btn-sm", text: __("New decision", "project-prepper"), type: "button",
							onclick: function () {
								if (!dTitle.value.trim()) return;
								api("/projects/" + projectId + "/decisions", {
									method: "POST",
									body: JSON.stringify({ title: dTitle.value.trim(), description: dDesc.value.trim(), requires_unanimous: dUnanimous.checked })
								}).then(function () {
									dTitle.value = ""; dDesc.value = ""; dUnanimous.checked = true;
									reload(renderDecisions);
								}).catch(function (e) { toast(e.message, "error"); });
							}
						})
					]));
				}
				renderDecisions();

				/* --- Umfragen (v0.15.0, Pendant zu tab-polls / org_polls der App) ---
				   Termin- (date) oder Auswahl-Umfragen (choice) unter den aktiven
				   Mitgliedern der besitzenden Gruppe. Doodle-Stil: Optionen als
				   Zeilen, je Option die Tally Ja/Vielleicht/Nein + drei Toggle-
				   Buttons für das aktuelle Gruppenmitglied (eigene Wahl
				   hervorgehoben). Anders als die Beschlüsse: mehrere Optionen, kein
				   Auto-Resolve — manuell schließen/öffnen. Direkt nach „Beschlüsse"
				   (verwandtes Voting-Konzept). Site-Projekte (ohne Gruppe) zeigen nur
				   einen Hinweis. can_vote/my_votes kommen aus dem Backend. */

				var POLL_TYPE_LABELS = { date: __("Date poll", "project-prepper"), choice: __("Choice poll", "project-prepper") };
				var POLL_VOTE_LABELS = { yes: __("Yes", "project-prepper"), maybe: __("Maybe", "project-prepper"), no: __("No", "project-prepper") };
				var POLL_VOTE_ORDER = ["yes", "maybe", "no"];

				var pollsSection = el("div", { class: "pp-modal-section" });
				function renderPolls() {
					pollsSection.innerHTML = "";
					pollsSection.appendChild(el("h3", { text: __("Polls", "project-prepper") }));

					// Kein Gruppen-Projekt -> Hinweis, keine Umfragen.
					if (!project.owner_group_id) {
						pollsSection.appendChild(el("p", { class: "pp-muted", text: __("Assign a group to run polls.", "project-prepper") }));
						return;
					}

					var list = el("ul", { class: "pp-lines pp-lines-block" });
					(project.polls || []).forEach(function (p) {
						var row = el("li", { class: "pp-decision" });

						// Kopfzeile: Titel + Typ-Hinweis + Status-Badge.
						var head = el("div", { class: "pp-decision-head" }, [
							el("strong", { text: p.title }),
							el("span", { class: "pp-muted", text: POLL_TYPE_LABELS[p.poll_type] || p.poll_type }),
							el("span", { class: "pp-badge pp-badge-" + (p.status === "open" ? "reserved" : "cancelled"), text: p.status === "open" ? __("Open", "project-prepper") : __("Closed", "project-prepper") })
						]);
						row.appendChild(head);

						if (p.description) row.appendChild(el("div", { class: "pp-muted", text: p.description }));

						// Beste Option (meiste Ja) für die Markierung bestimmen.
						var bestYes = 0;
						(p.options || []).forEach(function (o) { if (o.yes > bestYes) bestYes = o.yes; });

						// Doodle-Grid: je Option eine Zeile.
						var grid = el("ul", { class: "pp-lines pp-poll-grid" });
						(p.options || []).forEach(function (o) {
							var label;
							if (p.poll_type === "date") {
								label = o.option_date ? dateDe(o.option_date) : "—";
								if (o.option_time) label += " · " + o.option_time;
							} else {
								label = o.label || "—";
							}
							var optRow = el("li", { class: "pp-poll-option" });

							var labelCell = el("span", { class: "pp-poll-option-label", text: label });
							if (bestYes > 0 && o.yes === bestYes) {
								labelCell.appendChild(el("span", { class: "pp-muted pp-poll-best", text: " · " + __("Best option", "project-prepper") }));
							}
							optRow.appendChild(labelCell);

							// Tally „✓ n · ○ n · ✗ n" (Ja/Vielleicht/Nein als Text).
							/* translators: 1: yes votes, 2: maybe votes, 3: no votes */
							var tallyTpl = __("✓ %1$d · ○ %2$d · ✗ %3$d", "project-prepper");
							optRow.appendChild(el("span", { class: "pp-muted pp-poll-tally", text: sprintf(tallyTpl, o.yes, o.maybe, o.no) }));

							// Stimm-Buttons nur für offene Umfragen + stimmberechtigte
							// Gruppenmitglieder (Backend liefert can_vote/my_votes).
							if (p.status === "open" && p.can_vote) {
								var mine = (p.my_votes && p.my_votes[String(o.id)]) || null;
								var voteRow = el("span", { class: "pp-poll-votes" });
								POLL_VOTE_ORDER.forEach(function (v) {
									var isMine = mine === v;
									voteRow.appendChild(el("button", {
										class: "pp-btn pp-btn-sm" + (isMine ? " pp-btn-primary" : ""),
										text: POLL_VOTE_LABELS[v], type: "button",
										onclick: function () {
											api("/projects/" + projectId + "/polls/" + p.id + "/vote", {
												method: "POST", body: JSON.stringify({ option_id: o.id, vote: v })
											}).then(function () { reload(renderPolls); }).catch(function (e) { toast(e.message, "error"); });
										}
									}));
								});
								optRow.appendChild(voteRow);
							}
							grid.appendChild(optRow);
						});
						row.appendChild(grid);

						// Verwaltungs-Aktionen (Schließen/Öffnen/Löschen) — Backend
						// prüft Ersteller/Admin; wir zeigen sie editierbaren Nutzern.
						if (editable) {
							var actions = el("div", { class: "pp-row pp-decision-actions" });
							if (p.status === "open") {
								actions.appendChild(el("button", {
									class: "pp-link", text: __("Close", "project-prepper"), type: "button",
									onclick: function () {
										api("/projects/" + projectId + "/polls/" + p.id + "/close", { method: "POST" })
											.then(function () { reload(renderPolls); }).catch(function (e) { toast(e.message, "error"); });
									}
								}));
							} else {
								actions.appendChild(el("button", {
									class: "pp-link", text: __("Reopen", "project-prepper"), type: "button",
									onclick: function () {
										api("/projects/" + projectId + "/polls/" + p.id + "/reopen", { method: "POST" })
											.then(function () { reload(renderPolls); }).catch(function (e) { toast(e.message, "error"); });
									}
								}));
							}
							actions.appendChild(el("button", {
								class: "pp-link pp-link-danger", text: __("delete", "project-prepper"), type: "button",
								onclick: function () {
									if (!confirm(__("Delete this poll and all votes?", "project-prepper"))) return;
									api("/projects/" + projectId + "/polls/" + p.id, { method: "DELETE" })
										.then(function () { reload(renderPolls); }).catch(function (e) { toast(e.message, "error"); });
								}
							}));
							row.appendChild(actions);
						}
						list.appendChild(row);
					});
					if (!(project.polls || []).length) list.appendChild(el("li", { class: "pp-muted", text: __("No polls yet.", "project-prepper") }));
					pollsSection.appendChild(list);

					// Anlege-Formular: Titel, Beschreibung, Typ-Select + dynamische
					// Options-Liste (Datum(+Zeit) bei „Termin", Text-Label bei
					// „Auswahl"), „+ Option", mind. 2.
					if (!editable) return;

					var pTitle = el("input", { type: "text", placeholder: __("Title", "project-prepper"), class: "pp-input-lg" });
					var pDesc = el("input", { type: "text", placeholder: __("Description", "project-prepper"), class: "pp-input-lg" });
					var pType = el("select");
					pType.appendChild(el("option", { value: "date", text: __("Date poll", "project-prepper") }));
					pType.appendChild(el("option", { value: "choice", text: __("Choice poll", "project-prepper") }));

					var optionsWrap = el("div", { class: "pp-poll-new-options" });
					function addOptionRow() {
						var rowEl;
						if (pType.value === "date") {
							var dInput = el("input", { type: "date" });
							var tInput = el("input", { type: "time" });
							rowEl = el("div", { class: "pp-row pp-poll-new-option" }, [dInput, tInput]);
							rowEl._read = function () {
								if (!dInput.value) return null;
								return { option_date: dInput.value, option_time: tInput.value || "" };
							};
						} else {
							var lInput = el("input", { type: "text", placeholder: __("Option", "project-prepper"), class: "pp-input-md" });
							rowEl = el("div", { class: "pp-row pp-poll-new-option" }, [lInput]);
							rowEl._read = function () {
								if (!lInput.value.trim()) return null;
								return { label: lInput.value.trim() };
							};
						}
						rowEl.appendChild(el("button", {
							class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
							onclick: function () { optionsWrap.removeChild(rowEl); }
						}));
						optionsWrap.appendChild(rowEl);
					}
					// Typwechsel leert die Options (Felder ändern sich).
					pType.addEventListener("change", function () {
						optionsWrap.innerHTML = "";
						addOptionRow(); addOptionRow();
					});
					addOptionRow(); addOptionRow();

					pollsSection.appendChild(el("div", { class: "pp-row" }, [pTitle, pDesc, pType]));
					pollsSection.appendChild(optionsWrap);
					pollsSection.appendChild(el("div", { class: "pp-row" }, [
						el("button", {
							class: "pp-btn pp-btn-sm", text: __("Add option", "project-prepper"), type: "button",
							onclick: function () { addOptionRow(); }
						}),
						el("button", {
							class: "pp-btn pp-btn-sm pp-btn-primary", text: __("Create poll", "project-prepper"), type: "button",
							onclick: function () {
								if (!pTitle.value.trim()) return;
								var opts = [];
								Array.prototype.forEach.call(optionsWrap.children, function (r) {
									if (typeof r._read === "function") { var v = r._read(); if (v) opts.push(v); }
								});
								if (opts.length < 2) { toast(__("A poll needs at least two valid options.", "project-prepper"), "error"); return; }
								api("/projects/" + projectId + "/polls", {
									method: "POST",
									body: JSON.stringify({ title: pTitle.value.trim(), description: pDesc.value.trim(), poll_type: pType.value, options: opts })
								}).then(function () {
									pTitle.value = ""; pDesc.value = ""; pType.value = "date";
									optionsWrap.innerHTML = ""; addOptionRow(); addOptionRow();
									reload(renderPolls);
								}).catch(function (e) { toast(e.message, "error"); });
							}
						})
					]));
				}
				renderPolls();

				/* --- Team & Kontakte (Pendant zu tab-team der App) ---
				   Eine Sektion mit zwei Unterlisten: Team (Name/Rolle/Abteilung)
				   und Kontakte (Name/Rolle/Firma/E-Mail/Telefon). Single-Site:
				   keine Profil-Verknüpfung, nur Freitext. Ganz am Ende, da
				   organisatorisch (nach den operativen Aufgaben). */

				var teamSection = el("div", { class: "pp-modal-section" });
				function renderTeam() {
					teamSection.innerHTML = "";
					teamSection.appendChild(el("h3", { text: __("Team & Contacts", "project-prepper") }));

					// Unterliste 1: Team-Mitglieder.
					teamSection.appendChild(el("div", { class: "pp-subhead", text: __("Team", "project-prepper") }));
					var teamList = el("ul", { class: "pp-lines" });
					(project.team || []).forEach(function (m) {
						var meta = el("span", { class: "pp-muted", text: [m.role, m.department].filter(Boolean).join(" · ") });
						if (!editable) {
							teamList.appendChild(el("li", null, [el("span", { text: m.name }), meta]));
							return;
						}
						teamList.appendChild(el("li", null, [
							el("span", { text: m.name }), meta,
							el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/team/" + m.id, { method: "DELETE" })
										.then(function () { reload(renderTeam); }).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					});
					if (!(project.team || []).length) teamList.appendChild(el("li", { class: "pp-muted", text: __("No team members.", "project-prepper") }));
					teamSection.appendChild(teamList);

					if (editable) {
						var tName = el("input", { type: "text", placeholder: __("Name", "project-prepper"), class: "pp-input-md" });
						var tRole = el("input", { type: "text", placeholder: __("Role", "project-prepper"), class: "pp-input-md" });
						var tDept = el("input", { type: "text", placeholder: __("Department", "project-prepper"), class: "pp-input-md" });
						teamSection.appendChild(el("div", { class: "pp-row" }, [
							tName, tRole, tDept,
							el("button", {
								class: "pp-btn pp-btn-sm", text: __("+ Team member", "project-prepper"), type: "button",
								onclick: function () {
									if (!tName.value.trim()) return;
									api("/projects/" + projectId + "/team", {
										method: "POST",
										body: JSON.stringify({ name: tName.value.trim(), role: tRole.value.trim(), department: tDept.value.trim() })
									}).then(function () {
										tName.value = ""; tRole.value = ""; tDept.value = "";
										reload(renderTeam);
									}).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					}

					// Unterliste 2: Externe Kontakte.
					teamSection.appendChild(el("div", { class: "pp-subhead", text: __("Contacts", "project-prepper") }));
					var contactList = el("ul", { class: "pp-lines" });
					(project.contacts || []).forEach(function (c) {
						var bits = [c.role, c.company].filter(Boolean);
						if (c.email) bits.push(c.email);
						if (c.phone) bits.push(c.phone);
						var meta = el("span", { class: "pp-muted", text: bits.join(" · ") });
						if (!editable) {
							contactList.appendChild(el("li", null, [el("span", { text: c.name }), meta]));
							return;
						}
						contactList.appendChild(el("li", null, [
							el("span", { text: c.name }), meta,
							el("button", {
								class: "pp-link pp-link-danger", text: __("remove", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/contacts/" + c.id, { method: "DELETE" })
										.then(function () { reload(renderTeam); }).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					});
					if (!(project.contacts || []).length) contactList.appendChild(el("li", { class: "pp-muted", text: __("No contacts.", "project-prepper") }));
					teamSection.appendChild(contactList);

					if (editable) {
						var coName = el("input", { type: "text", placeholder: __("Name", "project-prepper"), class: "pp-input-md" });
						var coRole = el("input", { type: "text", placeholder: __("Role", "project-prepper"), class: "pp-input-sm" });
						var coCompany = el("input", { type: "text", placeholder: __("Company", "project-prepper"), class: "pp-input-sm" });
						var coEmail = el("input", { type: "email", placeholder: __("Email", "project-prepper"), class: "pp-input-md" });
						var coPhone = el("input", { type: "text", placeholder: __("Phone", "project-prepper"), class: "pp-input-sm" });
						teamSection.appendChild(el("div", { class: "pp-row" }, [
							coName, coRole, coCompany, coEmail, coPhone,
							el("button", {
								class: "pp-btn pp-btn-sm", text: __("+ Contact", "project-prepper"), type: "button",
								onclick: function () {
									if (!coName.value.trim()) return;
									api("/projects/" + projectId + "/contacts", {
										method: "POST",
										body: JSON.stringify({ name: coName.value.trim(), role: coRole.value.trim(), company: coCompany.value.trim(), email: coEmail.value.trim(), phone: coPhone.value.trim() })
									}).then(function () {
										coName.value = ""; coRole.value = ""; coCompany.value = ""; coEmail.value = ""; coPhone.value = "";
										reload(renderTeam);
									}).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					}
				}
				renderTeam();

				/* --- Kooperationsvereinbarung (Gruppen-Phase 5, Pendant zu
				   tab-agreement / cooperation_agreements der App) --- Die formale
				   Klammer über allem: ein Vertrags-Body (Freitext) + Signatur-
				   Tracking je Gruppenmitglied. Daher ganz am Ende des Modals.
				   Status-Maschine: Entwurf → In Unterzeichnung → Aktiv (alle
				   unterschrieben) | Beendet. Sichtbarkeit „darf ich unterschreiben"
				   + mein Status kommen aus dem Backend (can_sign/my_signature). */

				var AGREEMENT_STATUS = { draft: __("Draft", "project-prepper"), signing: __("In signing", "project-prepper"), active: __("Active", "project-prepper"), terminated: __("Terminated", "project-prepper") };
				var AGREEMENT_BADGE = { draft: "draft", signing: "reserved", active: "returned", terminated: "cancelled" };
				var SIGNATURE_STATUS = { signed: __("Signed", "project-prepper"), declined: __("Declined", "project-prepper"), pending: __("Pending", "project-prepper") };

				var agreementSection = el("div", { class: "pp-modal-section" });
				function renderAgreement() {
					agreementSection.innerHTML = "";
					agreementSection.appendChild(el("h3", { text: __("Cooperation agreement", "project-prepper") }));

					// Kein Gruppen-Projekt -> Hinweis, keine Vereinbarung.
					if (!project.owner_group_id) {
						agreementSection.appendChild(el("p", { class: "pp-muted", text: __("Assign a group to set up an agreement.", "project-prepper") }));
						return;
					}

					var a = project.agreement;

					// Noch keine Vereinbarung -> Anlege-Formular (nur editierbar).
					if (!a) {
						if (!editable) {
							agreementSection.appendChild(el("p", { class: "pp-muted", text: __("No agreement yet.", "project-prepper") }));
							return;
						}
						var nTitle = el("input", { type: "text", placeholder: __("Title", "project-prepper"), class: "pp-input-lg" });
						var nTerms = el("textarea", { rows: "4", placeholder: __("Contract text", "project-prepper") });
						agreementSection.appendChild(field(__("Title", "project-prepper"), nTitle));
						agreementSection.appendChild(field(__("Contract text", "project-prepper"), nTerms));
						agreementSection.appendChild(el("div", { class: "pp-row" }, [
							el("button", {
								class: "pp-btn pp-btn-sm", text: __("Create agreement", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/agreement", {
										method: "POST",
										body: JSON.stringify({ title: nTitle.value.trim(), terms: nTerms.value })
									}).then(function () { reload(renderAgreement); }).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
						return;
					}

					// Kopfzeile: Status-Badge + Version.
					agreementSection.appendChild(el("div", { class: "pp-decision-head" }, [
						el("strong", { text: a.title || "—" }),
						el("span", { class: "pp-badge pp-badge-" + (AGREEMENT_BADGE[a.status] || a.status), text: AGREEMENT_STATUS[a.status] || a.status }),
						/* translators: %d: agreement version number */
						el("span", { class: "pp-muted", text: sprintf(__("Version %d", "project-prepper"), a.version) })
					]));

					// Vertragstext: im Entwurf editierbar + speichern, sonst read-only.
					if (a.status === "draft" && editable) {
						var eTerms = el("textarea", { rows: "5" });
						eTerms.value = a.terms || "";
						agreementSection.appendChild(field(__("Contract text", "project-prepper"), eTerms));
						agreementSection.appendChild(el("div", { class: "pp-row" }, [
							el("button", {
								class: "pp-btn pp-btn-sm", text: __("Save", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/agreement", {
										method: "PUT", body: JSON.stringify({ title: a.title, terms: eTerms.value })
									}).then(function () { reload(renderAgreement); }).catch(function (e) { toast(e.message, "error"); });
								}
							}),
							el("button", {
								class: "pp-btn pp-btn-sm pp-btn-primary", text: __("Open for signing", "project-prepper"), type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/agreement/open", { method: "POST" })
										.then(function () { reload(renderAgreement); }).catch(function (e) { toast(e.message, "error"); });
								}
							})
						]));
					} else if (a.terms) {
						agreementSection.appendChild(el("div", { class: "pp-agreement-terms", text: a.terms }));
					}

					// Aktiv seit ...
					if (a.status === "active" && a.activated_at) {
						/* translators: %s: activation date */
						agreementSection.appendChild(el("p", { class: "pp-muted", text: sprintf(__("Active since %s", "project-prepper"), dateDe(a.activated_at)) }));
					}

					// Signatur-Soll: x von y unterschrieben (Text, keine Icons).
					if (a.status === "signing" || a.status === "active") {
						/* translators: 1: signed count, 2: total members */
						var tallyTxt = sprintf(__("Signed %1$d of %2$d", "project-prepper"), a.signed_count, a.total_members);
						agreementSection.appendChild(el("div", { class: "pp-decision-tally", text: tallyTxt }));
					}

					// Unterschreiben/Ablehnen für das aktuelle Gruppenmitglied
					// (Backend liefert can_sign + my_signature).
					if (a.can_sign) {
						var signRow = el("div", { class: "pp-row" });
						[["sign", __("Sign", "project-prepper")], ["decline", __("Decline", "project-prepper")]].forEach(function (pair) {
							var mineSigned = (pair[0] === "sign" && a.my_signature === "signed");
							var mineDeclined = (pair[0] === "decline" && a.my_signature === "declined");
							signRow.appendChild(el("button", {
								class: "pp-btn pp-btn-sm" + (mineSigned || mineDeclined ? " pp-btn-primary" : ""),
								text: pair[1], type: "button",
								onclick: function () {
									api("/projects/" + projectId + "/agreement/sign", {
										method: "POST", body: JSON.stringify({ action: pair[0] })
									}).then(function () { reload(renderAgreement); }).catch(function (e) { toast(e.message, "error"); });
								}
							}));
						});
						agreementSection.appendChild(signRow);
					}

					// Signatur-Roster: je Gruppenmitglied Status + Datum.
					if (a.status === "signing" || a.status === "active") {
						var roster = el("ul", { class: "pp-lines" });
						(a.signatures || []).forEach(function (s) {
							var who = s.missing ? __("(removed user)", "project-prepper") : s.display_name;
							var when = s.status === "signed" ? s.signed_at : (s.status === "declined" ? s.declined_at : "");
							var label = (SIGNATURE_STATUS[s.status] || s.status) + (when ? " · " + dateDe(when) : "");
							roster.appendChild(el("li", null, [
								el("span", { text: who }),
								el("span", { class: "pp-muted", text: label })
							]));
						});
						if (!(a.signatures || []).length) roster.appendChild(el("li", { class: "pp-muted", text: __("No group members.", "project-prepper") }));
						agreementSection.appendChild(roster);
					}

					// Verwaltungs-Aktionen (Ersteller/Admin prüft das Backend; wir
					// zeigen sie editierbaren Nutzern, je nach Status).
					if (editable) {
						var actions = el("div", { class: "pp-row pp-decision-actions" });
						if (a.status === "signing") {
							actions.appendChild(el("button", {
								class: "pp-link", text: __("Revise", "project-prepper"), type: "button",
								onclick: function () {
									if (!confirm(__("Revise this agreement? All signatures will be cleared.", "project-prepper"))) return;
									api("/projects/" + projectId + "/agreement/revise", { method: "POST" })
										.then(function () { reload(renderAgreement); }).catch(function (e) { toast(e.message, "error"); });
								}
							}));
						}
						if (a.status !== "terminated") {
							actions.appendChild(el("button", {
								class: "pp-link", text: __("Terminate", "project-prepper"), type: "button",
								onclick: function () {
									if (!confirm(__("Terminate this agreement?", "project-prepper"))) return;
									api("/projects/" + projectId + "/agreement/terminate", { method: "POST" })
										.then(function () { reload(renderAgreement); }).catch(function (e) { toast(e.message, "error"); });
								}
							}));
						}
						actions.appendChild(el("button", {
							class: "pp-link pp-link-danger", text: __("delete", "project-prepper"), type: "button",
							onclick: function () {
								if (!confirm(__("Delete this agreement and all signatures?", "project-prepper"))) return;
								api("/projects/" + projectId + "/agreement", { method: "DELETE" })
									.then(function () { reload(renderAgreement); }).catch(function (e) { toast(e.message, "error"); });
							}
						}));
						agreementSection.appendChild(actions);
					}
				}
				renderAgreement();

				// Tab-Leiste (App-Layout): die Modal-Sektionen werden in Tabs gekapselt,
				// nur das aktive Panel ist sichtbar. Reihenfolge wie in der App; WP-Extras
				// (Beteiligte, Beschlüsse) den passenden Tabs zugeordnet.
				var tabDefs = [
					{ label: __("Overview", "project-prepper"), sections: [el("div", null, [statusRow, info])] },
					{ label: __("Equipment", "project-prepper"), sections: [bookingsSection] },
					{ label: __("Schedule", "project-prepper"), sections: [scheduleSection] },
					{ label: __("Team & contacts", "project-prepper"), sections: [teamSection, membersSection] },
					{ label: __("Material & transport", "project-prepper"), sections: [consumablesSection] },
					{ label: __("Costs", "project-prepper"), sections: [costsSection] },
					{ label: __("Profit", "project-prepper"), sections: [profitSection] },
					{ label: __("Checklists", "project-prepper"), sections: [checklistsSection] },
					{ label: __("Tasks", "project-prepper"), sections: [tasksSection] },
					{ label: __("Polls", "project-prepper"), sections: [pollsSection] },
					{ label: __("Decisions", "project-prepper"), sections: [decisionsSection] },
					{ label: __("Agreement", "project-prepper"), sections: [agreementSection] },
					{ label: __("Files", "project-prepper"), sections: [filesSection] }
				];
				var tabBar = el("div", { class: "pp-tabs" });
				var panels = [];
				tabDefs.forEach(function (def, index) {
					var panel = el("div", { class: "pp-tab-panel" + (index === 0 ? "" : " is-hidden") }, def.sections);
					panels.push(panel);
					var tabBtn = el("button", {
						class: "pp-tab" + (index === 0 ? " is-active" : ""), type: "button", text: def.label,
						onclick: function () {
							tabBar.querySelectorAll(".pp-tab").forEach(function (b) { b.classList.remove("is-active"); });
							tabBtn.classList.add("is-active");
							panels.forEach(function (p, i) { p.classList.toggle("is-hidden", i !== index); });
						}
					});
					tabBar.appendChild(tabBtn);
				});
				var body = el("div", null, [tabBar].concat(panels));

				var close;
				var footerButtons = el("div", { class: "pp-right" }, [el("button", { class: "pp-btn", text: __("Close", "project-prepper"), onclick: function () { close(); } })]);
				if (editable) {
					footerButtons.insertBefore(el("button", {
						class: "pp-btn pp-btn-primary", text: __("Save", "project-prepper"),
						onclick: function () {
							api("/projects/" + projectId, {
								method: "PUT",
								body: JSON.stringify({
									name: f.name.value.trim(),
									date_start: f.start.value,
									date_end: f.end.value,
									venue_name: f.venueName.value.trim(),
									venue_address: f.venueAddress.value,
									client_name: f.clientName.value.trim(),
									client_email: f.clientEmail.value.trim(),
									client_phone: f.clientPhone.value.trim(),
									budget_planned: f.budgetPlanned.value,
									revenue_actual: f.revenueActual.value,
									owner_group_id: f.group.value ? parseInt(f.group.value, 10) : 0,
									notes: f.notes.value
								})
							}).then(function () {
								toast(__("Saved.", "project-prepper")); close(); load();
							}).catch(function (e) { toast(e.message, "error"); });
						}
					}), footerButtons.firstChild);
				}
				var footer = el("div", { class: "pp-modal-footer" }, [
					editable ? el("button", {
						class: "pp-btn pp-btn-danger", text: __("Delete project", "project-prepper"),
						onclick: function () {
							/* translators: %s: project name */
							if (!confirm(sprintf(__('Delete project "%s"? Bookings, checklists and tasks will be deleted too.', "project-prepper"), project.name))) return;
							api("/projects/" + projectId, { method: "DELETE" }).then(function () { close(); load(); });
						}
					}) : el("span"),
					footerButtons
				]);
				close = openModal(project.project_number + " — " + project.name, body, footer);
			}).catch(function (e) { toast(e.message, "error"); });
		}

		/* ----- Neues Projekt ----- */

		if (ppConfig.canEdit.projects) {
			var cName = el("input", { type: "text", placeholder: __("Name *", "project-prepper"), class: "pp-input-lg" });
			var cStatus = statusSelect("draft");
			var cFrom = el("input", { type: "date", title: __("From", "project-prepper") });
			var cTo = el("input", { type: "date", title: __("To", "project-prepper") });
			var cVenue = el("input", { type: "text", placeholder: __("Venue", "project-prepper"), class: "pp-input-md" });
			var cClient = el("input", { type: "text", placeholder: __("Client", "project-prepper"), class: "pp-input-md" });
			var cGroup = groupSelect("");
			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("New project", "project-prepper") }),
				el("form", {
					onsubmit: function (e) {
						e.preventDefault();
						if (!cName.value.trim()) return;
						api("/projects", {
							method: "POST",
							body: JSON.stringify({
								name: cName.value.trim(),
								status: cStatus.value,
								date_start: cFrom.value,
								date_end: cTo.value,
								venue_name: cVenue.value.trim(),
								client_name: cClient.value.trim(),
								owner_group_id: cGroup.value ? parseInt(cGroup.value, 10) : 0
							})
						}).then(function (project) {
							/* translators: %s: project number */
							toast(sprintf(__("Project %s created.", "project-prepper"), project.project_number));
							cName.value = cVenue.value = cClient.value = ""; cFrom.value = cTo.value = ""; cStatus.value = "draft"; cGroup.value = "";
							load();
						}).catch(function (e2) { toast(e2.message, "error"); });
					}
				}, [
					el("div", { class: "pp-row" }, [
						field(__("Name *", "project-prepper"), cName), field(__("Status", "project-prepper"), cStatus),
						field(__("From", "project-prepper"), cFrom), field(__("To", "project-prepper"), cTo),
						field(__("Venue", "project-prepper"), cVenue), field(__("Client", "project-prepper"), cClient),
						field(__("Group", "project-prepper"), cGroup),
						el("button", { class: "pp-btn pp-btn-primary", text: __("Create project", "project-prepper") })
					])
				])
			]));
		}

		api("/items").then(function (result) { items = result; }).catch(function () {});
		// Gruppen einmal laden (für die Gruppen-Auswahl). 403/leer → keine Gruppen.
		// Das Anlege-Formular wird synchron gebaut, daher die Optionen nachziehen.
		api("/groups").then(function (result) {
			groups = result || [];
			if (typeof cGroup !== "undefined" && cGroup) {
				var keep = cGroup.value;
				cGroup.innerHTML = "";
				cGroup.appendChild(el("option", { value: "", text: __("— site level —", "project-prepper") }));
				groups.forEach(function (group) { cGroup.appendChild(el("option", { value: group.id, text: group.name })); });
				cGroup.value = keep;
			}
		}).catch(function () { groups = []; });

		root.appendChild(pillBox);
		root.appendChild(listBox);
		renderPills();
		load();

		// Deep-Link aufs Detail (Pendant zur App-Route /projects/[id]): #pp-project-{id}.
		var hashMatch = window.location.hash.match(/^#pp-project-(\d+)$/);
		if (hashMatch) openProjectModal(parseInt(hashMatch[1], 10));
	}

	/* ================= Seite: Kategorien ================= */

	function renderCategories() {
		root.innerHTML = "";
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
			// E-Mail
			var emailToggle = el("input", { type: "checkbox" });
			emailToggle.checked = settings.email_notifications;
			var templateInputs = {};
			var TEMPLATE_LABELS = {
				rental_reserved: __("Reservation confirmed", "project-prepper"),
				rental_active: __("Equipment handed out", "project-prepper"),
				rental_returned: __("Return confirmed", "project-prepper")
			};
			var templateFields = Object.keys(TEMPLATE_LABELS).map(function (key) {
				var subject = el("input", { type: "text", value: settings.email_templates[key].subject, style: "width:100%" });
				var bodyArea = el("textarea");
				bodyArea.value = settings.email_templates[key].body;
				templateInputs[key] = { subject: subject, body: bodyArea };
				return el("div", { class: "pp-modal-section" }, [
					el("h3", { text: TEMPLATE_LABELS[key] }),
					field(__("Subject", "project-prepper"), subject),
					field(__("Text", "project-prepper"), bodyArea)
				]);
			});

			var saveBtn = el("button", {
				class: "pp-btn pp-btn-primary", text: __("Save", "project-prepper"),
				onclick: function () {
					var templates = {};
					Object.keys(templateInputs).forEach(function (key) {
						templates[key] = { subject: templateInputs[key].subject.value, body: templateInputs[key].body.value };
					});
					api("/settings", {
						method: "PUT",
						body: JSON.stringify({
							email_notifications: emailToggle.checked,
							delete_data_on_uninstall: deleteToggle.checked,
							public_show_rates: ratesToggle.checked,
							email_templates: templates
						})
					}).then(function () { toast(__("Settings saved.", "project-prepper")); }).catch(function (e) { toast(e.message, "error"); });
				}
			});

			root.appendChild(el("div", { class: "pp-card" }, [
				el("h2", { text: __("Email notifications", "project-prepper") }),
				el("label", { class: "pp-toggle" }, [emailToggle, el("span", { text: __("Send emails to borrowers (reservation, handout, return)", "project-prepper") })]),
				el("div", { class: "pp-muted", style: "margin-top:6px", text: __("Placeholders:", "project-prepper") + " {{borrower_name}}, {{rental_number}}, {{date_from}}, {{date_to}}, {{items}}, {{site_name}}" })
			].concat(templateFields)));

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
								href: "admin.php?page=pp-projects#pp-project-" + p.id,
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

	/* ================= Calendar (month view) ================= */

	function renderCalendar() {
		root.innerHTML = "";

		// Wochentage (Mo–So) + Monatsnamen als eigene übersetzbare Strings.
		var WEEKDAYS = [
			_x("Mon", "weekday", "project-prepper"),
			_x("Tue", "weekday", "project-prepper"),
			_x("Wed", "weekday", "project-prepper"),
			_x("Thu", "weekday", "project-prepper"),
			_x("Fri", "weekday", "project-prepper"),
			_x("Sat", "weekday", "project-prepper"),
			_x("Sun", "weekday", "project-prepper")
		];
		var MONTHS = [
			__("January", "project-prepper"), __("February", "project-prepper"),
			__("March", "project-prepper"), __("April", "project-prepper"),
			__("May", "project-prepper"), __("June", "project-prepper"),
			__("July", "project-prepper"), __("August", "project-prepper"),
			__("September", "project-prepper"), __("October", "project-prepper"),
			__("November", "project-prepper"), __("December", "project-prepper")
		];

		// Lokales Y-m-d (kein UTC-Versatz wie bei toISOString).
		function ymd(d) {
			var m = String(d.getMonth() + 1);
			var day = String(d.getDate());
			return d.getFullYear() + "-" + (m.length < 2 ? "0" + m : m) + "-" + (day.length < 2 ? "0" + day : day);
		}
		// Wochentag Mo=0 … So=6.
		function isoDow(d) { return (d.getDay() + 6) % 7; }

		var today = new Date();
		var view = new Date(today.getFullYear(), today.getMonth(), 1);

		// Container-Gerüst (Toolbar + Grid-Mount + Legende + Feed).
		var titleEl = el("span", { class: "pp-cal-title" });
		var toolbar = el("div", { class: "pp-cal-toolbar" }, [
			el("button", { class: "pp-cal-nav", text: "‹", title: __("Previous month", "project-prepper"), onclick: function () { shift(-1); } }),
			el("button", { class: "pp-cal-nav", text: "›", title: __("Next month", "project-prepper"), onclick: function () { shift(1); } }),
			titleEl,
			el("button", { class: "pp-cal-today", text: __("Today", "project-prepper"), onclick: function () { view = new Date(today.getFullYear(), today.getMonth(), 1); load(); } })
		]);
		var gridMount = el("div", {});
		root.appendChild(toolbar);
		root.appendChild(gridMount);

		// Legende.
		function legendItem(cls, label) {
			return el("span", { class: "pp-cal-legend-item" }, [
				el("span", { class: "pp-cal-swatch " + cls }),
				el("span", { text: label })
			]);
		}
		root.appendChild(el("div", { class: "pp-cal-legend" }, [
			legendItem("pp-cal-rental", __("Rental", "project-prepper")),
			legendItem("pp-cal-project", __("Project", "project-prepper")),
			legendItem("pp-cal-schedule", __("Schedule", "project-prepper"))
		]));

		// iCal-Feed-Hinweis: URL nur, wenn der Nutzer die Einstellungen sehen darf.
		var feedBox = el("div", { class: "pp-cal-feed" }, [
			el("span", { text: __("Calendar feed (iCal)", "project-prepper") + ": " })
		]);
		if (ppConfig.canEdit && ppConfig.canEdit.settings) {
			api("/settings").then(function (s) {
				if (s && s.ical_url) feedBox.appendChild(el("code", { text: s.ical_url }));
			}).catch(function () {
				feedBox.appendChild(el("span", { text: __("available in the settings.", "project-prepper") }));
			});
		} else {
			feedBox.appendChild(el("span", { text: __("available in the settings.", "project-prepper") }));
		}
		root.appendChild(feedBox);

		function shift(delta) {
			view = new Date(view.getFullYear(), view.getMonth() + delta, 1);
			load();
		}

		function load() {
			titleEl.textContent = MONTHS[view.getMonth()] + " " + view.getFullYear();

			// Erster sichtbarer Rastertag (Montag der ersten Woche) … letzter (Sonntag).
			var first = new Date(view.getFullYear(), view.getMonth(), 1);
			var gridStart = new Date(first);
			gridStart.setDate(first.getDate() - isoDow(first));
			var last = new Date(view.getFullYear(), view.getMonth() + 1, 0);
			var gridEnd = new Date(last);
			gridEnd.setDate(last.getDate() + (6 - isoDow(last)));

			var from = ymd(gridStart);
			var to = ymd(gridEnd);

			api("/calendar-events?from=" + from + "&to=" + to).then(function (events) {
				// Events pro Tag bündeln; mehrtägige an jedem Tag des Zeitraums.
				var byDay = {};
				function push(day, ev) { (byDay[day] = byDay[day] || []).push(ev); }
				(events || []).forEach(function (ev) {
					if (ev.type === "schedule") {
						push(ev.date, ev);
					} else {
						var s = new Date(ev.date_from + "T00:00:00");
						var e = new Date((ev.date_to || ev.date_from) + "T00:00:00");
						for (var d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
							var key = ymd(d);
							if (key >= from && key <= to) push(key, ev);
						}
					}
				});

				var grid = el("div", { class: "pp-cal-grid" });
				WEEKDAYS.forEach(function (w) { grid.appendChild(el("div", { class: "pp-cal-weekday", text: w })); });

				var todayKey = ymd(today);
				var cur = new Date(gridStart);
				while (cur <= gridEnd) {
					var key = ymd(cur);
					var outside = cur.getMonth() !== view.getMonth();
					var cls = "pp-cal-day" + (outside ? " pp-cal-outside" : "") + (key === todayKey ? " pp-cal-today-cell" : "");
					var cell = el("div", { class: cls }, [
						el("span", { class: "pp-cal-daynum", text: String(cur.getDate()) })
					]);
					(byDay[key] || []).forEach(function (ev) {
						cell.appendChild(calEvent(ev));
					});
					grid.appendChild(cell);
					cur.setDate(cur.getDate() + 1);
				}

				gridMount.innerHTML = "";
				gridMount.appendChild(grid);
			}).catch(function (err) {
				toast(err.message, "error");
			});
		}

		function calEvent(ev) {
			var label, href, cls;
			if (ev.type === "rental") {
				cls = "pp-cal-rental";
				label = ev.title;
				href = "admin.php?page=pp-rentals";
			} else if (ev.type === "project") {
				cls = "pp-cal-project";
				label = ev.title || __("(untitled)", "project-prepper");
				href = "admin.php?page=pp-projects#pp-project-" + ev.id;
			} else {
				cls = "pp-cal-schedule";
				var t = ev.time_start ? String(ev.time_start).slice(0, 5) + " " : "";
				label = t + (ev.title || "");
				href = "admin.php?page=pp-projects#pp-project-" + ev.project_id;
			}
			return el("a", { class: "pp-cal-event " + cls, href: href, title: label, text: label });
		}

		load();
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
	else renderInventory();
})();
