"use client";

import { useState, useRef } from "react";
import { createClient } from "@/lib/supabase";
import type { InventoryItem } from "@/types/database";
import { IconX, IconUpload, IconCheck } from "@/components/ui/icons";
import { appAlert } from "@/components/ui/confirm-dialog";
import * as XLSX from "xlsx";

interface ExcelImportProps {
  existingItems: InventoryItem[];
  onClose: () => void;
  onImportComplete: () => void;
  categoryPrefixes: Record<string, string>;
}

// Die Felder, die importiert werden können
const inventoryFields = [
  { key: "skip", label: "— Überspringen —" },
  { key: "inventory_number", label: "Inventarnummer" },
  { key: "name", label: "Name *" },
  { key: "description", label: "Beschreibung" },
  { key: "category", label: "Kategorie" },
  { key: "quantity", label: "Menge" },
  { key: "condition", label: "Zustand" },
  { key: "cost_per_day", label: "Preis/Tag (€)" },
  { key: "location", label: "Lagerort" },
  { key: "owner", label: "Eigentümer" },
] as const;

type FieldKey = (typeof inventoryFields)[number]["key"];

// Automatische Zuordnung basierend auf Spaltenname
function autoDetectMapping(header: string): FieldKey {
  const h = header.toLowerCase().trim();
  if (h.includes("inv") && (h.includes("nr") || h.includes("num"))) return "inventory_number";
  if (h === "name" || h === "artikel" || h === "bezeichnung" || h === "artikelname") return "name";
  if (h.includes("beschreib") || h === "description" || h === "details") return "description";
  if (h.includes("kategorie") || h === "category" || h === "typ" || h === "type" || h === "gruppe") return "category";
  if (h === "menge" || h === "anzahl" || h === "quantity" || h === "stk" || h === "stück" || h === "bestand") return "quantity";
  if (h.includes("zustand") || h === "condition" || h === "status" || h === "qualität") return "condition";
  if (h.includes("preis") || h.includes("kosten") || h.includes("cost") || h.includes("€") || h.includes("eur") || h.includes("tag")) return "cost_per_day";
  if (h.includes("lager") || h.includes("ort") || h.includes("standort") || h === "location") return "location";
  if (h.includes("eigen") || h.includes("owner") || h.includes("besitzer")) return "owner";
  return "skip";
}

// Zustandsmapping von deutschen Begriffen
function mapCondition(val: string): InventoryItem["condition"] {
  const v = val.toLowerCase().trim();
  if (v === "neu" || v === "new") return "new";
  if (v === "gut" || v === "good") return "good";
  if (v === "befriedigend" || v === "ok" || v === "fair") return "fair";
  if (v === "schlecht" || v === "poor") return "poor";
  if (v === "defekt" || v === "broken" || v === "kaputt") return "broken";
  if (v === "ausgemustert" || v === "retired" || v === "alt") return "retired";
  return "good"; // Fallback
}

type Step = "upload" | "mapping" | "preview" | "importing" | "done";

export function ExcelImport({ existingItems, onClose, onImportComplete, categoryPrefixes }: ExcelImportProps) {
  const supabase = createClient();
  const fileRef = useRef<HTMLInputElement>(null);

  const [step, setStep] = useState<Step>("upload");
  const [fileName, setFileName] = useState("");
  const [headers, setHeaders] = useState<string[]>([]);
  const [rawRows, setRawRows] = useState<string[][]>([]);
  const [mapping, setMapping] = useState<Record<number, FieldKey>>({});
  const [importProgress, setImportProgress] = useState(0);
  const [importTotal, setImportTotal] = useState(0);
  const [importErrors, setImportErrors] = useState<string[]>([]);
  const [importedCount, setImportedCount] = useState(0);

  // ── Schritt 1: Datei einlesen ──────────────────────────────────────────
  function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    setFileName(file.name);
    const reader = new FileReader();
    reader.onload = async (evt) => {
      const data = new Uint8Array(evt.target?.result as ArrayBuffer);
      const wb = XLSX.read(data, { type: "array" });
      const ws = wb.Sheets[wb.SheetNames[0]];
      const json = XLSX.utils.sheet_to_json<string[]>(ws, { header: 1, defval: "" });

      if (json.length < 2) {
        await appAlert("Die Datei enthält keine Daten (mindestens Kopfzeile + 1 Datenzeile benötigt).");
        return;
      }

      const hdrs = (json[0] as string[]).map(String);
      const rows = json.slice(1).filter((r) => r.some((cell) => String(cell).trim() !== ""));

      setHeaders(hdrs);
      setRawRows(rows.map((r) => r.map(String)));

      // Auto-Mapping
      const autoMap: Record<number, FieldKey> = {};
      hdrs.forEach((h, i) => {
        autoMap[i] = autoDetectMapping(h);
      });
      setMapping(autoMap);
      setStep("mapping");
    };
    reader.readAsArrayBuffer(file);
  }

  // ── Schritt 2: Mapping aktualisieren ───────────────────────────────────
  function updateMapping(colIndex: number, field: FieldKey) {
    setMapping((prev) => ({ ...prev, [colIndex]: field }));
  }

  function hasNameMapping(): boolean {
    return Object.values(mapping).includes("name");
  }

  // ── Schritt 3: Vorschau generieren ─────────────────────────────────────
  type PreviewRow = {
    inventory_number: string;
    name: string;
    description: string;
    category: string;
    quantity: number;
    condition: InventoryItem["condition"];
    cost_per_day: number;
    location: string;
    owner: string;
  };

  function buildPreviewRows(): PreviewRow[] {
    // Zu Auto-Nummern-Vergabe brauchen wir Counter pro Kategorie
    const counters: Record<string, number> = {};
    // Bestehende Items zählen
    for (const item of existingItems) {
      const prefix = item.inventory_number?.split("-")[0] || "";
      const num = parseInt(item.inventory_number?.split("-")[1] || "0", 10);
      if (prefix && !isNaN(num)) {
        counters[prefix] = Math.max(counters[prefix] || 0, num);
      }
    }

    return rawRows.map((row) => {
      const mapped: Record<string, string> = {};
      Object.entries(mapping).forEach(([colStr, field]) => {
        if (field !== "skip") {
          mapped[field] = row[Number(colStr)] || "";
        }
      });

      const category = mapped.category || "Sonstiges";
      let invNumber = mapped.inventory_number || "";

      // Auto-Inventarnummer wenn leer
      if (!invNumber) {
        const prefix = categoryPrefixes[category] || category.slice(0, 3).toUpperCase();
        counters[prefix] = (counters[prefix] || 0) + 1;
        invNumber = `${prefix}-${String(counters[prefix]).padStart(3, "0")}`;
      }

      return {
        inventory_number: invNumber,
        name: mapped.name || "",
        description: mapped.description || "",
        category,
        quantity: parseInt(mapped.quantity, 10) || 1,
        condition: mapped.condition ? mapCondition(mapped.condition) : "good",
        cost_per_day: parseFloat(String(mapped.cost_per_day).replace(",", ".")) || 0,
        location: mapped.location || "",
        owner: mapped.owner || "",
      };
    }).filter((r) => r.name.trim() !== ""); // Zeilen ohne Name überspringen
  }

  const previewRows = step === "preview" || step === "importing" || step === "done" ? buildPreviewRows() : [];

  // ── Schritt 4: Import ausführen ────────────────────────────────────────
  async function handleImport() {
    setStep("importing");
    const rows = buildPreviewRows();
    setImportTotal(rows.length);
    setImportProgress(0);
    setImportErrors([]);

    let successCount = 0;
    const errors: string[] = [];

    // Batch-Import in 20er Gruppen
    const batchSize = 20;
    for (let i = 0; i < rows.length; i += batchSize) {
      const batch = rows.slice(i, i + batchSize).map((r) => ({
        inventory_number: r.inventory_number,
        name: r.name,
        description: r.description || null,
        category: r.category,
        quantity: r.quantity,
        condition: r.condition,
        cost_per_day: r.cost_per_day,
        location: r.location || null,
        owner: r.owner || null,
      }));

      const { error } = await supabase.from("inventory_items").insert(batch);

      if (error) {
        // Fallback: einzeln importieren um fehlerhafte Zeilen zu identifizieren
        for (const row of batch) {
          const { error: singleErr } = await supabase.from("inventory_items").insert(row);
          if (singleErr) {
            errors.push(`${row.inventory_number} "${row.name}": ${singleErr.message}`);
          } else {
            successCount++;
          }
        }
      } else {
        successCount += batch.length;
      }

      setImportProgress(Math.min(i + batchSize, rows.length));
    }

    setImportedCount(successCount);
    setImportErrors(errors);
    setStep("done");
  }

  // ── Style-Helfer ───────────────────────────────────────────────────────
  const inputStyle = { border: "1px solid var(--color-border)", background: "var(--color-background)" };

  const conditionLabels: Record<string, string> = {
    new: "Neu", good: "Gut", fair: "Befriedigend", poor: "Schlecht", broken: "Defekt", retired: "Ausgemustert",
  };

  // ── Render ─────────────────────────────────────────────────────────────
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center" style={{ background: "rgba(0,0,0,0.5)" }}>
      <div
        className="relative w-full rounded-xl overflow-hidden flex flex-col"
        style={{
          maxWidth: step === "upload" ? "480px" : "960px",
          maxHeight: "85vh",
          background: "var(--color-surface)",
          border: "1px solid var(--color-border)",
          boxShadow: "var(--shadow-lg, 0 25px 50px -12px rgba(0,0,0,.25))",
        }}
      >
        {/* Modal Header */}
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: "1px solid var(--color-border)" }}>
          <div>
            <h2 className="text-lg font-semibold">Excel-Import</h2>
            <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
              {step === "upload" && "Schritt 1/3 — Datei auswählen"}
              {step === "mapping" && "Schritt 2/3 — Feldzuordnung prüfen"}
              {step === "preview" && "Schritt 3/3 — Vorschau & Importieren"}
              {step === "importing" && "Import läuft..."}
              {step === "done" && "Import abgeschlossen"}
            </p>
          </div>
          <button onClick={onClose} style={{ color: "var(--color-muted-foreground)" }}><IconX size={20} /></button>
        </div>

        {/* Modal Body */}
        <div className="flex-1 overflow-y-auto px-6 py-5">

          {/* ── UPLOAD ── */}
          {step === "upload" && (
            <div>
              <div
                className="flex flex-col items-center justify-center py-12 rounded-lg cursor-pointer transition-colors"
                style={{ border: "2px dashed var(--color-border)", background: "var(--color-background)" }}
                onClick={() => fileRef.current?.click()}
              >
                <IconUpload size={40} style={{ color: "var(--color-muted-foreground)" } as React.CSSProperties} />
                <p className="mt-3 font-medium text-sm">Datei auswählen oder hierher ziehen</p>
                <p className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>.xlsx, .xls oder .csv</p>
              </div>
              <input
                ref={fileRef}
                type="file"
                accept=".xlsx,.xls,.csv"
                className="hidden"
                onChange={handleFileChange}
              />
              <div className="mt-4 text-xs space-y-1" style={{ color: "var(--color-muted-foreground)" }}>
                <p><strong>Hinweis:</strong> Die erste Zeile muss die Spaltenüberschriften enthalten.</p>
                <p>Pflichtfeld: <strong>Name</strong>. Alle anderen Felder sind optional.</p>
                <p>Inventarnummern werden automatisch vergeben wenn nicht angegeben.</p>
              </div>
            </div>
          )}

          {/* ── MAPPING ── */}
          {step === "mapping" && (
            <div>
              <div className="mb-4 p-3 rounded-lg text-sm" style={{ background: "var(--color-muted)" }}>
                <strong>{fileName}</strong> — {rawRows.length} Zeilen, {headers.length} Spalten erkannt.
                Die Zuordnung wurde automatisch vorgeschlagen. Bitte prüfe und korrigiere bei Bedarf.
              </div>

              <div className="rounded-lg overflow-hidden" style={{ border: "1px solid var(--color-border)" }}>
                <table className="w-full text-sm">
                  <thead>
                    <tr style={{ background: "var(--color-muted)" }}>
                      <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Excel-Spalte</th>
                      <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Beispielwerte</th>
                      <th className="text-left px-4 py-3 font-medium" style={{ color: "var(--color-muted-foreground)" }}>Zuordnung</th>
                    </tr>
                  </thead>
                  <tbody>
                    {headers.map((header, colIdx) => {
                      // Zeige bis zu 3 Beispielwerte
                      const examples = rawRows
                        .slice(0, 3)
                        .map((r) => r[colIdx])
                        .filter((v) => v && v.trim())
                        .join(", ");

                      return (
                        <tr key={colIdx} style={{ borderTop: "1px solid var(--color-border)" }}>
                          <td className="px-4 py-3 font-medium">{header}</td>
                          <td className="px-4 py-3" style={{ color: "var(--color-muted-foreground)" }}>
                            <span className="text-xs">{examples || "—"}</span>
                          </td>
                          <td className="px-4 py-3">
                            <select
                              value={mapping[colIdx] || "skip"}
                              onChange={(e) => updateMapping(colIdx, e.target.value as FieldKey)}
                              className="w-full px-3 py-1.5 rounded-lg text-sm"
                              style={inputStyle}
                            >
                              {inventoryFields.map((f) => (
                                <option key={f.key} value={f.key}>{f.label}</option>
                              ))}
                            </select>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              {!hasNameMapping() && (
                <div className="mt-3 p-3 rounded-lg text-sm" style={{ background: "#fef2f2", color: "#dc2626" }}>
                  Bitte ordne mindestens eine Spalte dem Feld <strong>„Name"</strong> zu.
                </div>
              )}
            </div>
          )}

          {/* ── PREVIEW ── */}
          {step === "preview" && (
            <div>
              <div className="mb-4 p-3 rounded-lg text-sm" style={{ background: "var(--color-muted)" }}>
                <strong>{previewRows.length} Artikel</strong> werden importiert.
                Prüfe die Vorschau – danach wird importiert.
              </div>

              <div className="rounded-lg overflow-x-auto" style={{ border: "1px solid var(--color-border)" }}>
                <table className="w-full text-sm">
                  <thead>
                    <tr style={{ background: "var(--color-muted)" }}>
                      <th className="text-left px-3 py-2 font-medium text-xs" style={{ color: "var(--color-muted-foreground)" }}>Inv.-Nr.</th>
                      <th className="text-left px-3 py-2 font-medium text-xs" style={{ color: "var(--color-muted-foreground)" }}>Name</th>
                      <th className="text-left px-3 py-2 font-medium text-xs" style={{ color: "var(--color-muted-foreground)" }}>Kategorie</th>
                      <th className="text-center px-3 py-2 font-medium text-xs" style={{ color: "var(--color-muted-foreground)" }}>Menge</th>
                      <th className="text-left px-3 py-2 font-medium text-xs" style={{ color: "var(--color-muted-foreground)" }}>Zustand</th>
                      <th className="text-right px-3 py-2 font-medium text-xs" style={{ color: "var(--color-muted-foreground)" }}>€/Tag</th>
                      <th className="text-left px-3 py-2 font-medium text-xs" style={{ color: "var(--color-muted-foreground)" }}>Ort</th>
                    </tr>
                  </thead>
                  <tbody>
                    {previewRows.slice(0, 50).map((row, i) => (
                      <tr key={i} style={{ borderTop: "1px solid var(--color-border)" }}>
                        <td className="px-3 py-2 font-mono text-xs">{row.inventory_number}</td>
                        <td className="px-3 py-2 font-medium">{row.name}</td>
                        <td className="px-3 py-2">{row.category}</td>
                        <td className="px-3 py-2 text-center">{row.quantity}</td>
                        <td className="px-3 py-2 text-xs">{conditionLabels[row.condition] || row.condition}</td>
                        <td className="px-3 py-2 text-right font-mono">{row.cost_per_day.toFixed(2)}</td>
                        <td className="px-3 py-2" style={{ color: row.location ? undefined : "var(--color-muted-foreground)" }}>
                          {row.location || "–"}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {previewRows.length > 50 && (
                <p className="text-xs mt-2 text-center" style={{ color: "var(--color-muted-foreground)" }}>
                  ... und {previewRows.length - 50} weitere Zeilen
                </p>
              )}
            </div>
          )}

          {/* ── IMPORTING ── */}
          {step === "importing" && (
            <div className="py-8 text-center">
              <p className="font-medium mb-4">Import läuft...</p>
              <div className="w-full h-3 rounded-full overflow-hidden mx-auto max-w-md" style={{ background: "var(--color-muted)" }}>
                <div
                  className="h-full rounded-full transition-all duration-300"
                  style={{
                    width: `${importTotal > 0 ? (importProgress / importTotal) * 100 : 0}%`,
                    background: "var(--color-primary)",
                  }}
                />
              </div>
              <p className="text-sm mt-2" style={{ color: "var(--color-muted-foreground)" }}>
                {importProgress} / {importTotal} Artikel
              </p>
            </div>
          )}

          {/* ── DONE ── */}
          {step === "done" && (
            <div className="py-8 text-center">
              <div className="w-14 h-14 rounded-full mx-auto mb-4 flex items-center justify-center"
                style={{ background: importErrors.length > 0 ? "#fef3c7" : "#dcfce7" }}>
                <IconCheck size={28} style={{ color: importErrors.length > 0 ? "#b45309" : "#16a34a" } as React.CSSProperties} />
              </div>
              <p className="text-lg font-semibold mb-1">
                {importedCount} Artikel importiert
              </p>
              {importErrors.length > 0 && (
                <div className="mt-4 text-left max-w-lg mx-auto">
                  <p className="text-sm font-medium mb-2" style={{ color: "var(--color-destructive)" }}>
                    {importErrors.length} Fehler:
                  </p>
                  <div className="max-h-40 overflow-y-auto rounded-lg p-3 text-xs space-y-1"
                    style={{ background: "var(--color-muted)" }}>
                    {importErrors.map((err, i) => (
                      <p key={i}>{err}</p>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Modal Footer */}
        <div className="flex items-center justify-between px-6 py-4" style={{ borderTop: "1px solid var(--color-border)" }}>
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            {step === "mapping" && `${rawRows.length} Datenzeilen`}
            {step === "preview" && `${previewRows.length} Artikel bereit`}
          </div>
          <div className="flex gap-2">
            {step === "mapping" && (
              <>
                <button onClick={() => { setStep("upload"); setHeaders([]); setRawRows([]); }}
                  className="px-4 py-2 text-sm rounded-lg"
                  style={{ border: "1px solid var(--color-border)" }}>
                  Zurück
                </button>
                <button
                  onClick={() => setStep("preview")}
                  disabled={!hasNameMapping()}
                  className="px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50"
                  style={{ background: "var(--color-primary)" }}>
                  Weiter zur Vorschau
                </button>
              </>
            )}
            {step === "preview" && (
              <>
                <button onClick={() => setStep("mapping")}
                  className="px-4 py-2 text-sm rounded-lg"
                  style={{ border: "1px solid var(--color-border)" }}>
                  Zurück
                </button>
                <button
                  onClick={handleImport}
                  className="px-4 py-2 text-sm text-white rounded-lg font-medium"
                  style={{ background: "var(--color-primary)" }}>
                  {previewRows.length} Artikel importieren
                </button>
              </>
            )}
            {step === "done" && (
              <button
                onClick={() => { onImportComplete(); onClose(); }}
                className="px-4 py-2 text-sm text-white rounded-lg"
                style={{ background: "var(--color-primary)" }}>
                Fertig
              </button>
            )}
            {(step === "upload") && (
              <button onClick={onClose}
                className="px-4 py-2 text-sm rounded-lg"
                style={{ border: "1px solid var(--color-border)" }}>
                Abbrechen
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
