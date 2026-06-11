/**
 * Project Prepper — Gutenberg-Blöcke (Editor-Seite, kein Build-Step).
 * Server-gerenderte Blöcke mit Inspector-Einstellungen.
 */
(function (wp) {
	"use strict";

	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var ServerSideRender = wp.serverSideRender;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;

	function inspector(children) {
		return el(InspectorControls, {}, el(PanelBody, { title: "Einstellungen", initialOpen: true }, children));
	}

	function showAllToggle(props) {
		return el(ToggleControl, {
			key: "showAll",
			label: "Auch defekte/ausgemusterte zeigen",
			checked: props.attributes.showAll,
			onChange: function (v) { props.setAttributes({ showAll: v }); }
		});
	}

	registerBlockType("project-prepper/inventory", {
		title: "PP: Equipment-Liste",
		description: "Öffentliche Inventarliste aus Project Prepper.",
		icon: "archive",
		category: "widgets",
		attributes: {
			category: { type: "string", default: "" },
			showRates: { type: "boolean", default: false },
			search: { type: "boolean", default: false },
			showAll: { type: "boolean", default: false }
		},
		edit: function (props) {
			return el(wp.element.Fragment, {},
				inspector([
					el(TextControl, {
						key: "category",
						label: "Kategorie (Name oder Prefix, leer = alle)",
						value: props.attributes.category,
						onChange: function (v) { props.setAttributes({ category: v }); }
					}),
					el(ToggleControl, {
						key: "rates",
						label: "Tagessätze anzeigen",
						checked: props.attributes.showRates,
						onChange: function (v) { props.setAttributes({ showRates: v }); }
					}),
					el(ToggleControl, {
						key: "search",
						label: "Suchfeld anzeigen",
						checked: props.attributes.search,
						onChange: function (v) { props.setAttributes({ search: v }); }
					}),
					showAllToggle(props)
				]),
				el(ServerSideRender, { block: "project-prepper/inventory", attributes: props.attributes })
			);
		},
		save: function () { return null; }
	});

	registerBlockType("project-prepper/availability", {
		title: "PP: Verfügbarkeits-Check",
		description: "Verfügbarkeit eines Artikels für einen Zeitraum prüfen.",
		icon: "calendar-alt",
		category: "widgets",
		attributes: {
			item: { type: "string", default: "" },
			showAll: { type: "boolean", default: false }
		},
		edit: function (props) {
			return el(wp.element.Fragment, {},
				inspector([
					el(TextControl, {
						key: "item",
						label: "Artikel-ID (leer = Auswahlfeld)",
						value: props.attributes.item,
						onChange: function (v) { props.setAttributes({ item: v }); }
					}),
					showAllToggle(props)
				]),
				el(ServerSideRender, { block: "project-prepper/availability", attributes: props.attributes })
			);
		},
		save: function () { return null; }
	});

	registerBlockType("project-prepper/request-form", {
		title: "PP: Anfrage-Formular",
		description: "Leih-/Projektanfrage-Formular für Besucher.",
		icon: "email",
		category: "widgets",
		attributes: {
			showItems: { type: "boolean", default: true },
			showAll: { type: "boolean", default: false }
		},
		edit: function (props) {
			return el(wp.element.Fragment, {},
				inspector([
					el(ToggleControl, {
						key: "items",
						label: "Equipment-Auswahl anzeigen",
						checked: props.attributes.showItems,
						onChange: function (v) { props.setAttributes({ showItems: v }); }
					}),
					showAllToggle(props)
				]),
				el(ServerSideRender, { block: "project-prepper/request-form", attributes: props.attributes })
			);
		},
		save: function () { return null; }
	});
})(window.wp);
