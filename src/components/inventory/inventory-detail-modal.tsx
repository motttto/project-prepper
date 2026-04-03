"use client";

import { useState, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import type { InventoryItem } from "@/types/database";
import type { InventoryUnit } from "@/types/database";
import { IconX, IconSave, IconExternalLink, IconHash, IconPlus, IconTrash } from "@/components/ui/icons";
import { DateInput } from "@/components/ui/date-input";
import { InventoryImageUpload } from "@/components/inventory/inventory-image-upload";

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
      if (!orgId) return;
      const { data } = await supabase
        .from("org_memberships")
        .select("profile_id, is_active, profiles(id, name)")
        .eq("org_id", orgId);
      if (data) {
        setAllProfiles(
          data.map((m: any) => ({
            id: m.profiles?.id || m.profile_id,
            name: m.profiles?.name || "",
            is_active: m.is_active,
          }))
        );
      }
    }
    loadProfiles();
  }, [supabase, orgId]);

  async function handleSave() {
    setSaving(true);
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
        owner_profile_id: ownerProfileId || null,
        funding_source: fundingSource,
        depreciation_method: depreciationMethod,
        depreciation_years: depreciationYears,
        residual_value: residualValue,
        is_shareable: isSharable,
        sharing_notes: sharingNotes || null,
      })
      .eq("id", item.id);

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
          </div>

          {/* Erweiterte Details */}
          <div
            className="pt-2"
            style={{ borderTop: "1px solid var(--color-border-light)" }}
          >
            <h3 className="text-sm font-semibold mb-3" style={{ color: "var(--color-muted-foreground)" }}>
              Gerätedetails
            </h3>
            <div className="grid grid-cols-2 gap-4">
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
            </div>

            {/* Zubehör */}
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
              {/* Freifeld-Input für eigenes Zubehör */}
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
            </div>
          </div>

          {/* Eigentum & Wert (Akkordeon) */}
          <div
            className="pt-2"
            style={{ borderTop: "1px solid var(--color-border-light)" }}
          >
            <button
              type="button"
              onClick={() => setShowOwnership(!showOwnership)}
              className="flex items-center justify-between w-full text-sm font-semibold mb-3"
              style={{ color: "var(--color-muted-foreground)" }}
            >
              <span>Eigentum &amp; Wert {item.current_value != null ? `(${Number(item.current_value).toLocaleString("de-DE", { style: "currency", currency: "EUR" })})` : ""}</span>
              <span className="text-xs">{showOwnership ? "▲" : "▼"}</span>
            </button>

            {showOwnership && (
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
                  {/* Eigentum */}
                  <div>
                    <label className="block text-xs font-medium mb-1.5"
                      style={{ color: "var(--color-muted-foreground)" }}>
                      Eigentum
                    </label>
                    <select
                      value={ownershipType}
                      onChange={(e) => setOwnershipType(e.target.value as any)}
                      className="w-full px-3 py-2 rounded-lg text-sm"
                      style={inputStyle}
                    >
                      <option value="organization">Organisation</option>
                      <option value="member">Mitglied (persönlich)</option>
                      <option value="shared">Geteilt</option>
                    </select>
                  </div>

                  {/* Eigentümer (nur bei member/shared) */}
                  {ownershipType !== "organization" && (
                    <div>
                      <label className="block text-xs font-medium mb-1.5"
                        style={{ color: "var(--color-muted-foreground)" }}>
                        Eigentümer
                      </label>
                      <select
                        value={ownerProfileId}
                        onChange={(e) => setOwnerProfileId(e.target.value)}
                        className="w-full px-3 py-2 rounded-lg text-sm"
                        style={inputStyle}
                      >
                        <option value="">-- Auswählen --</option>
                        {allProfiles
                          .filter((p) => p.is_active)
                          .map((p) => (
                            <option key={p.id} value={p.id}>{p.name}</option>
                          ))}
                      </select>
                    </div>
                  )}

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

                {/* Partner-Sharing */}
                <div
                  className="rounded-lg p-3 mt-2"
                  style={{ background: "var(--color-muted)" }}
                >
                  <label className="flex items-center gap-2 text-sm font-medium cursor-pointer">
                    <input
                      type="checkbox"
                      checked={isSharable}
                      onChange={(e) => setIsSharable(e.target.checked)}
                    />
                    Für Partner-Organisationen teilbar
                  </label>
                  {isSharable && (
                    <input
                      type="text"
                      value={sharingNotes}
                      onChange={(e) => setSharingNotes(e.target.value)}
                      placeholder="Hinweise zum Verleih (optional)"
                      className="w-full px-3 py-2 rounded-lg text-sm mt-2"
                      style={inputStyle}
                    />
                  )}
                </div>
              </div>
            )}
          </div>

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

          {/* Metadaten (readonly) */}
          <div
            className="flex items-center gap-6 text-xs pt-2"
            style={{
              color: "var(--color-muted-foreground)",
              borderTop: "1px solid var(--color-border-light)",
              paddingTop: "12px",
            }}
          >
            {item.owner && <span>Eigentümer: {item.owner}</span>}
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
          {hasChanges && (
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
    </div>
  );
}
