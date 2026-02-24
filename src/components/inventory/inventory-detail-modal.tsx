"use client";

import { useState, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import type { InventoryItem } from "@/types/database";
import { IconX, IconSave } from "@/components/ui/icons";
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
      purchasedAt !== (item.purchased_at || "");
    setHasChanges(changed);
  }, [name, description, category, quantity, condition, costPerDay, location, purchasedBy, purchasedAt, item]);

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
              <input
                type="date"
                value={purchasedAt}
                onChange={(e) => setPurchasedAt(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              />
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
