"use client";

import { useState, useRef } from "react";
import imageCompression from "browser-image-compression";
import { createClient } from "@/lib/supabase";
import { IconCamera, IconImage } from "@/components/ui/icons";

interface InventoryImageUploadProps {
  itemId: string;
  orgId?: string;
  currentImageUrl: string | null;
  onUploadComplete: (newUrl: string) => void;
  size?: "small" | "large";
}

type UploadState = "idle" | "compressing" | "uploading" | "done";

export function InventoryImageUpload({
  itemId,
  orgId,
  currentImageUrl,
  onUploadComplete,
  size = "large",
}: InventoryImageUploadProps) {
  const [state, setState] = useState<UploadState>("idle");
  const [preview, setPreview] = useState<string | null>(null);
  const [dragOver, setDragOver] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const isLarge = size === "large";

  async function deleteOldImage(oldUrl: string) {
    const supabase = createClient();
    const parts = oldUrl.split("/inventory-images/");
    if (parts[1]) {
      await supabase.storage
        .from("inventory-images")
        .remove([decodeURIComponent(parts[1])]);
    }
  }

  async function handleFile(file: File) {
    // Validierung
    if (!file.type.startsWith("image/")) {
      setError("Nur Bilddateien erlaubt.");
      return;
    }
    if (file.size > 20 * 1024 * 1024) {
      setError("Datei ist zu groß (max. 20 MB).");
      return;
    }

    setError(null);

    try {
      // Komprimierung
      setState("compressing");
      const compressed = await imageCompression(file, {
        maxSizeMB: 0.2,
        maxWidthOrHeight: 800,
        useWebWorker: true,
        fileType: "image/webp",
      });

      // Preview anzeigen
      const previewUrl = URL.createObjectURL(compressed);
      setPreview(previewUrl);

      // Upload
      setState("uploading");
      const supabase = createClient();
      const filePath = orgId
        ? `${orgId}/${itemId}/${Date.now()}.webp`
        : `${itemId}/${Date.now()}.webp`;

      // Alte Datei löschen
      if (currentImageUrl) {
        await deleteOldImage(currentImageUrl);
      }

      const { error: uploadError } = await supabase.storage
        .from("inventory-images")
        .upload(filePath, compressed, {
          contentType: "image/webp",
          upsert: false,
        });

      if (uploadError) throw uploadError;

      // Public URL holen
      const {
        data: { publicUrl },
      } = supabase.storage.from("inventory-images").getPublicUrl(filePath);

      // DB aktualisieren
      const { error: dbError } = await supabase
        .from("inventory_items")
        .update({ image_url: publicUrl })
        .eq("id", itemId);

      if (dbError) throw dbError;

      setState("done");
      onUploadComplete(publicUrl);

      // Nach 1s zurück zu idle
      setTimeout(() => setState("idle"), 1000);
    } catch (err) {
      console.error("Upload error:", err);
      setError("Fehler beim Hochladen. Bitte versuche es erneut.");
      setState("idle");
    }
  }

  function handleDrop(e: React.DragEvent) {
    e.preventDefault();
    setDragOver(false);
    const file = e.dataTransfer.files[0];
    if (file) handleFile(file);
  }

  function handleInputChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (file) handleFile(file);
    // Input zurücksetzen damit gleiche Datei nochmal wählbar ist
    e.target.value = "";
  }

  const displayUrl = preview || currentImageUrl;
  const isProcessing = state === "compressing" || state === "uploading";

  return (
    <div>
      <div
        onClick={() => !isProcessing && inputRef.current?.click()}
        onDragOver={(e) => {
          e.preventDefault();
          setDragOver(true);
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={handleDrop}
        className="relative rounded-xl overflow-hidden transition-all cursor-pointer"
        style={{
          width: isLarge ? "100%" : 80,
          height: isLarge ? 240 : 80,
          border: `2px dashed ${dragOver ? "var(--color-primary)" : "var(--color-border)"}`,
          background: dragOver
            ? "var(--color-primary-light)"
            : "var(--color-muted)",
        }}
      >
        {displayUrl ? (
          <img
            src={displayUrl}
            alt="Inventar-Foto"
            className="w-full h-full object-contain"
          />
        ) : (
          <div className="w-full h-full flex flex-col items-center justify-center gap-2">
            <IconImage
              size={isLarge ? 32 : 20}
              style={{ color: "var(--color-muted-foreground)" }}
            />
            {isLarge && (
              <span
                className="text-xs text-center px-4"
                style={{ color: "var(--color-muted-foreground)" }}
              >
                Klicken oder Bild hierher ziehen
              </span>
            )}
          </div>
        )}

        {/* Overlay bei Verarbeitung */}
        {isProcessing && (
          <div
            className="absolute inset-0 flex flex-col items-center justify-center gap-2"
            style={{ background: "rgba(0,0,0,0.5)" }}
          >
            <div className="w-6 h-6 border-2 border-white border-t-transparent rounded-full animate-spin" />
            <span className="text-xs text-white font-medium">
              {state === "compressing"
                ? "Wird komprimiert..."
                : "Wird hochgeladen..."}
            </span>
          </div>
        )}

        {/* Done Overlay */}
        {state === "done" && (
          <div
            className="absolute inset-0 flex items-center justify-center"
            style={{ background: "rgba(16,185,129,0.3)" }}
          >
            <span className="text-white text-2xl">✓</span>
          </div>
        )}

        {/* Hover-Overlay (nur wenn Bild vorhanden) */}
        {displayUrl && !isProcessing && state !== "done" && (
          <div
            className="absolute inset-0 flex items-center justify-center opacity-0 hover:opacity-100 transition-opacity"
            style={{ background: "rgba(0,0,0,0.4)" }}
          >
            <IconCamera size={isLarge ? 28 : 18} className="text-white" />
          </div>
        )}
      </div>

      {error && (
        <p
          className="text-xs mt-1.5"
          style={{ color: "var(--color-destructive)" }}
        >
          {error}
        </p>
      )}

      <input
        ref={inputRef}
        type="file"
        accept="image/*"
        className="hidden"
        onChange={handleInputChange}
      />
    </div>
  );
}
