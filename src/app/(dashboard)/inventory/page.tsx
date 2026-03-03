"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import type { InventoryItem, Booking } from "@/types/database";
import { IconPlus, IconSearch, IconX, IconTrash, IconDownload, IconUpload, IconImage, IconActivity } from "@/components/ui/icons";
import { ExcelImport } from "@/components/inventory/excel-import";
import { InventoryDetailModal } from "@/components/inventory/inventory-detail-modal";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import * as XLSX from "xlsx";

// Booking-Info pro Inventar-Artikel (aggregiert)
interface BookingInfo {
  bookingId: string;
  projectName: string;
  quantity: number;
  status: string;
  dateFrom: string;
  dateTo: string;
}
interface ItemBookingData {
  bookedQty: number;
  bookings: BookingInfo[];
}

const conditionLabels: Record<InventoryItem["condition"], string> = {
  new: "Neu",
  good: "Gut",
  fair: "Befriedigend",
  poor: "Schlecht",
  broken: "Defekt",
  retired: "Ausgemustert",
};

const conditionStyles: Record<InventoryItem["condition"], { bg: string; color: string }> = {
  new: { bg: "var(--color-success-light)", color: "var(--color-success)" },
  good: { bg: "var(--color-success-light)", color: "var(--color-success)" },
  fair: { bg: "var(--color-warning-light)", color: "var(--color-warning)" },
  poor: { bg: "var(--color-warning-light)", color: "#ea580c" },
  broken: { bg: "var(--color-destructive-light)", color: "var(--color-destructive)" },
  retired: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)" },
};

// Kategorie Icons (Emoji als schnelle Lösung)
const categoryIcons: Record<string, string> = {
  Projektion: "📽",
  Licht: "💡",
  Effekte: "✨",
  Steuerung: "🎛",
  Video: "🖥",
  Audio: "🔊",
  Kabel: "🔌",
  Rigging: "🔧",
  Transport: "📦",
  "Zubehör": "🧰",
};

export default function InventoryPage() {
  const supabase = createClient();
  const { orgId } = useOrg();
  const [items, setItems] = useState<InventoryItem[]>([]);
  const [bookingMap, setBookingMap] = useState<Map<string, ItemBookingData>>(new Map());
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState("");
  const [loanFilter, setLoanFilter] = useState(false);
  const [search, setSearch] = useState("");
  const [showCreate, setShowCreate] = useState(false);
  const [showImport, setShowImport] = useState(false);
  const [selectedItem, setSelectedItem] = useState<InventoryItem | null>(null);
  const [saving, setSaving] = useState(false);

  // Formular-State
  const [formInventoryNumber, setFormInventoryNumber] = useState("");
  const [formName, setFormName] = useState("");
  const [formDescription, setFormDescription] = useState("");
  const [formCategory, setFormCategory] = useState("");
  const [formQuantity, setFormQuantity] = useState(1);
  const [formCondition, setFormCondition] = useState<InventoryItem["condition"]>("new");
  const [formCostPerDay, setFormCostPerDay] = useState(0);
  const [formLocation, setFormLocation] = useState("");

  // Kategorie-Kürzel für Inventarnummer
  const categoryPrefixes: Record<string, string> = {
    Projektion: "PRO", Licht: "LIC", Effekte: "EFF", Steuerung: "STR",
    Video: "VID", Audio: "AUD", Kabel: "KAB", Rigging: "RIG",
    Transport: "TRA", "Zubehör": "ZUB",
  };

  function generateNextNumber(category: string, existingItems: InventoryItem[]): string {
    const prefix = categoryPrefixes[category] || category.slice(0, 3).toUpperCase();
    const existing = existingItems
      .filter((i) => i.inventory_number?.startsWith(prefix + "-"))
      .map((i) => parseInt(i.inventory_number.split("-")[1], 10))
      .filter((n) => !isNaN(n));
    const next = existing.length > 0 ? Math.max(...existing) + 1 : 1;
    return `${prefix}-${String(next).padStart(3, "0")}`;
  }

  const loadItems = useCallback(async () => {
    if (!orgId) return;
    const [itemsRes, bookingsRes] = await Promise.all([
      supabase
        .from("inventory_items")
        .select("*")
        .eq("org_id", orgId)
        .order("category")
        .order("name"),
      // Aktive Buchungen laden (reserved oder checked_out)
      supabase
        .from("bookings")
        .select("id, inventory_item_id, quantity, status, date_from, date_to, projects(name)")
        .in("status", ["reserved", "checked_out"]),
    ]);

    if (itemsRes.data) setItems(itemsRes.data as InventoryItem[]);

    // Booking-Map aufbauen: itemId → { bookedQty, bookings[] }
    if (bookingsRes.data) {
      const today = new Date().toISOString().split("T")[0];
      const map = new Map<string, ItemBookingData>();

      for (const b of bookingsRes.data) {
        // Nur Buchungen zählen die heute aktiv sind ODER status=checked_out
        const isCheckedOut = b.status === "checked_out";
        const isInDateRange = b.date_from <= today && b.date_to >= today;
        if (!isCheckedOut && !isInDateRange) continue;

        const itemId = b.inventory_item_id;
        const proj = b.projects as unknown as { name: string } | { name: string }[] | null;
        const projectName = Array.isArray(proj) ? proj[0]?.name : proj?.name || "Unbekannt";
        const existing = map.get(itemId) || { bookedQty: 0, bookings: [] };
        existing.bookedQty += b.quantity;
        existing.bookings.push({
          bookingId: b.id,
          projectName,
          quantity: b.quantity,
          status: b.status,
          dateFrom: b.date_from,
          dateTo: b.date_to,
        });
        map.set(itemId, existing);
      }
      setBookingMap(map);
    }

    setLoading(false);
  }, [supabase, orgId]);

  useEffect(() => {
    loadItems();
  }, [loadItems]);

  // Realtime: Live-Synchronisation
  useRealtimeTable({
    table: "inventory_items",
    orgFilter: orgId || undefined,
    onDataChange: loadItems,
  });

  // Bookings Realtime: bei Änderungen neu laden
  useRealtimeTable({
    table: "bookings",
    onDataChange: loadItems,
  });

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    const invNumber = formInventoryNumber || generateNextNumber(formCategory || "Sonstiges", items);
    const { error } = await supabase.from("inventory_items").insert({
      inventory_number: invNumber,
      name: formName,
      description: formDescription || null,
      category: formCategory || "Sonstiges",
      quantity: formQuantity,
      condition: formCondition,
      cost_per_day: formCostPerDay,
      location: formLocation || null,
      org_id: orgId,
    });
    if (!error) {
      setFormInventoryNumber(""); setFormName(""); setFormDescription(""); setFormCategory("");
      setFormQuantity(1); setFormCondition("new"); setFormCostPerDay(0); setFormLocation("");
      setShowCreate(false);
      loadItems();
    }
    setSaving(false);
  }

  async function handleDelete(item: InventoryItem) {
    if (!confirm("Artikel wirklich löschen?")) return;
    // Storage-Datei löschen falls vorhanden
    if (item.image_url) {
      const path = item.image_url.split("/inventory-images/")[1];
      if (path) {
        await supabase.storage.from("inventory-images").remove([decodeURIComponent(path)]);
      }
    }
    await supabase.from("inventory_items").delete().eq("id", item.id);
    loadItems();
  }

  const categories = useMemo(() => [...new Set(items.map((i) => i.category))].sort(), [items]);

  // Anzahl ausgeliehener Items (für Filter-Pill)
  const loanedItemCount = useMemo(
    () => items.filter((i) => bookingMap.has(i.id)).length,
    [items, bookingMap]
  );
  const totalBookedQty = useMemo(
    () => Array.from(bookingMap.values()).reduce((sum, b) => sum + b.bookedQty, 0),
    [bookingMap]
  );

  const filtered = useMemo(() => {
    let result = items;
    if (loanFilter) result = result.filter((i) => bookingMap.has(i.id));
    if (filter) result = result.filter((i) => i.category === filter);
    if (search.trim()) {
      const q = search.toLowerCase();
      result = result.filter(
        (i) =>
          i.inventory_number?.toLowerCase().includes(q) ||
          i.name.toLowerCase().includes(q) ||
          i.description?.toLowerCase().includes(q) ||
          i.category.toLowerCase().includes(q) ||
          i.location?.toLowerCase().includes(q) ||
          i.purchased_by?.toLowerCase().includes(q)
      );
    }
    return result;
  }, [items, filter, loanFilter, search, bookingMap]);

  const totalQuantity = filtered.reduce((sum, i) => sum + i.quantity, 0);
  const totalValue = filtered.reduce((sum, i) => sum + Number(i.cost_per_day) * i.quantity, 0);

  function handleExportXLS() {
    const rows = filtered.map((item) => ({
      "Inv.-Nr.": item.inventory_number || "",
      Name: item.name,
      Beschreibung: item.description || "",
      Kategorie: item.category,
      Menge: item.quantity,
      Zustand: conditionLabels[item.condition] || item.condition,
      "Preis/Tag (€)": Number(item.cost_per_day),
      Lagerort: item.location || "",
      Eigentümer: item.owner || "",
      Pate: item.purchased_by || "",
    }));

    const ws = XLSX.utils.json_to_sheet(rows);

    // Spaltenbreiten
    ws["!cols"] = [
      { wch: 12 }, // Inv.-Nr.
      { wch: 30 }, // Name
      { wch: 30 }, // Beschreibung
      { wch: 16 }, // Kategorie
      { wch: 8 },  // Menge
      { wch: 14 }, // Zustand
      { wch: 14 }, // Preis/Tag
      { wch: 16 }, // Lagerort
      { wch: 16 }, // Eigentümer
      { wch: 16 }, // Pate
    ];

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Inventar");
    const dateStr = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, `Inventar_${dateStr}.xlsx`);
  }

  if (loading) {
    return (
      <div className="space-y-4">
        <div className="h-8 w-48 skeleton" />
        <div className="flex gap-2">{[1, 2, 3, 4, 5].map((i) => <div key={i} className="h-8 w-24 skeleton rounded-full" />)}</div>
        {[1, 2, 3, 4, 5, 6].map((i) => <div key={i} className="h-14 skeleton rounded-lg" />)}
      </div>
    );
  }

  return (
    <div className="animate-fadeIn">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold">Inventar</h1>
          <p className="text-sm mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
            {items.length} Artikel &middot; {totalQuantity} Teile gesamt
            {loanedItemCount > 0 && <> &middot; <span style={{ color: "var(--color-info)" }}>{loanedItemCount} ausgeliehen</span></>}
          </p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <button
            onClick={() => setShowImport(true)}
            className="flex items-center gap-2 px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-sm font-medium transition-colors"
            style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
            onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-muted)"}
            onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}
          >
            <IconUpload size={16} />
            <span className="hidden sm:inline">Excel-Import</span>
            <span className="sm:hidden">Import</span>
          </button>
          <button
            onClick={handleExportXLS}
            className="flex items-center gap-2 px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-sm font-medium transition-colors"
            style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
            onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-muted)"}
            onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}
          >
            <IconDownload size={16} />
            <span className="hidden sm:inline">Excel-Export</span>
            <span className="sm:hidden">Export</span>
          </button>
          <button
            onClick={() => setShowCreate(!showCreate)}
            className="flex items-center gap-2 px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-sm font-medium text-white transition-colors"
            style={{ background: "var(--color-primary)" }}
            onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-primary-hover)"}
            onMouseLeave={(e) => e.currentTarget.style.background = "var(--color-primary)"}
          >
            <IconPlus size={16} />
            <span className="hidden sm:inline">Neuer Artikel</span>
            <span className="sm:hidden">Neu</span>
          </button>
        </div>
      </div>

      {/* Create Form */}
      {showCreate && (
        <div
          className="mb-6 p-6 rounded-xl animate-slideDown"
          style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-md)", border: "1px solid var(--color-border)" }}
        >
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold">Neuer Artikel</h2>
            <button onClick={() => setShowCreate(false)} style={{ color: "var(--color-muted-foreground)" }}><IconX size={20} /></button>
          </div>
          <form onSubmit={handleCreate} className="space-y-4">
            <div className="grid grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1.5">Inventarnummer</label>
                <input type="text" value={formInventoryNumber}
                  onChange={(e) => setFormInventoryNumber(e.target.value.toUpperCase())}
                  className="w-full px-3 py-2 rounded-lg text-sm font-mono"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder={formCategory ? generateNextNumber(formCategory, items) : "z.B. PRO-001"} />
                <p className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                  Leer lassen für Auto-Vergabe
                </p>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Name</label>
                <input type="text" value={formName} onChange={(e) => setFormName(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="z.B. Beamer Epson EB-U50" required />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Kategorie</label>
                <input type="text" value={formCategory} onChange={(e) => setFormCategory(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="z.B. Licht, Projektion" list="categories" />
                <datalist id="categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">Beschreibung</label>
              <input type="text" value={formDescription} onChange={(e) => setFormDescription(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                placeholder="Kurze Beschreibung" />
            </div>
            <div className="grid grid-cols-4 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1.5">Menge</label>
                <input type="number" value={formQuantity} onChange={(e) => setFormQuantity(Number(e.target.value))} min={1}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }} />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Zustand</label>
                <select value={formCondition} onChange={(e) => setFormCondition(e.target.value as InventoryItem["condition"])}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}>
                  {Object.entries(conditionLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Preis/Tag (&euro;)</label>
                <input type="number" value={formCostPerDay} onChange={(e) => setFormCostPerDay(Number(e.target.value))} min={0} step={0.01}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }} />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Lagerort</label>
                <input type="text" value={formLocation} onChange={(e) => setFormLocation(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="z.B. Lager A" />
              </div>
            </div>
            <div className="flex gap-2 pt-2">
              <button type="submit" disabled={saving}
                className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}>
                {saving ? "Wird gespeichert..." : "Anlegen"}
              </button>
              <button type="button" onClick={() => setShowCreate(false)}
                className="px-4 py-2 rounded-lg text-sm" style={{ border: "1px solid var(--color-border)" }}>
                Abbrechen
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Search + Category Filter */}
      <div className="flex items-start gap-4 mb-5">
        <div className="relative flex-1 max-w-sm">
          <IconSearch size={16} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: "var(--color-muted-foreground)" } as React.CSSProperties} />
          <input
            type="text" value={search} onChange={(e) => setSearch(e.target.value)}
            placeholder="Artikel suchen..."
            className="w-full pl-9 pr-3 py-2 rounded-lg text-sm"
            style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }} />
          {search && (
            <button onClick={() => setSearch("")} className="absolute right-2 top-1/2 -translate-y-1/2" style={{ color: "var(--color-muted-foreground)" }}>
              <IconX size={14} />
            </button>
          )}
        </div>
      </div>

      {/* Status-Filter + Category Pills */}
      <div className="flex gap-2 mb-5 flex-wrap items-center">
        {/* Ausgeliehen-Filter */}
        {loanedItemCount > 0 && (
          <>
            <button
              onClick={() => setLoanFilter(!loanFilter)}
              className="px-3 py-1.5 rounded-full text-sm font-medium transition-all flex items-center gap-1.5"
              style={{
                background: loanFilter ? "var(--color-info)" : "var(--color-surface)",
                color: loanFilter ? "#fff" : "var(--color-info)",
                border: loanFilter ? "none" : "1px solid var(--color-info)",
              }}
            >
              <IconActivity size={13} />
              Ausgeliehen ({loanedItemCount})
            </button>
            <div
              className="w-px h-6"
              style={{ background: "var(--color-border-light)" }}
            />
          </>
        )}

        <button
          onClick={() => setFilter("")}
          className="px-3 py-1.5 rounded-full text-sm font-medium transition-all"
          style={{
            background: !filter ? "var(--color-primary)" : "var(--color-surface)",
            color: !filter ? "#fff" : "var(--color-muted-foreground)",
            border: !filter ? "none" : "1px solid var(--color-border-light)",
          }}
        >
          Alle ({items.length})
        </button>
          {categories.map((cat) => {
            const count = items.filter((i) => i.category === cat).length;
            return (
              <button
                key={cat}
                onClick={() => setFilter(filter === cat ? "" : cat)}
                className="px-3 py-1.5 rounded-full text-sm font-medium transition-all flex items-center gap-1"
                style={{
                  background: filter === cat ? "var(--color-primary)" : "var(--color-surface)",
                  color: filter === cat ? "#fff" : "var(--color-muted-foreground)",
                  border: filter === cat ? "none" : "1px solid var(--color-border-light)",
                }}
              >
                <span>{categoryIcons[cat] || "📁"}</span>
                {cat} ({count})
              </button>
            );
          })}
      </div>

      {/* Stats Row */}
      <div className="flex gap-4 mb-5">
        <div className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
          {filtered.length} Artikel &middot; {totalQuantity} Teile
          {totalBookedQty > 0 && (
            <> &middot; <span style={{ color: "var(--color-info)" }}>{totalBookedQty} ausgeliehen</span></>
          )}
          {" "}&middot; Tageswert: {totalValue.toFixed(0)} &euro;
        </div>
      </div>

      {/* Table */}
      {filtered.length === 0 ? (
        <div className="text-center py-16 rounded-xl" style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}>
          <p className="text-lg mb-1">{search ? "Keine Treffer" : "Noch keine Artikel"}</p>
          <p className="text-sm">{search ? `Kein Artikel für "${search}" gefunden` : "Lege deinen ersten Artikel an."}</p>
        </div>
      ) : (
        <div
          className="rounded-xl overflow-hidden"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)", boxShadow: "var(--shadow-sm)" }}
        >
          <div className="overflow-x-auto">
          <table className="w-full min-w-[900px]">
            <thead>
              <tr style={{ borderBottom: "1px solid var(--color-border-light)" }}>
                <th className="w-14 px-3 py-3"></th>
                <th className="text-left px-4 py-3 text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Inv.-Nr.</th>
                <th className="text-left px-4 py-3 text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Artikel</th>
                <th className="text-left px-4 py-3 text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Kategorie</th>
                <th className="text-center px-4 py-3 text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Menge</th>
                <th className="text-center px-4 py-3 text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Verfügbar</th>
                <th className="text-left px-4 py-3 text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Zustand</th>
                <th className="text-right px-4 py-3 text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>&euro;/Tag</th>
                <th className="text-left px-4 py-3 text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Pate</th>
                <th className="text-left px-4 py-3 text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Ort</th>
                <th className="w-10 px-4 py-3"></th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((item) => (
                <tr
                  key={item.id}
                  className="transition-colors group cursor-pointer"
                  style={{ borderBottom: "1px solid var(--color-border-light)" }}
                  onClick={() => setSelectedItem(item)}
                  onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-surface-hover)"}
                  onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}
                >
                  <td className="px-3 py-3.5">
                    {item.image_url ? (
                      <img
                        src={item.image_url}
                        alt={item.name}
                        className="w-10 h-10 rounded-lg object-cover"
                        style={{ border: "1px solid var(--color-border-light)" }}
                      />
                    ) : (
                      <div
                        className="w-10 h-10 rounded-lg flex items-center justify-center"
                        style={{ background: "var(--color-muted)" }}
                      >
                        <IconImage size={16} style={{ color: "var(--color-muted-foreground)" }} />
                      </div>
                    )}
                  </td>
                  <td className="px-4 py-3.5">
                    <span className="font-mono text-sm px-1.5 py-0.5 rounded"
                      style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                      {item.inventory_number}
                    </span>
                  </td>
                  <td className="px-4 py-3.5">
                    <div className="font-medium text-sm">{item.name}</div>
                    {item.description && (
                      <div className="text-xs mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
                        {item.description}
                      </div>
                    )}
                    {/* Ausleihe-Info */}
                    {bookingMap.has(item.id) && (() => {
                      const bd = bookingMap.get(item.id)!;
                      if (bd.bookings.length === 1) {
                        const b = bd.bookings[0];
                        return (
                          <div className="text-xs mt-1 flex items-center gap-1" style={{ color: "var(--color-info)" }}>
                            <IconActivity size={11} />
                            {b.projectName} ({b.quantity}x)
                          </div>
                        );
                      }
                      return (
                        <div className="text-xs mt-1 flex items-center gap-1" style={{ color: "var(--color-info)" }}>
                          <IconActivity size={11} />
                          {bd.bookings.length} Ausleihen aktiv
                        </div>
                      );
                    })()}
                  </td>
                  <td className="px-4 py-3.5">
                    <span className="text-sm flex items-center gap-1">
                      <span>{categoryIcons[item.category] || "📁"}</span>
                      {item.category}
                    </span>
                  </td>
                  <td className="px-4 py-3.5 text-center">
                    <span
                      className="inline-flex items-center justify-center w-8 h-6 rounded text-sm font-semibold"
                      style={{
                        background: item.quantity > 5 ? "var(--color-success-light)" : item.quantity > 1 ? "var(--color-info-light)" : "var(--color-warning-light)",
                        color: item.quantity > 5 ? "var(--color-success)" : item.quantity > 1 ? "var(--color-info)" : "var(--color-warning)",
                      }}
                    >
                      {item.quantity}
                    </span>
                  </td>
                  {/* Verfügbar-Spalte */}
                  <td className="px-4 py-3.5 text-center">
                    {(() => {
                      const booked = bookingMap.get(item.id)?.bookedQty || 0;
                      const available = Math.max(0, item.quantity - booked);
                      if (booked === 0) {
                        // Nichts ausgeliehen → grüner Haken
                        return (
                          <span
                            className="inline-flex items-center justify-center px-2 h-6 rounded text-xs font-semibold"
                            style={{ background: "var(--color-success-light)", color: "var(--color-success)" }}
                            title={`${item.quantity} verfügbar`}
                          >
                            {item.quantity} ✓
                          </span>
                        );
                      }
                      if (available === 0) {
                        // Alles ausgeliehen → Rot
                        return (
                          <span
                            className="inline-flex items-center justify-center px-2 h-6 rounded text-xs font-semibold"
                            style={{ background: "var(--color-destructive-light)", color: "var(--color-destructive)" }}
                            title={`0 von ${item.quantity} verfügbar`}
                          >
                            0 frei
                          </span>
                        );
                      }
                      // Teilweise ausgeliehen → Warning
                      return (
                        <span
                          className="inline-flex items-center justify-center px-2 h-6 rounded text-xs font-semibold"
                          style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}
                          title={`${available} von ${item.quantity} verfügbar`}
                        >
                          {available}/{item.quantity}
                        </span>
                      );
                    })()}
                  </td>
                  <td className="px-4 py-3.5">
                    <span
                      className="text-xs px-2 py-0.5 rounded-full font-medium"
                      style={conditionStyles[item.condition]}
                    >
                      {conditionLabels[item.condition]}
                    </span>
                  </td>
                  <td className="px-4 py-3.5 text-right text-sm tabular-nums">
                    {Number(item.cost_per_day).toFixed(2)} &euro;
                  </td>
                  <td className="px-4 py-3.5 text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                    {item.purchased_by || "–"}
                  </td>
                  <td className="px-4 py-3.5 text-sm" style={{ color: "var(--color-muted-foreground)" }}>
                    {item.location || "–"}
                  </td>
                  <td className="px-4 py-3.5">
                    <button
                      onClick={(e) => { e.stopPropagation(); handleDelete(item); }}
                      className="opacity-0 group-hover:opacity-100 p-1.5 rounded transition-opacity"
                      style={{ color: "var(--color-destructive)" }}
                      title="Löschen"
                    >
                      <IconTrash size={15} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        </div>
      )}

      {/* Excel Import Modal */}
      {showImport && (
        <ExcelImport
          existingItems={items}
          onClose={() => setShowImport(false)}
          onImportComplete={loadItems}
          categoryPrefixes={categoryPrefixes}
        />
      )}

      {/* Detail Modal */}
      {selectedItem && (
        <InventoryDetailModal
          item={selectedItem}
          onClose={() => setSelectedItem(null)}
          onItemUpdated={() => {
            loadItems();
            setSelectedItem(null);
          }}
        />
      )}
    </div>
  );
}
