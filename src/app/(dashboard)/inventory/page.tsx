"use client";

import { useState, useEffect, useCallback, useMemo, Suspense } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import { useOrg, useWorkspace } from "@/contexts/org-context";
import { useCurrentUser, hasPermission } from "@/hooks/use-current-user";
import type { InventoryItem, InventoryCategory, Booking } from "@/types/database";
import { IconPlus, IconSearch, IconX, IconTrash, IconDownload, IconUpload, IconImage, IconActivity, IconEdit, IconSave, IconHandshake, IconInventory, IconShield } from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";
import { logActivity } from "@/lib/activity-log";
import { ExcelImport } from "@/components/inventory/excel-import";
import { appConfirm } from "@/components/ui/confirm-dialog";
import { InventoryDetailModal } from "@/components/inventory/inventory-detail-modal";
import { ShareWithGroupModal } from "@/components/inventory/share-with-group-modal";
import { EquipmentLoansPanel } from "@/components/inventory/equipment-loans-panel";
import { FullShareModal } from "@/components/inventory/full-share-modal";
import { InventoryEarningsOverview } from "@/components/inventory/inventory-earnings-overview";
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

// Default-Kategorien (Fallback wenn DB noch leer)
const defaultCategories: { name: string; icon: string; prefix: string }[] = [
  { name: "Projektion", icon: "📽", prefix: "PRO" },
  { name: "Licht", icon: "💡", prefix: "LIC" },
  { name: "Effekte", icon: "✨", prefix: "EFF" },
  { name: "Steuerung", icon: "🎛", prefix: "STR" },
  { name: "Video", icon: "🖥", prefix: "VID" },
  { name: "Audio", icon: "🔊", prefix: "AUD" },
  { name: "Kabel", icon: "🔌", prefix: "KAB" },
  { name: "Rigging", icon: "🔧", prefix: "RIG" },
  { name: "Transport", icon: "📦", prefix: "TRA" },
  { name: "Zubehör", icon: "🧰", prefix: "ZUB" },
];

export default function InventoryPageWrapper() {
  return (
    <Suspense fallback={<div className="p-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>Laden...</div>}>
      <InventoryPage />
    </Suspense>
  );
}

function InventoryPage() {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const ownerId = currentUser?.id ?? null;
  // Backward-Compat: Aliase damit alter Code minimal angepasst werden muss
  const orgId = ownerId;
  // groupId für Group-Modus
  const { groupId, groupName, activeGroup } = useWorkspace();
  const searchParams = useSearchParams();
  const router = useRouter();
  const canEdit = hasPermission(currentUser, "inventory_edit");
  const canExport = hasPermission(currentUser, "excel_export");
  const canImport = hasPermission(currentUser, "excel_import");
  const [items, setItems] = useState<InventoryItem[]>([]);
  // Share-Map: itemId → [{groupId, groupName, requires_approval}]
  const [shareMap, setShareMap] = useState<
    Map<string, { groupId: string; groupName: string; requires_approval: boolean }[]>
  >(new Map());
  const [bookingMap, setBookingMap] = useState<Map<string, ItemBookingData>>(new Map());
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState("");
  const [loanFilter, setLoanFilter] = useState(false);
  const [search, setSearch] = useState("");
  const [pateFilter, setPateFilter] = useState(searchParams.get("pate") || "");
  const [showCreate, setShowCreate] = useState(false);
  const [showImport, setShowImport] = useState(false);
  const [selectedItem, setSelectedItem] = useState<InventoryItem | null>(null);
  const [shareItem, setShareItem] = useState<InventoryItem | null>(null);
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
  const [formDeviceName, setFormDeviceName] = useState("");
  const [formSerialNumber, setFormSerialNumber] = useState("");
  const [formPurchasePrice, setFormPurchasePrice] = useState<number | "">("");
  const [formDimensions, setFormDimensions] = useState("");
  const [formPowerWatts, setFormPowerWatts] = useState<number | "">("");
  const [formAccessories, setFormAccessories] = useState<string[]>([]);
  const [formAccessoryCustom, setFormAccessoryCustom] = useState("");
  const [formTags, setFormTags] = useState<string[]>([]);
  const [formTagCustom, setFormTagCustom] = useState("");
  const [formCustomField, setFormCustomField] = useState("");
  const [formManufacturerUrl, setFormManufacturerUrl] = useState("");
  const [formManualUrl, setFormManualUrl] = useState("");
  const [showCreateDetails, setShowCreateDetails] = useState(false);
  const [showCategoryManager, setShowCategoryManager] = useState(false);
  const [showLoans, setShowLoans] = useState(false);
  const [showFullShare, setShowFullShare] = useState(false);
  const [showEarningsOverview, setShowEarningsOverview] = useState(false);

  // Dynamische Kategorien aus DB
  const [dbCategories, setDbCategories] = useState<InventoryCategory[]>([]);

  // Profile-Map für Eigentumsanteile
  const [profileMap, setProfileMap] = useState<Map<string, string>>(new Map());

  // Computed: Icon- und Prefix-Maps aus DB-Kategorien
  const categoryIcons = useMemo(() => {
    const map: Record<string, string> = {};
    for (const c of dbCategories) map[c.name] = c.icon;
    return map;
  }, [dbCategories]);

  const categoryPrefixes = useMemo(() => {
    const map: Record<string, string> = {};
    for (const c of dbCategories) map[c.name] = c.prefix;
    return map;
  }, [dbCategories]);

  function generateNextNumber(category: string, existingItems: InventoryItem[]): string {
    const prefix = categoryPrefixes[category] || category.slice(0, 3).toUpperCase();
    // Im Group-Modus → Group-Suffix (z.B. DKS), im Solo-Modus → User-Suffix (z.B. MOT)
    const suffix = activeGroup?.inventorySuffix || currentUser?.inventorySuffix || null;
    const existing = existingItems
      .filter((i) => i.inventory_number?.startsWith(prefix + "-"))
      .map((i) => parseInt(i.inventory_number.split("-")[1], 10))
      .filter((n) => !isNaN(n));
    const next = existing.length > 0 ? Math.max(...existing) + 1 : 1;
    const base = `${prefix}-${String(next).padStart(3, "0")}`;
    return suffix ? `${base}-${suffix}` : base;
  }

  const loadItems = useCallback(async () => {
    if (!ownerId) return;

    // Im Group-Modus: group-owned Items + Items die fremde Gruppen mit uns teilen
    // Im Solo-Modus: nur eigene Items (owner_profile_id)
    let crossGroupItemIds: string[] = [];
    if (groupId) {
      const { data: shareRows } = await supabase
        .from("inventory_group_shares")
        .select("inventory_item_id")
        .eq("group_id", groupId)
        .is("revoked_at", null);
      crossGroupItemIds = (shareRows || []).map((r) => r.inventory_item_id);
    }

    const itemsQuery = groupId
      ? crossGroupItemIds.length > 0
        ? supabase
            .from("inventory_items")
            .select("*")
            .or(`owner_group_id.eq.${groupId},id.in.(${crossGroupItemIds.join(",")})`)
            .order("category")
            .order("name")
        : supabase
            .from("inventory_items")
            .select("*")
            .eq("owner_group_id", groupId)
            .order("category")
            .order("name")
      : supabase
          .from("inventory_items")
          .select("*")
          .eq("owner_profile_id", ownerId)
          .order("category")
          .order("name");

    const [itemsRes, bookingsRes] = await Promise.all([
      itemsQuery,
      supabase
        .from("bookings")
        .select("id, inventory_item_id, quantity, status, date_from, date_to, projects(name)")
        .in("status", ["reserved", "checked_out"]),
    ]);

    if (itemsRes.data) {
      setItems(itemsRes.data as InventoryItem[]);

      // Share-Map fuer diese Items laden
      const ids = (itemsRes.data as InventoryItem[]).map((x) => x.id);
      if (ids.length > 0) {
        const { data: shares } = await supabase
          .from("inventory_group_shares")
          .select("inventory_item_id, group_id, requires_approval, groups(name)")
          .in("inventory_item_id", ids)
          .is("revoked_at", null);
        const map = new Map<string, { groupId: string; groupName: string; requires_approval: boolean }[]>();
        for (const s of shares || []) {
          const grp = s.groups as unknown as { name: string } | { name: string }[] | null;
          const groupName = Array.isArray(grp) ? grp[0]?.name : grp?.name;
          const arr = map.get(s.inventory_item_id) || [];
          arr.push({
            groupId: s.group_id,
            groupName: groupName || "Gruppe",
            requires_approval: !!s.requires_approval,
          });
          map.set(s.inventory_item_id, arr);
        }
        setShareMap(map);
      } else {
        setShareMap(new Map());
      }
    }

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
  }, [supabase, ownerId, groupId]);

  useEffect(() => {
    loadItems();
  }, [loadItems]);

  // Kategorien laden (+ Auto-Seed falls leer)
  // Group-Modus: group-owned. Solo-Modus: user-owned.
  const loadCategories = useCallback(async () => {
    if (!ownerId) return;
    const query = groupId
      ? supabase.from("inventory_categories").select("*").eq("owner_group_id", groupId)
      : supabase.from("inventory_categories").select("*").eq("owner_profile_id", ownerId);
    const { data } = await query.order("sort_order").order("name");
    if (data && data.length > 0) {
      setDbCategories(data);
    } else if (data && data.length === 0) {
      // Auto-Seed
      const seeds = defaultCategories.map((c, i) => ({
        owner_profile_id: groupId ? null : ownerId,
        owner_group_id: groupId ?? null,
        name: c.name,
        icon: c.icon,
        prefix: c.prefix,
        sort_order: i,
      }));
      const { data: inserted } = await supabase
        .from("inventory_categories")
        .insert(seeds)
        .select();
      if (inserted) setDbCategories(inserted);
    }
  }, [supabase, ownerId, groupId]);

  useEffect(() => {
    loadCategories();
  }, [loadCategories]);

  // Profile-Map: Im Solo-Modus nur eigener User. (Group-Member kommen in Phase 4)
  useEffect(() => {
    if (!currentUser) return;
    const map = new Map<string, string>();
    map.set(currentUser.id, currentUser.name);
    setProfileMap(map);
  }, [currentUser]);

  // Realtime: Kategorien (einfach alle, RLS schraenkt ein)
  useRealtimeTable({
    table: "inventory_categories",
    onDataChange: loadCategories,
  });

  // Realtime: Live-Synchronisation
  useRealtimeTable({
    table: "inventory_items",
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
      owner_profile_id: groupId ? null : ownerId,
      owner_group_id: groupId ?? null,
      device_name: formDeviceName || null,
      serial_number: formSerialNumber || null,
      purchase_price: formPurchasePrice !== "" ? Number(formPurchasePrice) : null,
      dimensions: formDimensions || null,
      power_watts: formPowerWatts !== "" ? Number(formPowerWatts) : null,
      accessories: formAccessories.length > 0 ? formAccessories : null,
      tags: formTags,
      custom_field: formCustomField || null,
      manufacturer_url: formManufacturerUrl || null,
      manual_url: formManualUrl || null,
    });
    if (error) {
      showToast("Fehler beim Erstellen: " + error.message, "error");
    } else {
      setFormInventoryNumber(""); setFormName(""); setFormDescription(""); setFormCategory("");
      setFormQuantity(1); setFormCondition("new"); setFormCostPerDay(0); setFormLocation("");
      setFormDeviceName(""); setFormSerialNumber(""); setFormPurchasePrice("");
      setFormDimensions(""); setFormPowerWatts(""); setFormAccessories([]);
      setFormAccessoryCustom(""); setFormTags([]); setFormTagCustom(""); setFormCustomField("");
      setFormManufacturerUrl(""); setFormManualUrl(""); setShowCreateDetails(false);
      setShowCreate(false);
      loadItems();
      showToast("Artikel erstellt", "success");
      // logActivity entfaellt während Refactor (org_activity_log noch nicht migriert)
    }
    setSaving(false);
  }

  async function handleDelete(item: InventoryItem) {
    if (!(await appConfirm("Artikel wirklich löschen?", { variant: "danger", confirmLabel: "Löschen" }))) return;
    // Storage-Datei löschen falls vorhanden
    if (item.image_url) {
      const path = item.image_url.split("/inventory-images/")[1];
      if (path) {
        await supabase.storage.from("inventory-images").remove([decodeURIComponent(path)]);
      }
    }
    const { error } = await supabase.from("inventory_items").delete().eq("id", item.id);
    if (error) {
      showToast("Fehler beim Löschen: " + error.message, "error");
    } else {
      loadItems();
      showToast("Artikel gelöscht", "success");
      // logActivity entfaellt während Refactor
    }
  }

  // Kategorien: DB-Kategorien in Sortierreihenfolge, plus unbekannte aus Items
  const categories = useMemo(() => {
    const dbNames = dbCategories.map((c) => c.name);
    const itemCats = [...new Set(items.map((i) => i.category))];
    const extra = itemCats.filter((c) => !dbNames.includes(c)).sort();
    return [...dbNames, ...extra];
  }, [items, dbCategories]);

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
    if (pateFilter) result = result.filter((i) => i.purchased_by === pateFilter);
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
          i.purchased_by?.toLowerCase().includes(q) ||
          i.device_name?.toLowerCase().includes(q) ||
          i.serial_number?.toLowerCase().includes(q) ||
          i.custom_field?.toLowerCase().includes(q) ||
          i.accessories?.some((a) => a.toLowerCase().includes(q)) ||
          i.tags?.some((t) => t.toLowerCase().includes(q)) ||
          i.manufacturer_url?.toLowerCase().includes(q) ||
          i.manual_url?.toLowerCase().includes(q)
      );
    }
    return result;
  }, [items, pateFilter, filter, loanFilter, search, bookingMap]);

  const totalQuantity = filtered.reduce((sum, i) => sum + i.quantity, 0);
  const totalValue = filtered.reduce((sum, i) => sum + Number(i.cost_per_day) * i.quantity, 0);

  // Duplicate-Hint im Anlegen-Dialog: aehnliche Items (Name, Tags, device_name)
  const duplicateCandidates = useMemo(() => {
    const q = formName.trim().toLowerCase();
    if (q.length < 3) return [];
    return items
      .filter((i) =>
        i.name.toLowerCase().includes(q) ||
        i.device_name?.toLowerCase().includes(q) ||
        i.tags?.some((t) => t.toLowerCase().includes(q))
      )
      .slice(0, 5);
  }, [formName, items]);

  function handleExportXLS() {
    const rows = filtered.map((item) => ({
      "Inv.-Nr.": item.inventory_number || "",
      Name: item.name,
      "Gerätebezeichnung": item.device_name || "",
      Beschreibung: item.description || "",
      Kategorie: item.category,
      Seriennummer: item.serial_number || "",
      Menge: item.quantity,
      Zustand: conditionLabels[item.condition] || item.condition,
      "Kaufpreis (€)": item.purchase_price != null ? Number(item.purchase_price) : "",
      "Preis/Tag (€)": Number(item.cost_per_day),
      "Abmaße": item.dimensions || "",
      "Leistung (W)": item.power_watts != null ? item.power_watts : "",
      "Zubehör": item.accessories?.join(", ") || "",
      Lagerort: item.location || "",
      Eigentümer: item.owner || "",
      Pate: item.purchased_by || "",
      Freifeld: item.custom_field || "",
      "Hersteller-Link": item.manufacturer_url || "",
      "Manual-Link": item.manual_url || "",
    }));

    const ws = XLSX.utils.json_to_sheet(rows);

    // Spaltenbreiten
    ws["!cols"] = [
      { wch: 12 }, // Inv.-Nr.
      { wch: 30 }, // Name
      { wch: 24 }, // Gerätebezeichnung
      { wch: 30 }, // Beschreibung
      { wch: 16 }, // Kategorie
      { wch: 20 }, // Seriennummer
      { wch: 8 },  // Menge
      { wch: 14 }, // Zustand
      { wch: 14 }, // Kaufpreis
      { wch: 14 }, // Preis/Tag
      { wch: 18 }, // Abmaße
      { wch: 12 }, // Leistung
      { wch: 28 }, // Zubehör
      { wch: 16 }, // Lagerort
      { wch: 16 }, // Eigentümer
      { wch: 16 }, // Pate
      { wch: 24 }, // Freifeld
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
            onClick={() => setShowLoans(true)}
            className="flex items-center gap-2 px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-sm font-medium transition-colors"
            style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
            onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-muted)"}
            onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}
          >
            <IconHandshake size={16} />
            <span className="hidden sm:inline">Leihgaben</span>
          </button>
          {canEdit && (
            <button
              onClick={() => setShowFullShare(true)}
              className="px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-sm font-medium transition-colors"
              style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
              onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-muted)")}
              onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
            >
              Inventar freigeben
            </button>
          )}
          <button
            onClick={() => setShowEarningsOverview(true)}
            className="px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-sm font-medium transition-colors"
            style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
            onMouseEnter={(e) => (e.currentTarget.style.background = "var(--color-muted)")}
            onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
          >
            Auswertung
          </button>
          {canEdit && (
            <button
              onClick={() => setShowCategoryManager(true)}
              className="flex items-center gap-2 px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg text-sm font-medium transition-colors"
              style={{ border: "1px solid var(--color-border)", color: "var(--color-foreground)" }}
              onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-muted)"}
              onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}
            >
              <IconEdit size={16} />
              <span className="hidden sm:inline">Kategorien</span>
            </button>
          )}
          {canImport && (
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
          )}
          {canExport && (
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
          )}
          {canEdit && (
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
          )}
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
                {duplicateCandidates.length > 0 && (
                  <div className="mt-2 px-3 py-2 rounded-lg text-xs"
                    style={{ background: "var(--color-warning-light)", color: "var(--color-warning)", border: "1px solid var(--color-warning)" }}>
                    <div className="font-medium mb-1">&Auml;hnliche Eintr&auml;ge schon vorhanden:</div>
                    <ul className="space-y-0.5">
                      {duplicateCandidates.map((c) => (
                        <li key={c.id}>
                          &middot; {c.inventory_number ? `${c.inventory_number} — ` : ""}{c.name}
                          {c.device_name ? ` (${c.device_name})` : ""}
                        </li>
                      ))}
                    </ul>
                    <div className="mt-1 opacity-80">Pr&uuml;fe, ob du das Item ggf. nur erg&auml;nzen statt neu anlegen solltest.</div>
                  </div>
                )}
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
            {/* Aufklappbare Gerätedetails */}
            <div>
              <button
                type="button"
                onClick={() => setShowCreateDetails(!showCreateDetails)}
                className="text-sm font-medium flex items-center gap-1 transition-colors"
                style={{ color: "var(--color-primary)" }}
              >
                <span style={{ display: "inline-block", transform: showCreateDetails ? "rotate(90deg)" : "rotate(0deg)", transition: "transform 150ms" }}>▶</span>
                Weitere Details (optional)
              </button>
            </div>
            {showCreateDetails && (
              <div className="space-y-4 pl-0 animate-slideDown">
                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <label className="block text-sm font-medium mb-1.5">Gerätebezeichnung</label>
                    <input type="text" value={formDeviceName} onChange={(e) => setFormDeviceName(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                      placeholder="z.B. EB-U50" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1.5">Seriennummer</label>
                    <input type="text" value={formSerialNumber} onChange={(e) => setFormSerialNumber(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm font-mono"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                      placeholder="z.B. SN-12345678" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1.5">Kaufpreis (&euro;)</label>
                    <input type="number" value={formPurchasePrice} onChange={(e) => setFormPurchasePrice(e.target.value === "" ? "" : Number(e.target.value))}
                      min={0} step={0.01}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                      placeholder="0.00" />
                  </div>
                </div>
                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <label className="block text-sm font-medium mb-1.5">Abma&szlig;e</label>
                    <input type="text" value={formDimensions} onChange={(e) => setFormDimensions(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                      placeholder="z.B. 60x40x30 cm" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1.5">Leistung (W)</label>
                    <input type="number" value={formPowerWatts} onChange={(e) => setFormPowerWatts(e.target.value === "" ? "" : Number(e.target.value))}
                      min={0}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                      placeholder="z.B. 500" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1.5">Freifeld</label>
                    <input type="text" value={formCustomField} onChange={(e) => setFormCustomField(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                      placeholder="Sonstige Infos" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1.5">Hersteller-Link</label>
                    <input type="url" value={formManufacturerUrl} onChange={(e) => setFormManufacturerUrl(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                      placeholder="https://..." />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1.5">Manual / Handbuch</label>
                    <input type="url" value={formManualUrl} onChange={(e) => setFormManualUrl(e.target.value)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                      placeholder="https://..." />
                  </div>
                </div>
                {/* Zubehör-Buttons */}
                <div>
                  <label className="block text-sm font-medium mb-1.5">Zubeh&ouml;r</label>
                  <div className="flex flex-wrap gap-2 mb-2">
                    {["Netzteil", "Tasche", "Kabel", "Adapter"].map((acc) => {
                      const isSelected = formAccessories.includes(acc);
                      return (
                        <button key={acc} type="button"
                          onClick={() => setFormAccessories((prev) => isSelected ? prev.filter((a) => a !== acc) : [...prev, acc])}
                          className="px-3 py-1.5 rounded-full text-xs font-medium transition-all"
                          style={{
                            background: isSelected ? "var(--color-primary)" : "var(--color-surface)",
                            color: isSelected ? "#fff" : "var(--color-muted-foreground)",
                            border: isSelected ? "none" : "1px solid var(--color-border)",
                          }}>
                          {isSelected ? "✓ " : ""}{acc}
                        </button>
                      );
                    })}
                  </div>
                  {/* Custom Tags */}
                  <div className="flex flex-wrap gap-1.5 mb-2">
                    {formAccessories.filter((a) => !["Netzteil", "Tasche", "Kabel", "Adapter"].includes(a)).map((acc) => (
                      <span key={acc} className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium"
                        style={{ background: "var(--color-info-light)", color: "var(--color-info)" }}>
                        {acc}
                        <button type="button" onClick={() => setFormAccessories((prev) => prev.filter((a) => a !== acc))} className="ml-0.5 hover:opacity-70">&times;</button>
                      </span>
                    ))}
                  </div>
                  <div className="flex gap-2">
                    <input type="text" value={formAccessoryCustom}
                      onChange={(e) => setFormAccessoryCustom(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === "Enter" && formAccessoryCustom.trim()) {
                          e.preventDefault();
                          if (!formAccessories.includes(formAccessoryCustom.trim())) {
                            setFormAccessories((prev) => [...prev, formAccessoryCustom.trim()]);
                          }
                          setFormAccessoryCustom("");
                        }
                      }}
                      className="flex-1 px-3 py-1.5 rounded-lg text-xs"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                      placeholder="Eigenes Zubeh&ouml;r..." />
                    <button type="button"
                      onClick={() => {
                        if (formAccessoryCustom.trim() && !formAccessories.includes(formAccessoryCustom.trim())) {
                          setFormAccessories((prev) => [...prev, formAccessoryCustom.trim()]);
                        }
                        setFormAccessoryCustom("");
                      }}
                      className="px-3 py-1.5 rounded-lg text-xs font-medium"
                      style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}>
                      +
                    </button>
                  </div>
                </div>
                {/* Tags / Suchbegriffe */}
                <div>
                  <label className="block text-sm font-medium mb-1.5">Tags / Suchbegriffe</label>
                  <p className="text-xs mb-2" style={{ color: "var(--color-muted-foreground)" }}>
                    Mehrere Begriffe, unter denen das Item gefunden werden soll (z.B. &bdquo;Beamer&ldquo;, &bdquo;Projektor&ldquo;, &bdquo;EB-U50&ldquo;).
                  </p>
                  <div className="flex flex-wrap gap-1.5 mb-2">
                    {formTags.map((t) => (
                      <span key={t} className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium"
                        style={{ background: "var(--color-info-light)", color: "var(--color-info)" }}>
                        #{t}
                        <button type="button" onClick={() => setFormTags((prev) => prev.filter((x) => x !== t))} className="ml-0.5 hover:opacity-70">&times;</button>
                      </span>
                    ))}
                  </div>
                  <div className="flex gap-2">
                    <input type="text" value={formTagCustom}
                      onChange={(e) => setFormTagCustom(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === "Enter" && formTagCustom.trim()) {
                          e.preventDefault();
                          const v = formTagCustom.trim();
                          if (!formTags.includes(v)) setFormTags((prev) => [...prev, v]);
                          setFormTagCustom("");
                        }
                      }}
                      className="flex-1 px-3 py-1.5 rounded-lg text-xs"
                      style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                      placeholder="Tag eingeben + Enter" />
                    <button type="button"
                      onClick={() => {
                        const v = formTagCustom.trim();
                        if (v && !formTags.includes(v)) setFormTags((prev) => [...prev, v]);
                        setFormTagCustom("");
                      }}
                      className="px-3 py-1.5 rounded-lg text-xs font-medium"
                      style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}>
                      +
                    </button>
                  </div>
                </div>
              </div>
            )}

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

      {/* Pate-Filter Banner */}
      {pateFilter && (
        <div
          className="flex items-center gap-2 px-4 py-2.5 rounded-xl mb-4"
          style={{
            background: "var(--color-info-light)",
            border: "1px solid var(--color-info)",
          }}
        >
          <IconInventory size={14} style={{ color: "var(--color-info)" }} />
          <span className="text-sm font-medium" style={{ color: "var(--color-info)" }}>
            Pate: {pateFilter}
          </span>
          <button
            onClick={() => {
              setPateFilter("");
              router.replace("/inventory", { scroll: false });
            }}
            className="ml-auto p-0.5 rounded hover:opacity-70 transition-opacity"
            style={{ color: "var(--color-info)" }}
            title="Filter entfernen"
          >
            <IconX size={14} />
          </button>
        </div>
      )}

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
          <table className="w-full">
            <thead>
              <tr style={{ borderBottom: "1px solid var(--color-border-light)" }}>
                <th className="w-14 px-3 py-3 hidden sm:table-cell"></th>
                <th className="text-left px-3 sm:px-4 py-3 text-xs sm:text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Artikel</th>
                <th className="text-left px-3 sm:px-4 py-3 text-xs sm:text-sm font-semibold uppercase tracking-wider hidden md:table-cell" style={{ color: "var(--color-muted-foreground)" }}>Kategorie</th>
                <th className="text-center px-2 sm:px-4 py-3 text-xs sm:text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Menge</th>
                <th className="text-center px-2 sm:px-4 py-3 text-xs sm:text-sm font-semibold uppercase tracking-wider hidden sm:table-cell" style={{ color: "var(--color-muted-foreground)" }}>Verfügbar</th>
                <th className="text-left px-3 sm:px-4 py-3 text-xs sm:text-sm font-semibold uppercase tracking-wider hidden lg:table-cell" style={{ color: "var(--color-muted-foreground)" }}>Zustand</th>
                <th className="text-right px-2 sm:px-4 py-3 text-xs sm:text-sm font-semibold uppercase tracking-wider hidden sm:table-cell" style={{ color: "var(--color-muted-foreground)" }}>&euro;/Tag</th>
                <th className="text-left px-3 sm:px-4 py-3 text-xs sm:text-sm font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Eigentum</th>
                <th className="text-left px-3 sm:px-4 py-3 text-xs sm:text-sm font-semibold uppercase tracking-wider hidden lg:table-cell" style={{ color: "var(--color-muted-foreground)" }}>Geteilt</th>
                {groupId && (
                  <th className="text-left px-3 sm:px-4 py-3 text-xs sm:text-sm font-semibold uppercase tracking-wider hidden xl:table-cell" style={{ color: "var(--color-muted-foreground)" }}>Pate</th>
                )}
                <th className="text-left px-3 sm:px-4 py-3 text-xs sm:text-sm font-semibold uppercase tracking-wider hidden xl:table-cell" style={{ color: "var(--color-muted-foreground)" }}>Ort</th>
                <th className="w-10 px-2 sm:px-4 py-3"></th>
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
                  {/* Bild — hidden on mobile */}
                  <td className="px-3 py-3.5 hidden sm:table-cell">
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
                  {/* Artikel — always visible, includes inv-nr on mobile */}
                  <td className="px-3 sm:px-4 py-3.5">
                    <div className="font-medium text-sm">{item.name}</div>
                    <div className="flex items-center gap-2 mt-0.5 flex-wrap">
                      <span className="font-mono text-xs px-1 py-0.5 rounded"
                        style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                        {item.inventory_number}
                      </span>
                      {/* Kategorie inline on mobile */}
                      <span className="text-xs md:hidden" style={{ color: "var(--color-muted-foreground)" }}>
                        {categoryIcons[item.category] || "📁"} {item.category}
                      </span>
                    </div>
                    {item.description && (
                      <div className="text-xs mt-0.5 hidden sm:block" style={{ color: "var(--color-muted-foreground)" }}>
                        {item.description}
                      </div>
                    )}
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
                  {/* Kategorie — hidden on mobile */}
                  <td className="px-3 sm:px-4 py-3.5 hidden md:table-cell">
                    <span className="text-sm flex items-center gap-1">
                      <span>{categoryIcons[item.category] || "📁"}</span>
                      {item.category}
                    </span>
                  </td>
                  {/* Menge */}
                  <td className="px-2 sm:px-4 py-3.5 text-center">
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
                  {/* Verfügbar — hidden on mobile */}
                  <td className="px-2 sm:px-4 py-3.5 text-center hidden sm:table-cell">
                    {(() => {
                      const booked = bookingMap.get(item.id)?.bookedQty || 0;
                      const available = Math.max(0, item.quantity - booked);
                      if (booked === 0) {
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
                  {/* Zustand — hidden on mobile+tablet */}
                  <td className="px-3 sm:px-4 py-3.5 hidden lg:table-cell">
                    <span
                      className="text-xs px-2 py-0.5 rounded-full font-medium"
                      style={conditionStyles[item.condition]}
                    >
                      {conditionLabels[item.condition]}
                    </span>
                  </td>
                  {/* €/Tag — hidden on mobile */}
                  <td className="px-2 sm:px-4 py-3.5 text-right text-sm tabular-nums hidden sm:table-cell">
                    {Number(item.cost_per_day).toFixed(2)} &euro;
                  </td>
                  {/* Eigentum — always visible */}
                  <td className="px-3 sm:px-4 py-3.5">
                    {item.ownership_shares && item.ownership_shares.length > 0 ? (
                      <div className="flex flex-wrap gap-1" title={
                        (item.ownership_shares as any[]).map((s: any) =>
                          `${profileMap.get(s.profile_id) || "?"}: ${s.percentage}%`
                        ).join(", ")
                      }>
                        {(item.ownership_shares as any[]).map((share: any) => {
                          const name = profileMap.get(share.profile_id) || "?";
                          const parts = name.split(" ");
                          const short = parts.length > 1
                            ? parts.map((w: string) => w[0]).join("").toUpperCase().slice(0, 2)
                            : name.slice(0, 3);
                          return (
                            <span
                              key={share.profile_id}
                              className="inline-flex items-center gap-1 text-xs px-1.5 py-0.5 rounded-full font-medium"
                              style={{ background: "var(--color-primary-light, rgba(99,102,241,0.1))", color: "var(--color-primary)" }}
                              title={`${name}: ${share.percentage}%`}
                            >
                              <span className="font-semibold">{short}</span>
                              <span className="tabular-nums">{share.percentage}%</span>
                            </span>
                          );
                        })}
                      </div>
                    ) : item.owner_profile_id ? (
                      <span
                        className="inline-flex items-center gap-1 text-xs px-1.5 py-0.5 rounded-full"
                        style={{ background: "var(--color-muted)", color: "var(--color-foreground)" }}
                        title="Eigentümer"
                      >
                        👤 {profileMap.get(item.owner_profile_id) || "—"}
                      </span>
                    ) : groupId && groupName ? (
                      <span
                        className="inline-flex items-center gap-1 text-xs px-1.5 py-0.5 rounded-full"
                        style={{ background: "var(--color-primary-light)", color: "var(--color-primary)" }}
                        title="Gruppen-Eigentum"
                      >
                        🛡️ {groupName}
                      </span>
                    ) : (
                      <span className="text-xs italic" style={{ color: "var(--color-muted-foreground)" }}>
                        —
                      </span>
                    )}
                  </td>
                  {/* Geteilt — hidden on mobile/tablet */}
                  <td className="px-3 sm:px-4 py-3.5 hidden lg:table-cell">
                    {(() => {
                      // Im Group-Kontext: eigene Gruppe rausfiltern (ist ja schon Kontext)
                      const allShares = shareMap.get(item.id) || [];
                      const sh = groupId ? allShares.filter((s) => s.groupId !== groupId) : allShares;
                      if (sh.length === 0) {
                        return (
                          <span className="text-xs italic" style={{ color: "var(--color-muted-foreground)" }}>
                            {groupId ? "—" : "nicht geteilt"}
                          </span>
                        );
                      }
                      return (
                        <div className="flex flex-wrap gap-1">
                          {sh.map((s) => (
                            <span
                              key={s.groupId}
                              className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium"
                              style={{
                                background: "var(--color-primary-light)",
                                color: "var(--color-primary)",
                              }}
                              title={
                                s.requires_approval
                                  ? `Geteilt mit ${s.groupName} · Zusage erforderlich`
                                  : `Geteilt mit ${s.groupName} · frei verfügbar`
                              }
                            >
                              🛡️ {s.groupName}
                              {s.requires_approval && <span>🔒</span>}
                            </span>
                          ))}
                        </div>
                      );
                    })()}
                  </td>
                  {/* Pate — nur im Group-Modus sichtbar */}
                  {groupId && (
                    <td className="px-3 sm:px-4 py-3.5 text-sm hidden xl:table-cell" style={{ color: "var(--color-muted-foreground)" }}>
                      {item.purchased_by || "–"}
                    </td>
                  )}
                  {/* Ort — hidden until xl */}
                  <td className="px-3 sm:px-4 py-3.5 text-sm hidden xl:table-cell" style={{ color: "var(--color-muted-foreground)" }}>
                    {item.location || "–"}
                  </td>
                  <td className="px-2 sm:px-4 py-3.5">
                    <div className="flex items-center gap-1">
                      {canEdit && (
                        <button
                          onClick={(e) => { e.stopPropagation(); setShareItem(item); }}
                          className="opacity-0 group-hover:opacity-100 p-1.5 rounded transition-opacity"
                          style={{ color: "var(--color-success)" }}
                          title="Für Gruppe freigeben"
                        >
                          <IconShield size={15} />
                        </button>
                      )}
                      {canEdit && (
                        <button
                          onClick={(e) => { e.stopPropagation(); handleDelete(item); }}
                          className="opacity-0 group-hover:opacity-100 p-1.5 rounded transition-opacity"
                          style={{ color: "var(--color-destructive)" }}
                          title="Löschen"
                        >
                          <IconTrash size={15} />
                        </button>
                      )}
                    </div>
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
          ownerProfileId={groupId ? null : ownerId}
          ownerGroupId={groupId}
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

      {/* Share-with-Group Modal */}
      {shareItem && (
        <ShareWithGroupModal
          item={shareItem}
          onClose={() => setShareItem(null)}
        />
      )}

      {/* Kategorie-Verwaltungs-Modal */}
      {showCategoryManager && (
        <CategoryManagerModal
          categories={dbCategories}
          ownerProfileId={groupId ? null : ownerId}
          ownerGroupId={groupId}
          onClose={() => setShowCategoryManager(false)}
          onUpdated={loadCategories}
        />
      )}

      {/* Leihgaben-Panel */}
      <EquipmentLoansPanel
        show={showLoans}
        onClose={() => setShowLoans(false)}
      />

      {showFullShare && <FullShareModal onClose={() => setShowFullShare(false)} />}

      {showEarningsOverview && (
        <InventoryEarningsOverview
          items={items}
          onClose={() => setShowEarningsOverview(false)}
        />
      )}
    </div>
  );
}

// === Kategorie-Verwaltungs-Modal ===
function CategoryManagerModal({
  categories,
  ownerProfileId,
  ownerGroupId,
  onClose,
  onUpdated,
}: {
  categories: InventoryCategory[];
  ownerProfileId: string | null;
  ownerGroupId: string | null;
  onClose: () => void;
  onUpdated: () => void;
}) {
  const supabase = createClient();
  const [items, setItems] = useState(
    categories.map((c) => ({ ...c, _dirty: false }))
  );
  const [newName, setNewName] = useState("");
  const [newIcon, setNewIcon] = useState("📁");
  const [newPrefix, setNewPrefix] = useState("");
  const [saving, setSaving] = useState(false);

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-background)",
  };

  async function handleAdd() {
    if (!newName.trim()) return;
    setSaving(true);
    const prefix = newPrefix.trim() || newName.trim().slice(0, 3).toUpperCase();
    const { data } = await supabase
      .from("inventory_categories")
      .insert({
        owner_profile_id: ownerProfileId,
        owner_group_id: ownerGroupId,
        name: newName.trim(),
        icon: newIcon,
        prefix,
        sort_order: items.length,
      })
      .select()
      .single();
    if (data) {
      setItems((prev) => [...prev, { ...data, _dirty: false }]);
      setNewName("");
      setNewIcon("📁");
      setNewPrefix("");
      onUpdated();
    }
    setSaving(false);
  }

  async function handleUpdate(id: string) {
    const item = items.find((i) => i.id === id);
    if (!item) return;
    await supabase
      .from("inventory_categories")
      .update({ name: item.name, icon: item.icon, prefix: item.prefix, sort_order: item.sort_order })
      .eq("id", id);
    setItems((prev) => prev.map((i) => (i.id === id ? { ...i, _dirty: false } : i)));
    onUpdated();
  }

  async function handleDelete(id: string) {
    await supabase.from("inventory_categories").delete().eq("id", id);
    setItems((prev) => prev.filter((i) => i.id !== id));
    onUpdated();
  }

  function updateField(id: string, field: "name" | "icon" | "prefix", value: string) {
    setItems((prev) =>
      prev.map((i) => (i.id === id ? { ...i, [field]: value, _dirty: true } : i))
    );
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background: "rgba(0,0,0,0.5)" }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div
        className="w-full max-w-lg rounded-xl overflow-hidden flex flex-col"
        style={{
          background: "var(--color-surface)",
          boxShadow: "var(--shadow-lg)",
          maxHeight: "80vh",
        }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between px-6 py-4"
          style={{ borderBottom: "1px solid var(--color-border-light)" }}
        >
          <h2 className="text-lg font-bold">Kategorien verwalten</h2>
          <button onClick={onClose} className="p-1.5 rounded-lg" style={{ color: "var(--color-muted-foreground)" }}>
            <IconX size={20} />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-6 py-4 space-y-2">
          {items.map((cat) => (
            <div
              key={cat.id}
              className="flex items-center gap-2 px-3 py-2 rounded-lg"
              style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
            >
              <input
                type="text"
                value={cat.icon}
                onChange={(e) => updateField(cat.id, "icon", e.target.value)}
                className="w-10 text-center px-1 py-1 rounded text-sm"
                style={inputStyle}
                title="Icon (Emoji)"
              />
              <input
                type="text"
                value={cat.name}
                onChange={(e) => updateField(cat.id, "name", e.target.value)}
                className="flex-1 px-2 py-1 rounded text-sm"
                style={inputStyle}
                placeholder="Name"
              />
              <input
                type="text"
                value={cat.prefix}
                onChange={(e) => updateField(cat.id, "prefix", e.target.value.toUpperCase())}
                className="w-16 px-2 py-1 rounded text-sm font-mono text-center"
                style={inputStyle}
                placeholder="PRE"
                maxLength={4}
                title="Prefix für Inventarnummer"
              />
              {cat._dirty && (
                <button
                  onClick={() => handleUpdate(cat.id)}
                  className="p-1 rounded transition-colors"
                  style={{ color: "var(--color-primary)" }}
                  title="Speichern"
                >
                  <IconSave size={14} />
                </button>
              )}
              <button
                onClick={() => handleDelete(cat.id)}
                className="p-1 rounded transition-colors"
                style={{ color: "var(--color-destructive)" }}
                title="Löschen"
              >
                <IconTrash size={14} />
              </button>
            </div>
          ))}

          {/* Neue Kategorie */}
          <div
            className="flex items-center gap-2 px-3 py-2 rounded-lg mt-3"
            style={{ border: "2px dashed var(--color-border)" }}
          >
            <input
              type="text"
              value={newIcon}
              onChange={(e) => setNewIcon(e.target.value)}
              className="w-10 text-center px-1 py-1 rounded text-sm"
              style={inputStyle}
              placeholder="📁"
            />
            <input
              type="text"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); handleAdd(); } }}
              className="flex-1 px-2 py-1 rounded text-sm"
              style={inputStyle}
              placeholder="Neue Kategorie..."
            />
            <input
              type="text"
              value={newPrefix}
              onChange={(e) => setNewPrefix(e.target.value.toUpperCase())}
              className="w-16 px-2 py-1 rounded text-sm font-mono text-center"
              style={inputStyle}
              placeholder="PRE"
              maxLength={4}
            />
            <button
              onClick={handleAdd}
              disabled={!newName.trim() || saving}
              className="p-1.5 rounded-lg text-white disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
              title="Hinzufügen"
            >
              <IconPlus size={14} />
            </button>
          </div>
        </div>

        {/* Footer */}
        <div
          className="flex justify-end px-6 py-3"
          style={{ borderTop: "1px solid var(--color-border-light)" }}
        >
          <button
            onClick={onClose}
            className="px-4 py-2 rounded-lg text-sm font-medium"
            style={{ border: "1px solid var(--color-border)" }}
          >
            Schließen
          </button>
        </div>
      </div>
    </div>
  );
}
