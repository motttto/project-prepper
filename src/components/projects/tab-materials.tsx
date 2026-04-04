"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import type { Project, Consumable } from "@/types/database";
import { IconPlus, IconTrash } from "@/components/ui/icons";
import { useRealtimeTable } from "@/hooks/use-realtime-table";

interface TabMaterialsProps {
  projectId: string;
  project: Project;
  onProjectUpdate: (p: Project) => void;
}

export function TabMaterials({ projectId, project, onProjectUpdate }: TabMaterialsProps) {
  const supabase = createClient();
  const [consumables, setConsumables] = useState<Consumable[]>([]);
  const [loading, setLoading] = useState(true);

  // Form state
  const [showForm, setShowForm] = useState(false);
  const [formName, setFormName] = useState("");
  const [formQuantity, setFormQuantity] = useState("1");
  const [formUnit, setFormUnit] = useState("Stk");
  const [formCost, setFormCost] = useState("");
  const [formNotes, setFormNotes] = useState("");
  const [saving, setSaving] = useState(false);

  // Transport notes editing
  const [editingTransport, setEditingTransport] = useState(false);
  const [transportDraft, setTransportDraft] = useState(project.transport_notes || "");

  const loadConsumables = useCallback(async () => {
    const { data } = await supabase
      .from("project_consumables")
      .select("*")
      .eq("project_id", projectId)
      .order("created_at");
    if (data) setConsumables(data as Consumable[]);
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    loadConsumables();
  }, [loadConsumables]);

  // Realtime: Live-Synchronisation
  useRealtimeTable({
    table: "project_consumables",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadConsumables,
  });

  const totalCost = consumables.reduce((sum, c) => sum + (Number(c.cost) || 0) * Number(c.quantity), 0);

  async function handleAddConsumable(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    const { error } = await supabase.from("project_consumables").insert({
      project_id: projectId,
      name: formName,
      quantity: parseFloat(formQuantity),
      unit: formUnit,
      cost: formCost ? parseFloat(formCost) : null,
      notes: formNotes || null,
    });
    if (!error) {
      setFormName(""); setFormQuantity("1"); setFormUnit("Stk"); setFormCost(""); setFormNotes("");
      setShowForm(false);
      loadConsumables();
    }
    setSaving(false);
  }

  async function handleDeleteConsumable(id: string) {
    if (!confirm("Material wirklich löschen?")) return;
    await supabase.from("project_consumables").delete().eq("id", id);
    loadConsumables();
  }

  async function handleSaveTransportNotes() {
    const { data, error } = await supabase
      .from("projects")
      .update({ transport_notes: transportDraft || null })
      .eq("id", projectId)
      .select()
      .single();
    if (!error && data) {
      onProjectUpdate(data as Project);
    }
    setEditingTransport(false);
  }

  if (loading) {
    return <div className="py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>Material wird geladen...</div>;
  }

  return (
    <div className="space-y-8">
      {/* ===== CONSUMABLES ===== */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">Verbrauchsmaterial</h2>
          <button
            onClick={() => setShowForm(!showForm)}
            className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-white rounded-lg transition-colors"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={16} />
            Material hinzufügen
          </button>
        </div>

        {showForm && (
          <div className="mb-4 p-5 rounded-lg" style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}>
            <h3 className="font-medium mb-3">Neues Material</h3>
            <form onSubmit={handleAddConsumable} className="space-y-3">
              <div className="grid grid-cols-4 gap-4">
                <div className="col-span-2">
                  <label className="block text-sm font-medium mb-1">Name *</label>
                  <input type="text" value={formName} onChange={(e) => setFormName(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                    placeholder="z.B. Gaffa-Tape, Kabelbinder, Batterien" required />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Menge *</label>
                  <input type="number" step="0.01" min="0" value={formQuantity} onChange={(e) => setFormQuantity(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }} required />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Einheit</label>
                  <select value={formUnit} onChange={(e) => setFormUnit(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}>
                    <option>Stk</option>
                    <option>Rolle</option>
                    <option>Meter</option>
                    <option>Liter</option>
                    <option>kg</option>
                    <option>Packung</option>
                    <option>Set</option>
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Stückkosten (€)</label>
                  <input type="number" step="0.01" min="0" value={formCost} onChange={(e) => setFormCost(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                    placeholder="0.00 (optional)" />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Notizen</label>
                  <input type="text" value={formNotes} onChange={(e) => setFormNotes(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm"
                    style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                    placeholder="z.B. Schwarz, 50mm breit" />
                </div>
              </div>
              <div className="flex gap-2">
                <button type="submit" disabled={saving}
                  className="px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50"
                  style={{ background: "var(--color-primary)" }}>
                  {saving ? "Wird gespeichert..." : "Hinzufügen"}
                </button>
                <button type="button" onClick={() => setShowForm(false)}
                  className="px-4 py-2 text-sm rounded-lg"
                  style={{ border: "1px solid var(--color-border)" }}>
                  Abbrechen
                </button>
              </div>
            </form>
          </div>
        )}

        {consumables.length === 0 ? (
          <div className="text-center py-8 rounded-lg"
            style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}>
            Noch kein Verbrauchsmaterial eingetragen
          </div>
        ) : (
          <div className="rounded-lg overflow-hidden" style={{ border: "1px solid var(--color-border)" }}>
            <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[450px]">
              <thead>
                <tr style={{ background: "var(--color-muted)" }}>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Material</th>
                  <th className="text-right px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Menge</th>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Einheit</th>
                  <th className="text-right px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Stückkosten</th>
                  <th className="text-right px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Gesamt</th>
                  <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Notizen</th>
                  <th className="px-4 py-3 w-10" />
                </tr>
              </thead>
              <tbody>
                {consumables.map((c) => {
                  const itemTotal = (Number(c.cost) || 0) * Number(c.quantity);
                  return (
                    <tr key={c.id} style={{ borderTop: "1px solid var(--color-border)" }} className="group">
                      <td className="px-4 py-3 font-medium">{c.name}</td>
                      <td className="px-4 py-3 text-right font-mono">{Number(c.quantity)}</td>
                      <td className="px-4 py-3">{c.unit}</td>
                      <td className="px-4 py-3 text-right font-mono">
                        {c.cost !== null ? `${Number(c.cost).toFixed(2)} €` : "–"}
                      </td>
                      <td className="px-4 py-3 text-right font-mono">
                        {c.cost !== null ? `${itemTotal.toFixed(2)} €` : "–"}
                      </td>
                      <td className="px-4 py-3" style={{ color: c.notes ? undefined : "var(--color-muted-foreground)" }}>
                        {c.notes || "–"}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <button onClick={() => handleDeleteConsumable(c.id)}
                          className="opacity-0 group-hover:opacity-100 transition-opacity text-xs"
                          style={{ color: "var(--color-destructive)" }}>
                          Löschen
                        </button>
                      </td>
                    </tr>
                  );
                })}
                {/* Totals row */}
                <tr style={{ borderTop: "2px solid var(--color-border)", background: "var(--color-muted)" }}>
                  <td className="px-4 py-3 font-semibold" colSpan={4}>Gesamt</td>
                  <td className="px-4 py-3 text-right font-mono font-semibold">
                    {totalCost.toFixed(2)} €
                  </td>
                  <td colSpan={2} />
                </tr>
              </tbody>
            </table>
            </div>
          </div>
        )}
      </div>

      {/* ===== TRANSPORT NOTES ===== */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">Transportnotizen</h2>
          {!editingTransport ? (
            <button
              onClick={() => { setTransportDraft(project.transport_notes || ""); setEditingTransport(true); }}
              className="px-3 py-1.5 text-sm rounded-lg"
              style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}
            >
              Bearbeiten
            </button>
          ) : (
            <div className="flex gap-2">
              <button onClick={handleSaveTransportNotes}
                className="px-3 py-1.5 text-sm text-white rounded-lg"
                style={{ background: "var(--color-primary)" }}>
                Speichern
              </button>
              <button onClick={() => setEditingTransport(false)}
                className="px-3 py-1.5 text-sm rounded-lg"
                style={{ border: "1px solid var(--color-border)" }}>
                Abbrechen
              </button>
            </div>
          )}
        </div>

        {editingTransport ? (
          <textarea
            value={transportDraft}
            onChange={(e) => setTransportDraft(e.target.value)}
            rows={5}
            className="w-full px-4 py-3 rounded-lg text-sm"
            style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
            placeholder="z.B. LKW 7.5t benötigt, Laderampe vorhanden, Parkplatz reserviert..."
          />
        ) : (
          <div className="p-4 rounded-lg text-sm whitespace-pre-wrap"
            style={{
              border: "1px solid var(--color-border)",
              background: "var(--color-surface)",
              color: project.transport_notes ? undefined : "var(--color-muted-foreground)",
              minHeight: "80px",
            }}>
            {project.transport_notes || "Keine Transportnotizen vorhanden. Klicke auf \"Bearbeiten\" um Infos hinzuzufügen."}
          </div>
        )}
      </div>
    </div>
  );
}
