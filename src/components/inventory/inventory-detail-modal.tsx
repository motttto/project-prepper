"use client";

import { useState, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import type { InventoryItem } from "@/types/database";
import { IconX, IconSave } from "@/components/ui/icons";
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
  const [saving, setSaving] = useState(false);
  const [hasChanges, setHasChanges] = useState(false);
  const [allProfiles, setAllProfiles] = useState<
    { id: string; name: string; is_active: boolean }[]
  >([]);

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
      customField !== (item.custom_field || "");
    setHasChanges(changed);
  }, [name, description, category, quantity, condition, costPerDay, location, purchasedBy, purchasedAt, deviceName, serialNumber, purchasePrice, dimensions, powerWatts, accessories, customField, item]);

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
      })
      .eq("id", item.id);

    if (!error) {
      onItemUpdated();
    }
    setSaving(false);
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
