"use client";

import { useState, useEffect, useCallback, useRef } from "react";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import type { InventoryDocument } from "@/types/database";
import { IconExternalLink, IconTrash, IconPlus } from "@/components/ui/icons";

interface InventoryDocumentsSectionProps {
  itemId: string;
  /** Upload/Löschen nur wenn editierbar; Öffnen immer möglich */
  isEditable: boolean;
}

function formatSize(bytes: number | null): string {
  if (!bytes) return "";
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function InventoryDocumentsSection({
  itemId,
  isEditable,
}: InventoryDocumentsSectionProps) {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const [docs, setDocs] = useState<InventoryDocument[]>([]);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [opening, setOpening] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const loadDocs = useCallback(async () => {
    setLoading(true);
    const { data } = await supabase
      .from("inventory_documents")
      .select("*")
      .eq("item_id", itemId)
      .order("created_at", { ascending: false });
    if (data) setDocs(data);
    setLoading(false);
  }, [supabase, itemId]);

  useEffect(() => {
    loadDocs();
  }, [loadDocs]);

  async function handleFile(file: File) {
    setError(null);
    if (file.type !== "application/pdf" && !file.name.toLowerCase().endsWith(".pdf")) {
      setError("Nur PDF-Dateien erlaubt.");
      return;
    }
    if (file.size > 20 * 1024 * 1024) {
      setError("Datei ist zu groß (max. 20 MB).");
      return;
    }
    if (!currentUser) return;

    setUploading(true);
    try {
      const storagePath = `${itemId}/${Date.now()}.pdf`;
      const { error: uploadError } = await supabase.storage
        .from("inventory-documents")
        .upload(storagePath, file, {
          contentType: "application/pdf",
          upsert: false,
        });
      if (uploadError) throw uploadError;

      const { error: dbError } = await supabase
        .from("inventory_documents")
        .insert({
          item_id: itemId,
          file_name: file.name,
          storage_path: storagePath,
          file_size: file.size,
          uploaded_by: currentUser.id,
        });
      if (dbError) throw dbError;

      await loadDocs();
    } catch (err) {
      console.error("PDF-Upload error:", err);
      setError("Fehler beim Hochladen. Bitte erneut versuchen.");
    } finally {
      setUploading(false);
    }
  }

  function handleInputChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (file) handleFile(file);
    e.target.value = "";
  }

  async function openDoc(doc: InventoryDocument) {
    setOpening(doc.id);
    setError(null);
    const { data, error: signError } = await supabase.storage
      .from("inventory-documents")
      .createSignedUrl(doc.storage_path, 60);
    setOpening(null);
    if (signError || !data) {
      setError("PDF konnte nicht geöffnet werden.");
      return;
    }
    window.open(data.signedUrl, "_blank", "noopener,noreferrer");
  }

  async function deleteDoc(doc: InventoryDocument) {
    if (!confirm(`„${doc.file_name}" wirklich löschen?`)) return;
    await supabase.storage.from("inventory-documents").remove([doc.storage_path]);
    await supabase.from("inventory_documents").delete().eq("id", doc.id);
    setDocs((prev) => prev.filter((d) => d.id !== doc.id));
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-sm font-semibold" style={{ color: "var(--color-foreground)" }}>
          Dokumente / PDFs
        </h3>
        {isEditable && (
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            disabled={uploading}
            className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium text-white disabled:opacity-50 transition-colors"
            style={{ background: "var(--color-primary)" }}
          >
            <IconPlus size={12} />
            {uploading ? "Wird hochgeladen..." : "PDF hochladen"}
          </button>
        )}
      </div>

      {loading ? (
        <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
          Lade...
        </p>
      ) : docs.length === 0 ? (
        <p className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
          {isEditable
            ? "Noch keine Dokumente. Lade Handbücher, Datenblätter oder Rechnungen als PDF hoch."
            : "Keine Dokumente vorhanden."}
        </p>
      ) : (
        <div className="space-y-2">
          {docs.map((doc) => (
            <div
              key={doc.id}
              className="flex items-center gap-3 px-3 py-2 rounded-lg"
              style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
            >
              <span className="text-base shrink-0">📄</span>
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium truncate">{doc.file_name}</div>
                {doc.file_size && (
                  <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                    {formatSize(doc.file_size)}
                  </div>
                )}
              </div>
              <button
                type="button"
                onClick={() => openDoc(doc)}
                disabled={opening === doc.id}
                className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors shrink-0 disabled:opacity-50"
                style={{ border: "1px solid var(--color-border)", color: "var(--color-primary)" }}
                title="PDF öffnen"
              >
                <IconExternalLink size={13} />
                {opening === doc.id ? "Öffne..." : "Öffnen"}
              </button>
              {isEditable && (
                <button
                  type="button"
                  onClick={() => deleteDoc(doc)}
                  className="p-1.5 rounded-lg transition-colors shrink-0"
                  style={{ color: "var(--color-error)" }}
                  title="Löschen"
                >
                  <IconTrash size={13} />
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {error && (
        <p className="text-xs mt-2" style={{ color: "var(--color-destructive, var(--color-error))" }}>
          {error}
        </p>
      )}

      <input
        ref={inputRef}
        type="file"
        accept="application/pdf,.pdf"
        className="hidden"
        onChange={handleInputChange}
      />
    </div>
  );
}
