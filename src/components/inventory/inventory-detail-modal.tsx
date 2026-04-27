"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg, useWorkspace } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import type { InventoryItem } from "@/types/database";
import type { InventoryUnit } from "@/types/database";
import { IconX, IconSave, IconExternalLink, IconHash, IconPlus, IconTrash } from "@/components/ui/icons";
import { DateInput } from "@/components/ui/date-input";
import { InventoryImageUpload } from "@/components/inventory/inventory-image-upload";
import { LoanRequestModal } from "@/components/inventory/loan-request-modal";

// Preset-Bedingungen für Group-Sharing (Tags)
const CONDITION_PRESETS: { value: string; label: string; description: string }[] = [
  { value: "free_use", label: "Zur freien Verfügung", description: "Mitglieder buchen ohne Nachfrage" },
  { value: "owner_pickup", label: "Nur vom Eigentümer entnehmbar", description: "Eigentümer übergibt physisch" },
  { value: "group_projects_only", label: "Nur für Gruppen-Projekte", description: "Nicht für Solo-Projekte von Mitgliedern" },
  { value: "rental_fee", label: "Gegen Leihgebühr", description: "Tagessatz wird abgerechnet" },
  { value: "deposit_required", label: "Mit Kaution", description: "Hinterlegungspflicht" },
  { value: "non_commercial", label: "Kein kommerzieller Einsatz", description: "Nur Non-Profit" },
  { value: "instruction_required", label: "Vorherige Einweisung", description: "Einweisung vor erster Nutzung" },
  { value: "expert_only", label: "Nur mit Fachkunde", description: "Qualifikation erforderlich" },
];

const conditionLabels: Record<InventoryItem["condition"], string> = {
  new: "Neu",
  good: "Gut",
  fair: "Befriedigend",
  poor: "Schlecht",
  broken: "Defekt",
  retired: "Ausgemustert",
};

interface InventoryDetailModalProps {
  item: InventoryItem;
  onClose: () => void;
  onItemUpdated: () => void;
}

export function InventoryDetailModal({
  item,
  onClose,
  onItemUpdated,
}: InventoryDetailModalProps) {
  const supabase = createClient();
  const { orgId } = useOrg();
  const { groups, groupId, groupName } = useWorkspace();
  const currentUser = useCurrentUser();
  // Editierbar: Solo-Modus eigene Items, oder Group-Modus für Items der eigenen Gruppe
  const isUserOwner = !!currentUser && item.owner_profile_id === currentUser.id;
  const isGroupOwned = !!item.owner_group_id;
  const isOwnGroupItem = isGroupOwned && !!groupId && item.owner_group_id === groupId;
  const isOwner = isUserOwner || isOwnGroupItem;
  // Bearbeitbar wenn man im richtigen Workspace ist (Solo für User-Items, Group für Group-Items)
  const isEditable = (isUserOwner && !groupId) || isOwnGroupItem;
  // Anzeige-Name des Eigentümers (für Banner)
  const [ownerName, setOwnerName] = useState<string>("");
  const [showLoanRequest, setShowLoanRequest] = useState(false);

  // Editierbare Felder
  const [name, setName] = useState(item.name);
  const [description, setDescription] = useState(item.description || "");
  const [category, setCategory] = useState(item.category);
  const [quantity, setQuantity] = useState(item.quantity);
  const [condition, setCondition] = useState<InventoryItem["condition"]>(
    item.condition
  );
  const [costPerDay, setCostPerDay] = useState(Number(item.cost_per_day));
  const [location, setLocation] = useState(item.location || "");
  const [purchasedBy, setPurchasedBy] = useState(item.purchased_by || "");
  const [purchasedAt, setPurchasedAt] = useState(item.purchased_at || "");
  const [imageUrl, setImageUrl] = useState(item.image_url);
  const [deviceName, setDeviceName] = useState(item.device_name || "");
  const [serialNumber, setSerialNumber] = useState(item.serial_number || "");
  const [purchasePrice, setPurchasePrice] = useState(item.purchase_price ?? "");
  const [dimensions, setDimensions] = useState(item.dimensions || "");
  const [powerWatts, setPowerWatts] = useState(item.power_watts ?? "");
  const [accessories, setAccessories] = useState<string[]>(item.accessories || []);
  const [accessoryCustom, setAccessoryCustom] = useState("");
  const [customField, setCustomField] = useState(item.custom_field || "");
  const [manufacturerUrl, setManufacturerUrl] = useState(item.manufacturer_url || "");
  const [manualUrl, setManualUrl] = useState(item.manual_url || "");
  // Eigentum & Abschreibung
  const [ownershipType, setOwnershipType] = useState(item.ownership_type || "organization");
  const [ownerProfileId, setOwnerProfileId] = useState(item.owner_profile_id || "");
  const [fundingSource, setFundingSource] = useState(item.funding_source || "organization");
  const [depreciationMethod, setDepreciationMethod] = useState(item.depreciation_method || "linear");
  const [depreciationYears, setDepreciationYears] = useState(item.depreciation_years ?? 7);
  const [residualValue, setResidualValue] = useState(item.residual_value ?? 0);
  // Sharing (Migration 041)
  const [isSharable, setIsSharable] = useState(item.is_shareable ?? false);
  const [sharingNotes, setSharingNotes] = useState(item.sharing_notes || "");
  const [saving, setSaving] = useState(false);
  const [hasChanges, setHasChanges] = useState(false);
  const [showOwnership, setShowOwnership] = useState(false);
  const [allProfiles, setAllProfiles] = useState<
    { id: string; name: string; is_active: boolean }[]
  >([]);

  // Einzelstücke
  const [units, setUnits] = useState<InventoryUnit[]>([]);
  const [unitsLoading, setUnitsLoading] = useState(false);
  const [unitsSaving, setUnitsSaving] = useState(false);

  // Gruppen-Shares pro Gruppe (Map)
  type ShareDraft = {
    enabled: boolean;
    daily_rate: number;
    requires_approval: boolean;
    conditions_tags: string[];
    conditions: string;
  };
  const [shares, setShares] = useState<Record<string, ShareDraft>>({});
  const [sharesLoaded, setSharesLoaded] = useState(false);

  // Track changes
  useEffect(() => {
    const changed =
      name !== item.name ||
      description !== (item.description || "") ||
      category !== item.category ||
      quantity !== item.quantity ||
      condition !== item.condition ||
      costPerDay !== Number(item.cost_per_day) ||
      location !== (item.location || "") ||
      purchasedBy !== (item.purchased_by || "") ||
      purchasedAt !== (item.purchased_at || "") ||
      deviceName !== (item.device_name || "") ||
      serialNumber !== (item.serial_number || "") ||
      String(purchasePrice) !== String(item.purchase_price ?? "") ||
      dimensions !== (item.dimensions || "") ||
      String(powerWatts) !== String(item.power_watts ?? "") ||
      JSON.stringify(accessories) !== JSON.stringify(item.accessories || []) ||
      customField !== (item.custom_field || "") ||
      manufacturerUrl !== (item.manufacturer_url || "") ||
      manualUrl !== (item.manual_url || "") ||
      ownershipType !== (item.ownership_type || "organization") ||
      ownerProfileId !== (item.owner_profile_id || "") ||
      fundingSource !== (item.funding_source || "organization") ||
      depreciationMethod !== (item.depreciation_method || "linear") ||
      depreciationYears !== (item.depreciation_years ?? 7) ||
      residualValue !== (item.residual_value ?? 0) ||
      isSharable !== (item.is_shareable ?? false) ||
      sharingNotes !== (item.sharing_notes || "");
    setHasChanges(changed);
  }, [name, description, category, quantity, condition, costPerDay, location, purchasedBy, purchasedAt, deviceName, serialNumber, purchasePrice, dimensions, powerWatts, accessories, customField, manufacturerUrl, manualUrl, ownershipType, ownerProfileId, fundingSource, depreciationMethod, depreciationYears, residualValue, isSharable, sharingNotes, item]);

  // Einzelstücke laden
  useEffect(() => {
    async function loadUnits() {
      if (!orgId) return;
      setUnitsLoading(true);
      const { data } = await supabase
        .from("inventory_units")
        .select("*")
        .eq("item_id", item.id)
        .eq("org_id", orgId)
        .order("unit_number", { ascending: true });
      if (data) setUnits(data);
      setUnitsLoading(false);
    }
    loadUnits();
  }, [supabase, item.id, orgId]);

  // Escape schließt Modal
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [onClose]);

  // Profile laden für Technikpat:in Dropdown — via org_memberships
  useEffect(() => {
    async function loadProfiles() {
      // User-First: nur den/die relevanten Profile laden
      const ids = new Set<string>();
      if (item.owner_profile_id) ids.add(item.owner_profile_id);
      if (currentUser?.id) ids.add(currentUser.id);
      // Ownership-Shares (Gewinn-Anteile) — Profile darin auch laden
      if (Array.isArray(item.ownership_shares)) {
        for (const s of item.ownership_shares as Array<{ profile_id?: string }>) {
          if (s.profile_id) ids.add(s.profile_id);
        }
      }
      if (ids.size === 0) return;
      const { data } = await supabase
        .from("profiles")
        .select("id, name")
        .in("id", Array.from(ids));
      if (data) {
        setAllProfiles(
          data.map((p) => ({ id: p.id, name: p.name || "", is_active: true }))
        );
        // Owner-Name fuer Banner
        if (item.owner_profile_id) {
          const owner = data.find((p) => p.id === item.owner_profile_id);
          setOwnerName(owner?.name || "");
        } else {
          setOwnerName("");
        }
      }
    }
    loadProfiles();
  }, [supabase, item.owner_profile_id, item.ownership_shares, currentUser?.id]);

  // Existierende Group-Shares für dieses Item laden
  useEffect(() => {
    async function loadShares() {
      const { data } = await supabase
        .from("inventory_group_shares")
        .select("group_id, daily_rate, requires_approval, conditions_tags, conditions")
        .eq("inventory_item_id", item.id)
        .is("revoked_at", null);
      const map: Record<string, ShareDraft> = {};
      for (const row of data || []) {
        map[row.group_id] = {
          enabled: true,
          daily_rate: Number(row.daily_rate) || 0,
          requires_approval: !!row.requires_approval,
          conditions_tags: row.conditions_tags || [],
          conditions: row.conditions || "",
        };
      }
      setShares(map);
      setSharesLoaded(true);
    }
    loadShares();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [item.id, supabase]);

  const myGroups = groups.filter((g) => g.isActive);

  function toggleGroupShare(groupId: string) {
    setShares((prev) => {
      const existing = prev[groupId];
      if (existing) {
        return { ...prev, [groupId]: { ...existing, enabled: !existing.enabled } };
      }
      return {
        ...prev,
        [groupId]: {
          enabled: true,
          daily_rate: costPerDay,
          requires_approval: false,
          conditions_tags: [],
          conditions: "",
        },
      };
    });
    setHasChanges(true);
  }

  function updateShare(groupId: string, patch: Partial<ShareDraft>) {
    setShares((prev) => {
      const existing = prev[groupId] || {
        enabled: true,
        daily_rate: costPerDay,
        requires_approval: false,
        conditions_tags: [],
        conditions: "",
      };
      return { ...prev, [groupId]: { ...existing, ...patch } };
    });
    setHasChanges(true);
  }

  function shareWithAll() {
    const next: Record<string, ShareDraft> = {};
    for (const g of myGroups) {
      const existing = shares[g.id];
      next[g.id] = existing
        ? { ...existing, enabled: true }
        : {
            enabled: true,
            daily_rate: costPerDay,
            requires_approval: false,
            conditions_tags: [],
            conditions: "",
          };
    }
    setShares({ ...shares, ...next });
    setHasChanges(true);
  }

  async function handleSave() {
    setSaving(true);
    // Group-owned Items: owner_profile_id darf nicht gesetzt werden (CHECK-Constraint)
    const ownerUpdate = isGroupOwned
      ? {} // keine Änderung am Owner
      : { owner_profile_id: ownerProfileId || null };
    const { error } = await supabase
      .from("inventory_items")
      .update({
        name,
        description: description || null,
        category,
        quantity,
        condition,
        cost_per_day: costPerDay,
        location: location || null,
        purchased_by: purchasedBy || null,
        purchased_at: purchasedAt || null,
        device_name: deviceName || null,
        serial_number: serialNumber || null,
        purchase_price: purchasePrice !== "" ? Number(purchasePrice) : null,
        dimensions: dimensions || null,
        power_watts: powerWatts !== "" ? Number(powerWatts) : null,
        accessories: accessories.length > 0 ? accessories : null,
        custom_field: customField || null,
        manufacturer_url: manufacturerUrl || null,
        manual_url: manualUrl || null,
        ownership_type: ownershipType,
        ...ownerUpdate,
        funding_source: fundingSource,
        depreciation_method: depreciationMethod,
        depreciation_years: depreciationYears,
        residual_value: residualValue,
        is_shareable: isSharable,
        sharing_notes: sharingNotes || null,
      })
      .eq("id", item.id);

    // Gruppen-Shares persistieren (nur im Solo-Modus, Eigentümer)
    if (!error && isEditable && currentUser) {
      const entries = Object.entries(shares);
      // Upsert für enabled, revoke/delete für disabled
      for (const [groupId, s] of entries) {
        if (s.enabled) {
          await supabase
            .from("inventory_group_shares")
            .upsert(
              {
                inventory_item_id: item.id,
                group_id: groupId,
                daily_rate: s.daily_rate,
                requires_approval: s.requires_approval,
                conditions_tags: s.conditions_tags,
                conditions: s.conditions || null,
                shared_by: currentUser.id,
                shared_at: new Date().toISOString(),
                revoked_at: null,
              },
              { onConflict: "inventory_item_id,group_id" }
            );
        } else {
          await supabase
            .from("inventory_group_shares")
            .delete()
            .eq("inventory_item_id", item.id)
            .eq("group_id", groupId);
        }
      }
    }

    if (!error) {
      onItemUpdated();
    }
    setSaving(false);
  }

  // Einzelstücke generieren (basierend auf Menge)
  async function generateUnits() {
    if (!orgId) return;
    setUnitsSaving(true);
    const existingNumbers = units.map((u) => u.unit_number);
    const newUnits = [];
    for (let i = 1; i <= quantity; i++) {
      if (!existingNumbers.includes(i)) {
        newUnits.push({
          item_id: item.id,
          org_id: orgId,
          unit_number: i,
          condition: condition,
          notes: null,
        });
      }
    }
    if (newUnits.length > 0) {
      const { data } = await supabase
        .from("inventory_units")
        .insert(newUnits)
        .select();
      if (data) setUnits((prev) => [...prev, ...data].sort((a, b) => a.unit_number - b.unit_number));
    }
    setUnitsSaving(false);
  }

  // Einzelstück aktualisieren
  async function updateUnit(unitId: string, field: "condition" | "notes", value: string) {
    await supabase
      .from("inventory_units")
      .update({ [field]: value })
      .eq("id", unitId);
    setUnits((prev) =>
      prev.map((u) => (u.id === unitId ? { ...u, [field]: value } : u))
    );
  }

  // Einzelstück löschen
  async function deleteUnit(unitId: string) {
    await supabase.from("inventory_units").delete().eq("id", unitId);
    setUnits((prev) => prev.filter((u) => u.id !== unitId));
  }

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-background)",
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background: "rgba(0,0,0,0.5)" }}
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div
        className="w-full max-w-2xl rounded-xl overflow-hidden flex flex-col"
        style={{
          background: "var(--color-surface)",
          boxShadow: "var(--shadow-lg)",
          maxHeight: "85vh",
        }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between px-6 py-4"
          style={{ borderBottom: "1px solid var(--color-border-light)" }}
        >
          <div>
            <h2 className="text-lg font-bold">{item.name}</h2>
            <span
              className="font-mono text-xs px-1.5 py-0.5 rounded"
              style={{
                background: "var(--color-muted)",
                color: "var(--color-muted-foreground)",
              }}
            >
              {item.inventory_number}
            </span>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg transition-colors"
            style={{ color: "var(--color-muted-foreground)" }}
            onMouseEnter={(e) =>
              (e.currentTarget.style.background = "var(--color-muted)")
            }
            onMouseLeave={(e) =>
              (e.currentTarget.style.background = "transparent")
            }
          >
            <IconX size={20} />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-6 py-5 space-y-6">
          {/* Read-Only Banner: im Group-Kontext oder wenn nicht Eigentümer */}
          {!isEditable && (
            <div
              className="rounded-lg p-3 flex items-start gap-2"
              style={{
                background: "var(--color-warning-light, #fef3c7)",
                border: "1px solid var(--color-warning, #f59e0b)",
                color: "var(--color-warning-foreground, #78350f)",
              }}
            >
              <span style={{ fontSize: 14 }}>🔒</span>
              <div className="text-xs flex-1">
                <div className="font-semibold mb-0.5">Nur Ansicht</div>
                <div>
                  Eigentümer:{" "}
                  <strong>
                    {ownerName ||
                      (item.owner_profile_id === currentUser?.id ? currentUser?.name : "—")}
                  </strong>
                  {groupId && groupName && (
                    <> · geteilt mit Gruppe <strong>{groupName}</strong></>
                  )}
                </div>
                <div className="mt-1">
                  Änderungen kann nur der Eigentümer in seinem persönlichen Inventar vornehmen.
                </div>
                {item.is_shareable && (
                  <button
                    type="button"
                    onClick={() => setShowLoanRequest(true)}
                    className="mt-2 px-3 py-1.5 rounded-md text-xs font-medium text-white"
                    style={{ background: "var(--color-primary)" }}
                  >
                    Verleih anfragen
                  </button>
                )}
              </div>
            </div>
          )}

          <fieldset disabled={!isEditable} className="space-y-6 disabled:opacity-90">
          {/* Foto */}
          <div>
            <label
              className="block text-sm font-medium mb-2"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              Foto
            </label>
            <InventoryImageUpload
              itemId={item.id}
              orgId={orgId || undefined}
              currentImageUrl={imageUrl}
              onUploadComplete={(newUrl) => {
                setImageUrl(newUrl);
                onItemUpdated();
              }}
              size="large"
            />
          </div>

          {/* Felder */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium mb-1.5"
                style={{ color: "var(--color-muted-foreground)" }}>
                Name
              </label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1.5"
                style={{ color: "var(--color-muted-foreground)" }}>
                Kategorie
              </label>
              <input
                type="text"
                value={category}
                onChange={(e) => setCategory(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              />
            </div>
            {(isEditable || description) && (
            <div className="col-span-2">
              <label className="block text-xs font-medium mb-1.5"
                style={{ color: "var(--color-muted-foreground)" }}>
                Beschreibung
              </label>
              <input
                type="text"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
                placeholder="Kurze Beschreibung"
              />
            </div>
            )}
            <div>
              <label className="block text-xs font-medium mb-1.5"
                style={{ color: "var(--color-muted-foreground)" }}>
                Menge
              </label>
              <input
                type="number"
                value={quantity}
                onChange={(e) => setQuantity(Number(e.target.value))}
                min={1}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1.5"
                style={{ color: "var(--color-muted-foreground)" }}>
                Zustand
              </label>
              <select
                value={condition}
                onChange={(e) =>
                  setCondition(e.target.value as InventoryItem["condition"])
                }
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              >
                {Object.entries(conditionLabels).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1.5"
                style={{ color: "var(--color-muted-foreground)" }}>
                Preis/Tag (&euro;)
              </label>
              <input
                type="number"
                value={costPerDay}
                onChange={(e) => setCostPerDay(Number(e.target.value))}
                min={0}
                step={0.01}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              />
            </div>
            {(isEditable || location) && (
            <div>
              <label className="block text-xs font-medium mb-1.5"
                style={{ color: "var(--color-muted-foreground)" }}>
                Lagerort
              </label>
              <input
                type="text"
                value={location}
                onChange={(e) => setLocation(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
                placeholder="z.B. Lager A"
              />
            </div>
            )}
            {(isEditable || purchasedBy) && (
            <div>
              <label className="block text-xs font-medium mb-1.5"
                style={{ color: "var(--color-muted-foreground)" }}>
                Technikpat:in
              </label>
              <select
                value={purchasedBy}
                onChange={(e) => setPurchasedBy(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              >
                <option value="">-- Keine Auswahl --</option>
                <optgroup label="Aktive Mitglieder">
                  {allProfiles
                    .filter((p) => p.is_active)
                    .map((p) => (
                      <option key={p.id} value={p.name}>
                        {p.name}
                      </option>
                    ))}
                </optgroup>
                {allProfiles.filter((p) => !p.is_active).length > 0 && (
                  <optgroup label="Ehemalige">
                    {allProfiles
                      .filter((p) => !p.is_active)
                      .map((p) => (
                        <option key={p.id} value={p.name}>
                          {p.name}
                        </option>
                      ))}
                  </optgroup>
                )}
                {purchasedBy &&
                  !allProfiles.some((p) => p.name === purchasedBy) && (
                    <option value={purchasedBy}>
                      {purchasedBy} (manuell)
                    </option>
                  )}
              </select>
            </div>
            )}
            {(isEditable || purchasedAt) && (
            <div>
              <label className="block text-xs font-medium mb-1.5"
                style={{ color: "var(--color-muted-foreground)" }}>
                Anschaffungsdatum
              </label>
              <DateInput
                value={purchasedAt}
                onChange={setPurchasedAt}
              />
            </div>
            )}
          </div>

          {/* Erweiterte Details — nur anzeigen wenn editierbar oder mind. 1 Feld gefüllt */}
          {(isEditable || deviceName || serialNumber || purchasePrice || dimensions || powerWatts || customField || manufacturerUrl || manualUrl || (accessories && accessories.length > 0)) && (
          <div
            className="pt-2"
            style={{ borderTop: "1px solid var(--color-border-light)" }}
          >
            <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-muted-foreground)" }}>
              Gerätedetails
            </h3>
            <div className="grid grid-cols-2 gap-4">
              {(isEditable || deviceName) && (
              <div>
                <label className="block text-xs font-medium mb-1.5"
                  style={{ color: "var(--color-muted-foreground)" }}>
                  Gerätebezeichnung
                </label>
                <input
                  type="text"
                  value={deviceName}
                  onChange={(e) => setDeviceName(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                  placeholder="z.B. EB-U50"
                />
              </div>
              )}
              {(isEditable || serialNumber) && (
              <div>
                <label className="block text-xs font-medium mb-1.5"
                  style={{ color: "var(--color-muted-foreground)" }}>
                  Seriennummer
                </label>
                <input
                  type="text"
                  value={serialNumber}
                  onChange={(e) => setSerialNumber(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm font-mono"
                  style={inputStyle}
                  placeholder="z.B. SN-12345678"
                />
              </div>
              )}
              {(isEditable || purchasePrice) && (
              <div>
                <label className="block text-xs font-medium mb-1.5"
                  style={{ color: "var(--color-muted-foreground)" }}>
                  Kaufpreis (&euro;)
                </label>
                <input
                  type="number"
                  value={purchasePrice}
                  onChange={(e) => setPurchasePrice(e.target.value === "" ? "" : Number(e.target.value))}
                  min={0}
                  step={0.01}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                  placeholder="0.00"
                />
              </div>
              )}
              {(isEditable || dimensions) && (
              <div>
                <label className="block text-xs font-medium mb-1.5"
                  style={{ color: "var(--color-muted-foreground)" }}>
                  Abma&szlig;e
                </label>
                <input
                  type="text"
                  value={dimensions}
                  onChange={(e) => setDimensions(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                  placeholder="z.B. 60x40x30 cm"
                />
              </div>
              )}
              {(isEditable || powerWatts) && (
              <div>
                <label className="block text-xs font-medium mb-1.5"
                  style={{ color: "var(--color-muted-foreground)" }}>
                  Leistung (W)
                </label>
                <input
                  type="number"
                  value={powerWatts}
                  onChange={(e) => setPowerWatts(e.target.value === "" ? "" : Number(e.target.value))}
                  min={0}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                  placeholder="z.B. 500"
                />
              </div>
              )}
              {(isEditable || customField) && (
              <div>
                <label className="block text-xs font-medium mb-1.5"
                  style={{ color: "var(--color-muted-foreground)" }}>
                  Freifeld
                </label>
                <input
                  type="text"
                  value={customField}
                  onChange={(e) => setCustomField(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                  placeholder="Sonstige Infos"
                />
              </div>
              )}
              {(isEditable || manufacturerUrl) && (
              <div>
                <label className="block text-xs font-medium mb-1.5"
                  style={{ color: "var(--color-muted-foreground)" }}>
                  Hersteller-Link
                </label>
                <div className="flex gap-2">
                  <input
                    type="url"
                    value={manufacturerUrl}
                    onChange={(e) => setManufacturerUrl(e.target.value)}
                    className="flex-1 px-3 py-2 rounded-lg text-sm"
                    style={inputStyle}
                    placeholder="https://..."
                  />
                  {manufacturerUrl && (
                    <a
                      href={manufacturerUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex items-center px-2 rounded-lg transition-colors"
                      style={{ border: "1px solid var(--color-border)", color: "var(--color-primary)" }}
                    >
                      <IconExternalLink size={14} />
                    </a>
                  )}
                </div>
              </div>
              )}
              {(isEditable || manualUrl) && (
              <div>
                <label className="block text-xs font-medium mb-1.5"
                  style={{ color: "var(--color-muted-foreground)" }}>
                  Manual / Handbuch
                </label>
                <div className="flex gap-2">
                  <input
                    type="url"
                    value={manualUrl}
                    onChange={(e) => setManualUrl(e.target.value)}
                    className="flex-1 px-3 py-2 rounded-lg text-sm"
                    style={inputStyle}
                    placeholder="https://..."
                  />
                  {manualUrl && (
                    <a
                      href={manualUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex items-center px-2 rounded-lg transition-colors"
                      style={{ border: "1px solid var(--color-border)", color: "var(--color-primary)" }}
                    >
                      <IconExternalLink size={14} />
                    </a>
                  )}
                </div>
              </div>
              )}
            </div>

            {/* Zubehör — nur wenn editierbar oder gefüllt */}
            {(isEditable || (accessories && accessories.length > 0)) && (
            <div className="mt-4">
              <label className="block text-xs font-medium mb-2"
                style={{ color: "var(--color-muted-foreground)" }}>
                Zubeh&ouml;r
              </label>
              <div className="flex flex-wrap gap-2 mb-2">
                {["Netzteil", "Tasche", "Kabel", "Adapter"].map((acc) => {
                  const isSelected = accessories.includes(acc);
                  return (
                    <button
                      key={acc}
                      type="button"
                      onClick={() =>
                        setAccessories((prev) =>
                          isSelected ? prev.filter((a) => a !== acc) : [...prev, acc]
                        )
                      }
                      className="px-3 py-1.5 rounded-full text-xs font-medium transition-all"
                      style={{
                        background: isSelected ? "var(--color-primary)" : "var(--color-surface)",
                        color: isSelected ? "#fff" : "var(--color-muted-foreground)",
                        border: isSelected ? "none" : "1px solid var(--color-border)",
                      }}
                    >
                      {isSelected ? "✓ " : ""}{acc}
                    </button>
                  );
                })}
              </div>
              {/* Custom accessory tags */}
              <div className="flex flex-wrap gap-1.5 mb-2">
                {accessories
                  .filter((a) => !["Netzteil", "Tasche", "Kabel", "Adapter"].includes(a))
                  .map((acc) => (
                    <span
                      key={acc}
                      className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium"
                      style={{ background: "var(--color-info-light)", color: "var(--color-info)" }}
                    >
                      {acc}
                      <button
                        type="button"
                        onClick={() => setAccessories((prev) => prev.filter((a) => a !== acc))}
                        className="ml-0.5 hover:opacity-70"
                      >
                        &times;
                      </button>
                    </span>
                  ))}
              </div>
              {/* Freifeld-Input für eigenes Zubehör — nur Edit */}
              {isEditable && (
              <div className="flex gap-2">
                <input
                  type="text"
                  value={accessoryCustom}
                  onChange={(e) => setAccessoryCustom(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter" && accessoryCustom.trim()) {
                      e.preventDefault();
                      if (!accessories.includes(accessoryCustom.trim())) {
                        setAccessories((prev) => [...prev, accessoryCustom.trim()]);
                      }
                      setAccessoryCustom("");
                    }
                  }}
                  className="flex-1 px-3 py-1.5 rounded-lg text-xs"
                  style={inputStyle}
                  placeholder="Eigenes Zubeh&ouml;r hinzuf&uuml;gen..."
                />
                <button
                  type="button"
                  onClick={() => {
                    if (accessoryCustom.trim() && !accessories.includes(accessoryCustom.trim())) {
                      setAccessories((prev) => [...prev, accessoryCustom.trim()]);
                    }
                    setAccessoryCustom("");
                  }}
                  className="px-3 py-1.5 rounded-lg text-xs font-medium"
                  style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}
                >
                  +
                </button>
              </div>
              )}
            </div>
            )}
          </div>
          )}

          {/* Eigentum & Wert — nur im Edit-Modus sichtbar */}
          {isEditable && (
          <div
            className="pt-3"
            style={{ borderTop: "1px solid var(--color-border-light)" }}
          >
            <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-muted-foreground)" }}>
              Eigentum &amp; Wert {item.current_value != null ? `(${Number(item.current_value).toLocaleString("de-DE", { style: "currency", currency: "EUR" })})` : ""}
            </h3>
            <div className="space-y-4">
                {/* Wert-Anzeige */}
                {item.purchase_price != null && item.current_value != null && (
                  <div
                    className="rounded-lg p-3"
                    style={{ background: "var(--color-muted)" }}
                  >
                    <div className="flex justify-between text-xs mb-2">
                      <span style={{ color: "var(--color-muted-foreground)" }}>Kaufpreis</span>
                      <span className="font-medium">{Number(item.purchase_price).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}</span>
                    </div>
                    <div className="flex justify-between text-xs mb-2">
                      <span style={{ color: "var(--color-muted-foreground)" }}>Aktueller Wert</span>
                      <span className="font-bold" style={{ color: "var(--color-primary)" }}>
                        {Number(item.current_value).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                      </span>
                    </div>
                    <div className="w-full rounded-full h-2 overflow-hidden" style={{ background: "var(--color-border)" }}>
                      <div
                        className="h-full rounded-full"
                        style={{
                          width: `${Math.max(5, (Number(item.current_value) / Number(item.purchase_price)) * 100)}%`,
                          background: Number(item.current_value) / Number(item.purchase_price) > 0.5
                            ? "var(--color-success)"
                            : Number(item.current_value) / Number(item.purchase_price) > 0.2
                            ? "var(--color-warning)"
                            : "var(--color-error)",
                        }}
                      />
                    </div>
                    <div className="flex justify-between text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                      <span>Abschreibung: {depreciationMethod === "linear" ? `${depreciationYears} Jahre linear` : "Keine"}</span>
                      <span>{Math.round((Number(item.current_value) / Number(item.purchase_price)) * 100)}%</span>
                    </div>
                  </div>
                )}

                <div className="grid grid-cols-2 gap-4">
                  {/* Eigentümer — zeigt den aktuellen Owner-Namen */}
                  <div>
                    <label className="block text-xs font-medium mb-1.5"
                      style={{ color: "var(--color-muted-foreground)" }}>
                      Eigentümer
                    </label>
                    <div
                      className="w-full px-3 py-2 rounded-lg text-sm flex items-center gap-2"
                      style={{
                        background: "var(--color-muted)",
                        border: "1px solid var(--color-border)",
                        color: "var(--color-foreground)",
                      }}
                      title={ownerProfileId ? "Du bist Eigentümer" : "Kein Eigentümer gesetzt (Gruppen-Item)"}
                    >
                      <span>👤</span>
                      <span className="font-medium truncate">
                        {ownerProfileId
                          ? allProfiles.find((p) => p.id === ownerProfileId)?.name ||
                            (ownerProfileId === currentUser?.id ? currentUser.name : "—")
                          : "— (nicht zugewiesen)"}
                      </span>
                    </div>
                  </div>

                  {/* Finanzierung */}
                  <div>
                    <label className="block text-xs font-medium mb-1.5"
                      style={{ color: "var(--color-muted-foreground)" }}>
                      Finanzierung
                    </label>
                    <select
                      value={fundingSource}
                      onChange={(e) => setFundingSource(e.target.value as any)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={inputStyle}
                    >
                      <option value="organization">Organisation</option>
                      <option value="self">Eigenfinanziert</option>
                      <option value="project">Projekt-Budget</option>
                      <option value="sponsor">Sponsor</option>
                    </select>
                  </div>
                </div>

                {/* Eigentumsanteile (bei shared) */}
                {item.ownership_shares && item.ownership_shares.length > 0 && (
                  <div
                    className="rounded-lg p-3 mt-3"
                    style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
                  >
                    <div className="text-xs font-semibold mb-2" style={{ color: "var(--color-muted-foreground)" }}>
                      Eigentumsanteile
                      {fundingSource === "project" && (
                        <span className="ml-2 font-normal">(aus Gewinnverteilung)</span>
                      )}
                    </div>
                    <div className="space-y-1.5">
                      {item.ownership_shares.map((share: any) => {
                        const profile = allProfiles.find((p) => p.id === share.profile_id);
                        return (
                          <div key={share.profile_id} className="flex items-center gap-2">
                            {/* Bar */}
                            <div className="flex-1 min-w-0">
                              <div className="flex items-center justify-between mb-0.5">
                                <span className="text-xs font-medium truncate">
                                  {profile?.name || "Unbekannt"}
                                </span>
                                <span className="text-xs tabular-nums shrink-0" style={{ color: "var(--color-muted-foreground)" }}>
                                  {share.percentage}% · {Number(share.invested).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                                </span>
                              </div>
                              <div className="w-full rounded-full h-1.5" style={{ background: "var(--color-border)" }}>
                                <div
                                  className="h-full rounded-full"
                                  style={{
                                    width: `${Math.min(share.percentage, 100)}%`,
                                    background: "var(--color-primary)",
                                  }}
                                />
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                    <div className="text-xs mt-2 pt-2" style={{ color: "var(--color-muted-foreground)", borderTop: "1px solid var(--color-border-light)" }}>
                      Steht dem Team frei zur Verfügung. Bei Austritt werden Anteile verrechnet.
                    </div>
                  </div>
                )}

                <div className="grid grid-cols-2 gap-4 mt-3">

                  {/* Abschreibungsmethode */}
                  <div>
                    <label className="block text-xs font-medium mb-1.5"
                      style={{ color: "var(--color-muted-foreground)" }}>
                      Abschreibung
                    </label>
                    <select
                      value={depreciationMethod}
                      onChange={(e) => setDepreciationMethod(e.target.value as any)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={inputStyle}
                    >
                      <option value="linear">Linear</option>
                      <option value="none">Keine</option>
                    </select>
                  </div>

                  {/* AfA-Dauer */}
                  {depreciationMethod === "linear" && (
                    <div>
                      <label className="block text-xs font-medium mb-1.5"
                        style={{ color: "var(--color-muted-foreground)" }}>
                        Nutzungsdauer (Jahre)
                      </label>
                      <input
                        type="number"
                        value={depreciationYears}
                        onChange={(e) => setDepreciationYears(Number(e.target.value))}
                        min={1}
                        max={30}
                        className="w-full px-3 py-2 rounded-lg text-sm"
                        style={inputStyle}
                      />
                    </div>
                  )}

                  {/* Restwert */}
                  {depreciationMethod === "linear" && (
                    <div>
                      <label className="block text-xs font-medium mb-1.5"
                        style={{ color: "var(--color-muted-foreground)" }}>
                        Restwert (&euro;)
                      </label>
                      <input
                        type="number"
                        value={residualValue}
                        onChange={(e) => setResidualValue(Number(e.target.value))}
                        min={0}
                        step={0.01}
                        className="w-full px-3 py-2 rounded-lg text-sm"
                        style={inputStyle}
                      />
                    </div>
                  )}
                </div>

              </div>
          </div>
          )}

          {/* Ausleihbedingungen — nur im Read-only Modus + Group-Kontext */}
          {!isEditable && groupId && sharesLoaded && shares[groupId]?.enabled && (
            <div
              className="pt-3"
              style={{ borderTop: "1px solid var(--color-border-light)" }}
            >
              <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-muted-foreground)" }}>
                Ausleihbedingungen
              </h3>
              <div
                className="rounded-lg p-3 space-y-2"
                style={{ background: "var(--color-muted)" }}
              >
                {/* Tagessatz + Approval */}
                <div className="flex items-center justify-between text-sm">
                  <span style={{ color: "var(--color-muted-foreground)" }}>Tagessatz</span>
                  <span className="font-medium tabular-nums">
                    {Number(shares[groupId].daily_rate).toLocaleString("de-DE", {
                      style: "currency",
                      currency: "EUR",
                    })}
                  </span>
                </div>
                <div className="flex items-center justify-between text-sm">
                  <span style={{ color: "var(--color-muted-foreground)" }}>Buchung</span>
                  <span className="font-medium">
                    {shares[groupId].requires_approval
                      ? "🔒 Zusage durch Eigentümer erforderlich"
                      : "✓ Frei verfügbar"}
                  </span>
                </div>
                {/* Bedingungs-Tags */}
                {shares[groupId].conditions_tags.length > 0 && (
                  <div className="pt-2" style={{ borderTop: "1px solid var(--color-border-light)" }}>
                    <div className="text-xs mb-1.5" style={{ color: "var(--color-muted-foreground)" }}>
                      Bedingungen
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                      {shares[groupId].conditions_tags.map((tag) => {
                        const preset = CONDITION_PRESETS.find((c) => c.value === tag);
                        return (
                          <span
                            key={tag}
                            className="text-xs px-2 py-1 rounded-full"
                            style={{
                              background: "var(--color-primary)",
                              color: "#fff",
                            }}
                            title={preset?.description}
                          >
                            {preset?.label || tag}
                          </span>
                        );
                      })}
                    </div>
                  </div>
                )}
                {/* Freier Text */}
                {shares[groupId].conditions && (
                  <div className="pt-2 text-sm italic" style={{
                    color: "var(--color-foreground)",
                    borderTop: "1px solid var(--color-border-light)",
                  }}>
                    „{shares[groupId].conditions}"
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Teilen mit Gruppen (nur im Solo-Modus, wenn Eigentümer & Gruppen vorhanden) */}
          {isEditable && myGroups.length > 0 && sharesLoaded && (
            <div
              className="pt-3"
              style={{ borderTop: "1px solid var(--color-border-light)" }}
            >
              <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
                  Teilen mit Gruppen
                </h3>
                <button
                  type="button"
                  onClick={shareWithAll}
                  className="text-xs px-2 py-1 rounded-md"
                  style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}
                >
                  Für alle freigeben
                </button>
              </div>
              <div className="space-y-3">
                {myGroups.map((g) => {
                  const s = shares[g.id];
                  const enabled = !!s?.enabled;
                  return (
                    <div
                      key={g.id}
                      className="rounded-lg p-3"
                      style={{
                        background: enabled ? "var(--color-primary-light)" : "var(--color-muted)",
                        border: enabled ? "1px solid var(--color-primary)" : "1px solid transparent",
                      }}
                    >
                      <label className="flex items-center gap-2 text-sm font-medium cursor-pointer">
                        <input
                          type="checkbox"
                          checked={enabled}
                          onChange={() => toggleGroupShare(g.id)}
                        />
                        🛡️ {g.name}
                      </label>
                      {enabled && s && (
                        <div className="mt-3 space-y-2 pl-6">
                          {/* Daily rate + Approval */}
                          <div className="grid grid-cols-2 gap-3">
                            <div>
                              <label className="block text-[11px] mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                                Tagessatz (€)
                              </label>
                              <input
                                type="number"
                                value={s.daily_rate}
                                step={0.01}
                                onChange={(e) => updateShare(g.id, { daily_rate: Number(e.target.value) })}
                                className="w-full px-2 py-1.5 rounded text-sm"
                                style={inputStyle}
                              />
                            </div>
                            <label className="flex items-center gap-2 text-xs mt-5 cursor-pointer">
                              <input
                                type="checkbox"
                                checked={s.requires_approval}
                                onChange={(e) => updateShare(g.id, { requires_approval: e.target.checked })}
                              />
                              Zusage durch Eigentümer erforderlich
                            </label>
                          </div>

                          {/* Bedingungen Presets */}
                          <div>
                            <label className="block text-[11px] mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                              Bedingungen
                            </label>
                            <div className="flex flex-wrap gap-1.5">
                              {CONDITION_PRESETS.map((c) => {
                                const on = s.conditions_tags.includes(c.value);
                                return (
                                  <button
                                    key={c.value}
                                    type="button"
                                    onClick={() => {
                                      const next = on
                                        ? s.conditions_tags.filter((t) => t !== c.value)
                                        : [...s.conditions_tags, c.value];
                                      updateShare(g.id, { conditions_tags: next });
                                    }}
                                    className="text-xs px-2 py-1 rounded-full"
                                    style={{
                                      background: on ? "var(--color-primary)" : "var(--color-surface)",
                                      color: on ? "#fff" : "var(--color-foreground)",
                                      border: on ? "none" : "1px solid var(--color-border)",
                                    }}
                                    title={c.description}
                                  >
                                    {c.label}
                                  </button>
                                );
                              })}
                            </div>
                          </div>

                          {/* Freier Text */}
                          <input
                            type="text"
                            value={s.conditions}
                            onChange={(e) => updateShare(g.id, { conditions: e.target.value })}
                            placeholder="Zusätzliche Hinweise (optional)"
                            className="w-full px-2 py-1.5 rounded text-sm"
                            style={inputStyle}
                          />
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* Einzelstücke (bei Menge > 1) */}
          {quantity > 1 && (
            <div
              className="pt-2"
              style={{ borderTop: "1px solid var(--color-border-light)" }}
            >
              <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold" style={{ color: "var(--color-muted-foreground)" }}>
                  <IconHash size={14} className="inline mr-1" style={{ verticalAlign: "-2px" }} />
                  Einzelstücke ({units.length}/{quantity})
                </h3>
                {units.length < quantity && (
                  <button
                    type="button"
                    onClick={generateUnits}
                    disabled={unitsSaving}
                    className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                    style={{
                      background: "var(--color-primary)",
                      color: "#fff",
                    }}
                  >
                    <IconPlus size={12} />
                    {unitsSaving ? "Wird erstellt..." : "Einzelstücke anlegen"}
                  </button>
                )}
              </div>

              {unitsLoading ? (
                <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  Lade...
                </p>
              ) : units.length === 0 ? (
                <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  Noch keine Einzelstücke angelegt. Klicke &quot;Einzelstücke anlegen&quot; um {quantity} Stücke zu erstellen.
                </p>
              ) : (
                <div className="space-y-2">
                  {units.map((unit) => (
                    <div
                      key={unit.id}
                      className="flex items-center gap-3 px-3 py-2 rounded-lg"
                      style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
                    >
                      <span className="text-xs font-mono font-bold shrink-0" style={{ color: "var(--color-muted-foreground)", minWidth: "2rem" }}>
                        #{unit.unit_number}
                      </span>
                      <select
                        value={unit.condition}
                        onChange={(e) => updateUnit(unit.id, "condition", e.target.value)}
                        className="px-2 py-1 rounded text-xs"
                        style={{ ...inputStyle, minWidth: "100px" }}
                      >
                        {Object.entries(conditionLabels).map(([key, label]) => (
                          <option key={key} value={key}>{label}</option>
                        ))}
                      </select>
                      <input
                        type="text"
                        value={unit.notes || ""}
                        onChange={(e) => updateUnit(unit.id, "notes", e.target.value)}
                        className="flex-1 px-2 py-1 rounded text-xs"
                        style={inputStyle}
                        placeholder="Notizen..."
                      />
                      <button
                        type="button"
                        onClick={() => deleteUnit(unit.id)}
                        className="p-1 rounded transition-colors shrink-0"
                        style={{ color: "var(--color-error)" }}
                      >
                        <IconTrash size={12} />
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          </fieldset>

          {/* Metadaten (readonly) */}
          <div
            className="flex items-center gap-6 text-xs pt-2"
            style={{
              color: "var(--color-muted-foreground)",
              borderTop: "1px solid var(--color-border-light)",
              paddingTop: "12px",
            }}
          >
            {/* Legacy text-Owner (z.B. "Kollektiv") nicht mehr anzeigen — Owner kommt jetzt aus owner_profile_id */}
            <span>
              Erstellt:{" "}
              {new Date(item.created_at).toLocaleDateString("de-DE", {
                day: "numeric",
                month: "short",
                year: "numeric",
              })}
            </span>
          </div>
        </div>

        {/* Footer */}
        <div
          className="flex items-center justify-end gap-2 px-6 py-4"
          style={{ borderTop: "1px solid var(--color-border-light)" }}
        >
          <button
            onClick={onClose}
            className="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
            style={{ border: "1px solid var(--color-border)" }}
            onMouseEnter={(e) =>
              (e.currentTarget.style.background = "var(--color-muted)")
            }
            onMouseLeave={(e) =>
              (e.currentTarget.style.background = "transparent")
            }
          >
            Schließen
          </button>
          {hasChanges && isEditable && (
            <button
              onClick={handleSave}
              disabled={saving}
              className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50 transition-colors"
              style={{ background: "var(--color-primary)" }}
              onMouseEnter={(e) =>
                (e.currentTarget.style.background =
                  "var(--color-primary-hover)")
              }
              onMouseLeave={(e) =>
                (e.currentTarget.style.background = "var(--color-primary)")
              }
            >
              <IconSave size={14} />
              {saving ? "Wird gespeichert..." : "Speichern"}
            </button>
          )}
        </div>
      </div>

      {showLoanRequest && (
        <LoanRequestModal
          item={item}
          onClose={() => setShowLoanRequest(false)}
          onSubmitted={() => onItemUpdated()}
        />
      )}
    </div>
  );
}
