"use client";

import { useState, useEffect, useCallback } from "react";
import { createClient } from "@/lib/supabase";
import type { ChecklistWithItems, ChecklistItem } from "@/types/database";
import { IconPlus, IconTrash } from "@/components/ui/icons";
import { appConfirm } from "@/components/ui/confirm-dialog";
import { useRealtimeTable } from "@/hooks/use-realtime-table";

interface TabChecklistsProps {
  projectId: string;
}

export function TabChecklists({ projectId }: TabChecklistsProps) {
  const supabase = createClient();
  const [checklists, setChecklists] = useState<ChecklistWithItems[]>([]);
  const [loading, setLoading] = useState(true);

  // New checklist form
  const [showNewChecklist, setShowNewChecklist] = useState(false);
  const [newChecklistName, setNewChecklistName] = useState("");
  const [savingChecklist, setSavingChecklist] = useState(false);

  // New item input per checklist
  const [newItemText, setNewItemText] = useState<Record<string, string>>({});

  const loadChecklists = useCallback(async () => {
    const { data } = await supabase
      .from("project_checklists")
      .select("*, project_checklist_items(*)")
      .eq("project_id", projectId)
      .order("sort_order");
    if (data) {
      // Sort items within each checklist
      const sorted = (data as ChecklistWithItems[]).map((cl) => ({
        ...cl,
        project_checklist_items: (cl.project_checklist_items || []).sort(
          (a, b) => a.sort_order - b.sort_order
        ),
      }));
      setChecklists(sorted);
    }
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    loadChecklists();
  }, [loadChecklists]);

  // Realtime: Live-Synchronisation
  useRealtimeTable({
    table: "project_checklists",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadChecklists,
  });
  useRealtimeTable({
    table: "project_checklist_items",
    onDataChange: loadChecklists,
  });

  async function handleCreateChecklist(e: React.FormEvent) {
    e.preventDefault();
    setSavingChecklist(true);
    const maxOrder = checklists.length > 0
      ? Math.max(...checklists.map((c) => c.sort_order))
      : -1;
    const { error } = await supabase.from("project_checklists").insert({
      project_id: projectId,
      name: newChecklistName,
      sort_order: maxOrder + 1,
    });
    if (!error) {
      setNewChecklistName("");
      setShowNewChecklist(false);
      loadChecklists();
    }
    setSavingChecklist(false);
  }

  async function handleDeleteChecklist(id: string) {
    if (!(await appConfirm("Checkliste und alle Einträge wirklich löschen?", { variant: "danger", confirmLabel: "Löschen" }))) return;
    await supabase.from("project_checklists").delete().eq("id", id);
    loadChecklists();
  }

  async function handleToggleItem(item: ChecklistItem) {
    // Optimistic update
    setChecklists((prev) =>
      prev.map((cl) => ({
        ...cl,
        project_checklist_items: cl.project_checklist_items.map((i) =>
          i.id === item.id ? { ...i, checked: !i.checked } : i
        ),
      }))
    );
    await supabase
      .from("project_checklist_items")
      .update({ checked: !item.checked })
      .eq("id", item.id);
  }

  async function handleAddItem(checklistId: string) {
    const text = (newItemText[checklistId] || "").trim();
    if (!text) return;

    const checklist = checklists.find((c) => c.id === checklistId);
    const items = checklist?.project_checklist_items || [];
    const maxOrder = items.length > 0 ? Math.max(...items.map((i) => i.sort_order)) : -1;

    const { error } = await supabase.from("project_checklist_items").insert({
      checklist_id: checklistId,
      label: text,
      sort_order: maxOrder + 1,
    });
    if (!error) {
      setNewItemText((prev) => ({ ...prev, [checklistId]: "" }));
      loadChecklists();
    }
  }

  async function handleDeleteItem(itemId: string) {
    await supabase.from("project_checklist_items").delete().eq("id", itemId);
    loadChecklists();
  }

  if (loading) {
    return <div className="py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>Checklisten werden geladen...</div>;
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-lg font-semibold">Checklisten</h2>
        <button
          onClick={() => setShowNewChecklist(!showNewChecklist)}
          className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-white rounded-lg transition-colors"
          style={{ background: "var(--color-primary)" }}
        >
          <IconPlus size={16} />
          Neue Checkliste
        </button>
      </div>

      {showNewChecklist && (
        <div className="mb-4 p-5 rounded-lg" style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}>
          <form onSubmit={handleCreateChecklist} className="flex gap-3">
            <input
              type="text"
              value={newChecklistName}
              onChange={(e) => setNewChecklistName(e.target.value)}
              className="flex-1 px-3 py-2 rounded-lg text-sm"
              style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
              placeholder="z.B. Aufbau-Checkliste, Packliste, Sicherheitscheck..."
              required
            />
            <button type="submit" disabled={savingChecklist}
              className="px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}>
              {savingChecklist ? "..." : "Erstellen"}
            </button>
            <button type="button" onClick={() => setShowNewChecklist(false)}
              className="px-4 py-2 text-sm rounded-lg"
              style={{ border: "1px solid var(--color-border)" }}>
              Abbrechen
            </button>
          </form>
        </div>
      )}

      {checklists.length === 0 ? (
        <div className="text-center py-12 rounded-lg"
          style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}>
          <p className="mb-2">Noch keine Checklisten erstellt</p>
          <p className="text-xs">Erstelle Checklisten für Aufbau, Packliste, Sicherheit und mehr.</p>
        </div>
      ) : (
        <div className="space-y-4">
          {checklists.map((checklist) => {
            const items = checklist.project_checklist_items;
            const total = items.length;
            const checked = items.filter((i) => i.checked).length;
            const percent = total > 0 ? Math.round((checked / total) * 100) : 0;

            return (
              <div key={checklist.id} className="rounded-lg overflow-hidden"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}>
                {/* Checklist Header */}
                <div className="flex items-center justify-between px-4 py-3"
                  style={{ borderBottom: "1px solid var(--color-border)" }}>
                  <div className="flex items-center gap-3">
                    <h3 className="font-semibold text-sm">{checklist.name}</h3>
                    <span className="text-xs px-2 py-0.5 rounded-full"
                      style={{
                        background: percent === 100 ? "#dcfce7" : "var(--color-muted)",
                        color: percent === 100 ? "#16a34a" : "var(--color-muted-foreground)",
                      }}>
                      {checked}/{total}
                    </span>
                  </div>
                  <div className="flex items-center gap-3">
                    {/* Progress bar */}
                    <div className="w-24 h-1.5 rounded-full overflow-hidden" style={{ background: "var(--color-muted)" }}>
                      <div className="h-full rounded-full transition-all duration-300"
                        style={{
                          width: `${percent}%`,
                          background: percent === 100 ? "#16a34a" : "var(--color-primary)",
                        }} />
                    </div>
                    <button onClick={() => handleDeleteChecklist(checklist.id)}
                      className="p-1 rounded transition-colors"
                      style={{ color: "var(--color-muted-foreground)" }}
                      title="Checkliste löschen">
                      <IconTrash size={14} />
                    </button>
                  </div>
                </div>

                {/* Items */}
                <div className="divide-y" style={{ borderColor: "var(--color-border-light, var(--color-border))" }}>
                  {items.map((item) => (
                    <div key={item.id} className="group flex items-center gap-3 px-4 py-2.5">
                      <input
                        type="checkbox"
                        checked={item.checked}
                        onChange={() => handleToggleItem(item)}
                        className="w-4 h-4 rounded shrink-0"
                        style={{ accentColor: "var(--color-primary)" }}
                      />
                      <span className="flex-1 text-sm" style={{
                        textDecoration: item.checked ? "line-through" : "none",
                        color: item.checked ? "var(--color-muted-foreground)" : undefined,
                      }}>
                        {item.label}
                      </span>
                      <button onClick={() => handleDeleteItem(item.id)}
                        className="opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded shrink-0"
                        style={{ color: "var(--color-destructive)" }}>
                        <IconTrash size={12} />
                      </button>
                    </div>
                  ))}
                </div>

                {/* Add item input */}
                <div className="px-4 py-2.5 flex gap-2" style={{ borderTop: "1px solid var(--color-border)" }}>
                  <input
                    type="text"
                    value={newItemText[checklist.id] || ""}
                    onChange={(e) => setNewItemText((prev) => ({ ...prev, [checklist.id]: e.target.value }))}
                    onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); handleAddItem(checklist.id); } }}
                    className="flex-1 px-3 py-1.5 rounded-lg text-sm"
                    style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                    placeholder="Neuen Punkt hinzufügen..."
                  />
                  <button onClick={() => handleAddItem(checklist.id)}
                    className="px-3 py-1.5 text-sm rounded-lg"
                    style={{ border: "1px solid var(--color-border)", color: "var(--color-muted-foreground)" }}>
                    Hinzufügen
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
