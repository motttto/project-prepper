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
					html: "<tr><th></th><th>" + __("Number", "project-prepper") + "</th><th>" + __("Name", "project-prepper") + "</th><th>" + __("Category", "project-prepper") + "</th><th>" + __("Quantity", "project-prepper") + "</th><th>" + __("Condition", "project-prepper") + "</th><th>" + __("Daily rate", "project-prepper") + "</th><th>" + __("Location", "project-prepper") + "</th><th>" + __("Docs", "project-prepper") + "</th></tr>"
				}));
				var tbody = el("tbody");
				items.forEach(function (item) {
					var thumb = item.image_url
						? el("img", { class: "pp-thumb", src: item.image_url, alt: "" })
						: el("div", { class: "pp-thumb-empty", text: item.category_icon || "📦" });
					// Badge "n unterwegs" wenn der Artikel heute in Verleihen steckt.
					var nameCell = el("td", null, [el("span", { text: item.name })]);
					if (item.out_now > 0) {
						nameCell.appendChild(el("span", { class: "pp-badge pp-badge-active pp-badge-out", text: item.out_now + " " + __("on loan", "project-prepper") }));
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
						el("td", null, [el("code", { text: item.inventory_number })]),
						nameCell,
						el("td", { text: (item.category_icon ? item.category_icon + " " : "") + (item.category_name || "—") }),
						el("td", { text: item.quantity }),
						el("td", null, [badge(item.condition, CONDITIONS)]),
						el("td", { text: money(item.cost_per_day) }),
						el("td", { text: item.location || "—" }),
						docsCell
					]);
					tbody.appendChild(row);
				});
				if (!items.length) tbody.appendChild(el("tr", { html: '<td colspan="9" class="pp-muted">' + __("No items found.", "project-prepper") + "</td>" }));
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

	/* ================= Routing ================= */

	if (page === "categories") renderCategories();
	else if (page === "rentals") renderRentals();
	else if (page === "inquiries") renderInquiries();
	else if (page === "settings") renderSettings();
	else renderInventory();
})();
