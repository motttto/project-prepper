"use client";

import { useState, useEffect, useCallback, useMemo } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import type { CostItem, Project } from "@/types/database";
import { IconPlus, IconX, IconTrash, IconFilter } from "@/components/ui/icons";
import { showToast } from "@/hooks/use-toast";
import { appConfirm } from "@/components/ui/confirm-dialog";

const categoryLabels: Record<CostItem["category"], string> = {
  personnel: "Personal",
  material: "Material",
  inventory: "Inventar",
  external: "Extern",
  other: "Sonstiges",
};

const categoryColors: Record<CostItem["category"], { bg: string; color: string }> = {
  personnel: { bg: "var(--color-info-light)", color: "var(--color-info)" },
  material: { bg: "var(--color-warning-light)", color: "var(--color-warning)" },
  inventory: { bg: "var(--color-primary-light)", color: "var(--color-primary)" },
  external: { bg: "var(--color-success-light)", color: "var(--color-success)" },
  other: { bg: "var(--color-muted)", color: "var(--color-muted-foreground)" },
};

export default function CostsPage() {
  const supabase = createClient();
  const { orgId } = useOrg();
  const [costs, setCosts] = useState<CostItem[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [selectedProject, setSelectedProject] = useState<string>("all");
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [saving, setSaving] = useState(false);

  // Formular
  const [formProjectId, setFormProjectId] = useState("");
  const [formCategory, setFormCategory] = useState<CostItem["category"]>("other");
  const [formDescription, setFormDescription] = useState("");
  const [formPlanned, setFormPlanned] = useState(0);
  const [formActual, setFormActual] = useState("");

  const loadData = useCallback(async () => {
    if (!orgId) return;
    const [costsRes, projectsRes] = await Promise.all([
      supabase.from("cost_items").select("*").order("created_at"),
      supabase.from("projects").select("id, name").eq("org_id", orgId).order("name"),
    ]);
    if (costsRes.data) setCosts(costsRes.data as CostItem[]);
    if (projectsRes.data) setProjects(projectsRes.data as Project[]);
    setLoading(false);
  }, [supabase, orgId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    const { error } = await supabase.from("cost_items").insert({
      project_id: formProjectId,
      category: formCategory,
      description: formDescription,
      amount_planned: formPlanned,
      amount_actual: formActual !== "" ? Number(formActual) : null,
    });
    if (error) {
      showToast("Fehler beim Erstellen: " + error.message, "error");
    } else {
      setFormProjectId(""); setFormCategory("other"); setFormDescription("");
      setFormPlanned(0); setFormActual("");
      setShowCreate(false);
      loadData();
      showToast("Kostenposition erstellt", "success");
    }
    setSaving(false);
  }

  async function handleDelete(id: string) {
    if (!(await appConfirm("Kostenposition wirklich löschen?", { variant: "danger", confirmLabel: "Löschen" }))) return;
    const { error } = await supabase.from("cost_items").delete().eq("id", id);
    if (error) {
      showToast("Fehler beim Löschen: " + error.message, "error");
    } else {
      loadData();
      showToast("Kostenposition gelöscht", "success");
    }
  }

  const filtered = useMemo(() =>
    selectedProject === "all"
      ? costs
      : costs.filter((c) => c.project_id === selectedProject),
    [costs, selectedProject]
  );

  const totalPlanned = filtered.reduce((sum, c) => sum + Number(c.amount_planned), 0);
  const totalActual = filtered.reduce((sum, c) => sum + (c.amount_actual !== null ? Number(c.amount_actual) : 0), 0);
  const openItems = filtered.filter((c) => c.amount_actual === null).length;
  const diff = totalActual - totalPlanned;

  const projectName = (id: string) => projects.find((p) => p.id === id)?.name || "–";

  if (loading) {
    return (
      <div className="space-y-4">
        <div className="h-8 w-48 skeleton" />
        <div className="grid grid-cols-3 gap-5">{[1, 2, 3].map((i) => <div key={i} className="h-24 skeleton rounded-xl" />)}</div>
        <div className="h-64 skeleton rounded-xl" />
      </div>
    );
  }

  return (
    <div className="animate-fadeIn">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold">Kostenkalkulation</h1>
          <p className="text-sm mt-0.5" style={{ color: "var(--color-muted-foreground)" }}>
            {costs.length} Positionen
          </p>
        </div>
        <button
          onClick={() => setShowCreate(!showCreate)}
          className="flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium text-white transition-colors"
          style={{ background: "var(--color-primary)" }}
          onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-primary-hover)"}
          onMouseLeave={(e) => e.currentTarget.style.background = "var(--color-primary)"}
        >
          <IconPlus size={16} />
          Neue Position
        </button>
      </div>

      {/* Create Form */}
      {showCreate && (
        <div className="mb-6 p-6 rounded-xl animate-slideDown"
          style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-md)", border: "1px solid var(--color-border)" }}>
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold">Neue Kostenposition</h2>
            <button onClick={() => setShowCreate(false)} style={{ color: "var(--color-muted-foreground)" }}><IconX size={20} /></button>
          </div>
          <form onSubmit={handleCreate} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1.5">Projekt</label>
                <select value={formProjectId} onChange={(e) => setFormProjectId(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }} required>
                  <option value="">Projekt wählen...</option>
                  {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Kategorie</label>
                <select value={formCategory} onChange={(e) => setFormCategory(e.target.value as CostItem["category"])}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}>
                  {Object.entries(categoryLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                </select>
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium mb-1.5">Beschreibung</label>
              <input type="text" value={formDescription} onChange={(e) => setFormDescription(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                placeholder="z.B. Catering (80 Personen)" required />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1.5">Geplant / Soll (&euro;)</label>
                <input type="number" value={formPlanned} onChange={(e) => setFormPlanned(Number(e.target.value))} min={0} step={0.01}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }} required />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1.5">Tatsächlich / Ist (&euro;)</label>
                <input type="number" value={formActual} onChange={(e) => setFormActual(e.target.value)} min={0} step={0.01}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{ border: "1px solid var(--color-border)", background: "var(--color-background)" }}
                  placeholder="Noch offen" />
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

      {/* Projekt-Filter (Dropdown statt Pills) */}
      <div className="flex items-center gap-3 mb-6">
        <IconFilter size={16} style={{ color: "var(--color-muted-foreground)" }} />
        <select
          value={selectedProject}
          onChange={(e) => setSelectedProject(e.target.value)}
          className="px-3 py-2 rounded-lg text-sm"
          style={{ border: "1px solid var(--color-border)", background: "var(--color-surface)" }}
        >
          <option value="all">Alle Projekte</option>
          {projects.map((p) => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </select>
        {selectedProject !== "all" && (
          <button
            onClick={() => setSelectedProject("all")}
            className="text-xs flex items-center gap-1"
            style={{ color: "var(--color-primary)" }}
          >
            <IconX size={12} /> Filter zurücksetzen
          </button>
        )}
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-3 gap-5 mb-6">
        <div className="p-5 rounded-xl" style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-sm)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: "var(--color-muted-foreground)" }}>
            Geplant (Soll)
          </div>
          <div className="text-2xl font-bold">{totalPlanned.toLocaleString("de-DE")} &euro;</div>
        </div>
        <div className="p-5 rounded-xl" style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-sm)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: "var(--color-muted-foreground)" }}>
            Tatsächlich (Ist)
          </div>
          <div className="text-2xl font-bold">{totalActual.toLocaleString("de-DE")} &euro;</div>
          {totalPlanned > 0 && (
            <div className="mt-2 h-1.5 rounded-full overflow-hidden" style={{ background: "var(--color-muted)" }}>
              <div
                className="h-full rounded-full transition-all"
                style={{
                  width: `${Math.min((totalActual / totalPlanned) * 100, 100)}%`,
                  background: totalActual > totalPlanned ? "var(--color-destructive)" : "var(--color-success)",
                }}
              />
            </div>
          )}
        </div>
        <div className="p-5 rounded-xl" style={{ background: "var(--color-surface)", boxShadow: "var(--shadow-sm)", border: "1px solid var(--color-border-light)" }}>
          <div className="text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: "var(--color-muted-foreground)" }}>
            Differenz
          </div>
          <div className="text-2xl font-bold" style={{ color: diff > 0 ? "var(--color-destructive)" : diff < 0 ? "var(--color-success)" : "var(--color-foreground)" }}>
            {diff > 0 ? "+" : ""}{diff.toLocaleString("de-DE")} &euro;
          </div>
          <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
            {openItems} offene Positionen
          </div>
        </div>
      </div>

      {/* Table */}
      {filtered.length === 0 ? (
        <div className="text-center py-16 rounded-xl" style={{ border: "2px dashed var(--color-border)", color: "var(--color-muted-foreground)" }}>
          <p className="text-lg mb-1">Noch keine Kostenpositionen</p>
          <p className="text-sm">Lege erst ein Projekt an, dann kannst du Kosten zuordnen.</p>
        </div>
      ) : (
        <div className="rounded-xl overflow-hidden"
          style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)", boxShadow: "var(--shadow-sm)" }}>
          <div className="overflow-x-auto">
          <table className="w-full min-w-[600px]">
            <thead>
              <tr style={{ borderBottom: "1px solid var(--color-border-light)" }}>
                {selectedProject === "all" && (
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Projekt</th>
                )}
                <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Kategorie</th>
                <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Beschreibung</th>
                <th className="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Soll</th>
                <th className="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Ist</th>
                <th className="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider" style={{ color: "var(--color-muted-foreground)" }}>Diff.</th>
                <th className="w-10 px-4 py-3"></th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((cost) => {
                const itemDiff = cost.amount_actual !== null
                  ? Number(cost.amount_actual) - Number(cost.amount_planned)
                  : null;
                return (
                  <tr key={cost.id} className="transition-colors group"
                    style={{ borderBottom: "1px solid var(--color-border-light)" }}
                    onMouseEnter={(e) => e.currentTarget.style.background = "var(--color-surface-hover)"}
                    onMouseLeave={(e) => e.currentTarget.style.background = "transparent"}>
                    {selectedProject === "all" && (
                      <td className="px-4 py-3 text-sm">{projectName(cost.project_id)}</td>
                    )}
                    <td className="px-4 py-3">
                      <span className="text-xs px-2 py-0.5 rounded-full font-medium"
                        style={categoryColors[cost.category]}>
                        {categoryLabels[cost.category]}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm">{cost.description}</td>
                    <td className="px-4 py-3 text-sm text-right tabular-nums">
                      {Number(cost.amount_planned).toLocaleString("de-DE")} &euro;
                    </td>
                    <td className="px-4 py-3 text-sm text-right tabular-nums">
                      {cost.amount_actual !== null
                        ? `${Number(cost.amount_actual).toLocaleString("de-DE")} \u20AC`
                        : <span style={{ color: "var(--color-muted-foreground)" }}>offen</span>}
                    </td>
                    <td className="px-4 py-3 text-sm text-right tabular-nums">
                      {itemDiff !== null ? (
                        <span style={{
                          color: itemDiff > 0 ? "var(--color-destructive)" : itemDiff < 0 ? "var(--color-success)" : "var(--color-foreground)"
                        }}>
                          {itemDiff > 0 ? "+" : ""}{itemDiff.toLocaleString("de-DE")} &euro;
                        </span>
                      ) : <span style={{ color: "var(--color-muted-foreground)" }}>–</span>}
                    </td>
                    <td className="px-4 py-3">
                      <button onClick={() => handleDelete(cost.id)}
                        className="opacity-0 group-hover:opacity-100 p-1 rounded transition-opacity"
                        style={{ color: "var(--color-destructive)" }} title="Löschen">
                        <IconTrash size={14} />
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr style={{ background: "var(--color-muted)" }}>
                <td className="px-4 py-3 text-sm font-semibold" colSpan={selectedProject === "all" ? 3 : 2}>
                  Gesamt
                </td>
                <td className="px-4 py-3 text-sm font-semibold text-right tabular-nums">
                  {totalPlanned.toLocaleString("de-DE")} &euro;
                </td>
                <td className="px-4 py-3 text-sm font-semibold text-right tabular-nums">
                  {totalActual.toLocaleString("de-DE")} &euro;
                </td>
                <td className="px-4 py-3 text-sm font-semibold text-right tabular-nums">
                  {diff !== 0 && (
                    <span style={{ color: diff > 0 ? "var(--color-destructive)" : "var(--color-success)" }}>
                      {diff > 0 ? "+" : ""}{diff.toLocaleString("de-DE")} &euro;
                    </span>
                  )}
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
          </div>
        </div>
      )}
    </div>
  );
}
