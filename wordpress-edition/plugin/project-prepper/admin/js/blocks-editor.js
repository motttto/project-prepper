/**
 * Project Prepper — Gutenberg-Blöcke (Editor-Seite, kein Build-Step).
 * Server-gerenderte Blöcke mit Inspector-Einstellungen.
 */
(function (wp) {
	"use strict";

	var __ = wp.i18n.__;
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var ServerSideRender = wp.serverSideRender;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;

	function inspector(children) {
		return el(InspectorControls, {}, el(PanelBody, { title: __("Settings", "project-prepper"), initialOpen: true }, children));
	}

	function showAllToggle(props) {
		return el(ToggleControl, {
			key: "showAll",
			label: __("Also show broken/retired items", "project-prepper"),
			checked: props.attributes.showAll,
			onChange: function (v) { props.setAttributes({ showAll: v }); }
		});
	}

	registerBlockType("project-prepper/inventory", {
		title: __("PP: Equipment list", "project-prepper"),
		description: __("Public inventory list from Project Prepper.", "project-prepper"),
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
						label: __("Category (name or prefix, empty = all)", "project-prepper"),
						value: props.attributes.category,
						onChange: function (v) { props.setAttributes({ category: v }); }
					}),
					el(ToggleControl, {
						key: "rates",
						label: __("Show daily rates", "project-prepper"),
						checked: props.attributes.showRates,
						onChange: function (v) { props.setAttributes({ showRates: v }); }
					}),
					el(ToggleControl, {
						key: "search",
						label: __("Show search field", "project-prepper"),
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
		title: __("PP: Availability check", "project-prepper"),
		description: __("Check the availability of an item for a date range.", "project-prepper"),
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
						label: __("Item ID (empty = select field)", "project-prepper"),
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
		title: __("PP: Request form", "project-prepper"),
		description: __("Rental/project request form for visitors.", "project-prepper"),
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
						label: __("Show equipment selection", "project-prepper"),
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
