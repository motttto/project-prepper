"use client";

import { useState, useCallback, useEffect, useRef } from "react";
import imageCompression from "browser-image-compression";
import { createClient } from "@/lib/supabase";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useWorkspace } from "@/contexts/org-context";
import type { ProjectFile } from "@/types/database";
import {
  IconUpload,
  IconTrash,
  IconFilePdf,
  IconImage,
  IconDownload,
  IconZoomIn,
  IconX,
} from "@/components/ui/icons";

interface TabFilesProps {
  projectId: string;
}

type UploadState = "idle" | "compressing" | "uploading";

const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
const ACCEPTED_TYPES = ["image/jpeg", "image/png", "image/webp", "image/gif", "application/pdf"];

function formatFileSize(bytes: number | null): string {
  if (!bytes) return "";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function isImage(fileType: string): boolean {
  return fileType.startsWith("image/");
}

export function TabFiles({ projectId }: TabFilesProps) {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const { groupId } = useWorkspace();
  const orgId = groupId ?? currentUser?.id ?? null; // Solo: User-ID als Storage-Bucket-Pfad

  const [files, setFiles] = useState<ProjectFile[]>([]);
  const [loading, setLoading] = useState(true);
  const [uploadState, setUploadState] = useState<UploadState>("idle");
  const [dragOver, setDragOver] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [lightboxUrl, setLightboxUrl] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<string | null>(null);

  const inputRef = useRef<HTMLInputElement>(null);

  // Dateien laden
  const loadFiles = useCallback(async () => {
    const { data } = await supabase
      .from("project_files")
      .select("*, profiles:uploaded_by(name)")
      .eq("project_id", projectId)
      .order("created_at", { ascending: false });

    if (data) setFiles(data as ProjectFile[]);
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    loadFiles();
  }, [loadFiles]);

  // Realtime
  useRealtimeTable({
    table: "project_files",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadFiles,
  });

  // Upload-Logik
  async function handleFiles(fileList: FileList | File[]) {
    const filesToUpload = Array.from(fileList);

    for (const file of filesToUpload) {
      if (!ACCEPTED_TYPES.includes(file.type)) {
        setError(`"${file.name}" — nur Bilder und PDFs erlaubt.`);
        continue;
      }
      if (file.size > MAX_FILE_SIZE) {
        setError(`"${file.name}" ist zu groß (max. 10 MB).`);
        continue;
      }

      setError(null);

      try {
        let uploadFile: File | Blob = file;
        let contentType = file.type;
        let extension = file.name.split(".").pop() || "bin";

        // Bilder komprimieren
        if (isImage(file.type)) {
          setUploadState("compressing");
          uploadFile = await imageCompression(file, {
            maxSizeMB: 0.5,
            maxWidthOrHeight: 1200,
            useWebWorker: true,
            fileType: "image/webp",
          });
          contentType = "image/webp";
          extension = "webp";
        }

        // Upload
        setUploadState("uploading");
        const timestamp = Date.now();
        const safeName = file.name.replace(/[^a-zA-Z0-9._-]/g, "_");
        const filePath = `${orgId}/${projectId}/${timestamp}_${safeName}`;

        const { error: uploadError } = await supabase.storage
          .from("project-files")
          .upload(filePath, uploadFile, {
            contentType,
            upsert: false,
          });

        if (uploadError) throw uploadError;

        // Public URL
        const {
          data: { publicUrl },
        } = supabase.storage.from("project-files").getPublicUrl(filePath);

        // DB-Eintrag
        const { error: dbError } = await supabase.from("project_files").insert({
          project_id: projectId,
          file_name: file.name,
          file_path: filePath,
          file_url: publicUrl,
          file_type: contentType,
          file_size: uploadFile instanceof Blob ? uploadFile.size : (uploadFile as File).size,
          uploaded_by: currentUser?.id || null,
        });

        if (dbError) throw dbError;

        loadFiles();
      } catch (err) {
        console.error("Upload error:", err);
        setError(`Fehler beim Hochladen von "${file.name}".`);
      }
    }

    setUploadState("idle");
  }

  function handleDrop(e: React.DragEvent) {
    e.preventDefault();
    setDragOver(false);
    if (e.dataTransfer.files.length > 0) {
      handleFiles(e.dataTransfer.files);
    }
  }

  function handleInputChange(e: React.ChangeEvent<HTMLInputElement>) {
    if (e.target.files && e.target.files.length > 0) {
      handleFiles(e.target.files);
    }
    e.target.value = "";
  }

  async function handleDelete(file: ProjectFile) {
    setDeleting(file.id);
    try {
      // Storage löschen
      await supabase.storage.from("project-files").remove([file.file_path]);
      // DB-Eintrag löschen
      await supabase.from("project_files").delete().eq("id", file.id);
      loadFiles();
    } catch (err) {
      console.error("Delete error:", err);
      setError("Fehler beim Löschen.");
    }
    setDeleting(null);
  }

  const isProcessing = uploadState !== "idle";

  if (loading) {
    return (
      <div className="py-8 text-center" style={{ color: "var(--color-muted-foreground)" }}>
        wird geladen...
      </div>
    );
  }

  return (
    <div className="mt-6 space-y-6">
      {/* Upload-Bereich */}
      <div
        onClick={() => !isProcessing && inputRef.current?.click()}
        onDragOver={(e) => {
          e.preventDefault();
          setDragOver(true);
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={handleDrop}
        className="relative rounded-xl p-8 flex flex-col items-center justify-center gap-3 cursor-pointer transition-all"
        style={{
          border: `2px dashed ${dragOver ? "var(--color-primary)" : "var(--color-border)"}`,
          background: dragOver ? "var(--color-primary-light)" : "var(--color-muted)",
          minHeight: 140,
        }}
      >
        {isProcessing ? (
          <>
            <div className="w-6 h-6 border-2 border-t-transparent rounded-full animate-spin" style={{ borderColor: "var(--color-primary)", borderTopColor: "transparent" }} />
            <span className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              {uploadState === "compressing" ? "Wird komprimiert..." : "Wird hochgeladen..."}
            </span>
          </>
        ) : (
          <>
            <IconUpload size={28} style={{ color: "var(--color-muted-foreground)" }} />
            <span className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
              Dateien hierher ziehen oder klicken
            </span>
            <span className="text-xs" style={{ color: "var(--color-muted-foreground)", opacity: 0.7 }}>
              Bilder & PDFs, max. 10 MB
            </span>
          </>
        )}
      </div>

      <input
        ref={inputRef}
        type="file"
        accept="image/*,application/pdf"
        multiple
        className="hidden"
        onChange={handleInputChange}
      />

      {error && (
        <p className="text-sm" style={{ color: "var(--color-destructive)" }}>
          {error}
        </p>
      )}

      {/* Datei-Grid */}
      {files.length === 0 ? (
        <div
          className="text-center py-8 rounded-xl"
          style={{
            border: "2px dashed var(--color-border)",
            color: "var(--color-muted-foreground)",
          }}
        >
          Noch keine Dateien hochgeladen
        </div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
          {files.map((file) => (
            <div
              key={file.id}
              className="group relative rounded-xl overflow-hidden"
              style={{
                border: "1px solid var(--color-border)",
                background: "var(--color-surface)",
              }}
            >
              {/* Preview */}
              {isImage(file.file_type) ? (
                <div
                  className="aspect-square cursor-pointer"
                  onClick={() => setLightboxUrl(file.file_url)}
                >
                  <img
                    src={file.file_url}
                    alt={file.file_name}
                    className="w-full h-full object-cover"
                  />
                  {/* Hover-Overlay */}
                  <div
                    className="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                    style={{ background: "rgba(0,0,0,0.3)" }}
                  >
                    <IconZoomIn size={24} className="text-white" />
                  </div>
                </div>
              ) : (
                <a
                  href={file.file_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="aspect-square flex flex-col items-center justify-center gap-2 transition-colors"
                  style={{ background: "var(--color-muted)" }}
                >
                  <IconFilePdf size={36} style={{ color: "var(--color-destructive)" }} />
                  <span
                    className="text-xs px-2 text-center truncate max-w-full"
                    style={{ color: "var(--color-muted-foreground)" }}
                  >
                    PDF
                  </span>
                </a>
              )}

              {/* Info-Bar */}
              <div className="p-2">
                <p
                  className="text-xs font-medium truncate"
                  title={file.file_name}
                >
                  {file.file_name}
                </p>
                <div className="flex items-center justify-between mt-1">
                  <span
                    className="text-xs"
                    style={{ color: "var(--color-muted-foreground)" }}
                  >
                    {formatFileSize(file.file_size)}
                    {file.profiles?.name && ` · ${file.profiles.name}`}
                  </span>
                  <div className="flex items-center gap-1">
                    <a
                      href={file.file_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="p-1 rounded hover:opacity-70 transition-opacity"
                      title="Herunterladen"
                    >
                      <IconDownload size={14} style={{ color: "var(--color-muted-foreground)" }} />
                    </a>
                    <button
                      onClick={() => handleDelete(file)}
                      disabled={deleting === file.id}
                      className="p-1 rounded hover:opacity-70 transition-opacity disabled:opacity-30"
                      title="Löschen"
                    >
                      <IconTrash size={14} style={{ color: "var(--color-destructive)" }} />
                    </button>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Lightbox */}
      {lightboxUrl && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: "rgba(0,0,0,0.8)" }}
          onClick={() => setLightboxUrl(null)}
        >
          <button
            onClick={() => setLightboxUrl(null)}
            className="absolute top-4 right-4 p-2 rounded-full transition-opacity hover:opacity-70"
            style={{ background: "rgba(255,255,255,0.2)" }}
          >
            <IconX size={24} className="text-white" />
          </button>
          <img
            src={lightboxUrl}
            alt="Vollbild"
            className="max-w-full max-h-full object-contain rounded-lg"
            onClick={(e) => e.stopPropagation()}
          />
        </div>
      )}
    </div>
  );
}
