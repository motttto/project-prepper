"use client";

import { useState, useEffect, useCallback, useRef, useMemo } from "react";
import { useParams, useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase";
import type { Project, Booking, InventoryItem, CostItem } from "@/types/database";

const statusLabels: Record<Project["status"], string> = {
  draft: "Entwurf",
  planning: "Planung",
  active: "Aktiv",
  completed: "Abgeschlossen",
  cancelled: "Abgebrochen",
};

const categoryLabels: Record<CostItem["category"], string> = {
  personnel: "Personal",
  material: "Material",
  inventory: "Inventar",
  external: "Extern",
  other: "Sonstiges",
};

type BookingWithItem = Booking & { inventory_items: InventoryItem };

export default function ProjectDetailPage() {
  const params = useParams();
  const router = useRouter();
  const supabase = createClient();
  const projectId = params.id as string;

  const [project, setProject] = useState<Project | null>(null);
  const [bookings, setBookings] = useState<BookingWithItem[]>([]);
  const [costs, setCosts] = useState<CostItem[]>([]);
  const [inventoryItems, setInventoryItems] = useState<InventoryItem[]>([]);
  const [loading, setLoading] = useState(true);

  // Buchungs-Formular
  const [showBooking, setShowBooking] = useState(false);
  const [bookItemId, setBookItemId] = useState("");
  const [bookQuantity, setBookQuantity] = useState(1);
  const [bookFrom, setBookFrom] = useState("");
  const [bookTo, setBookTo] = useState("");
  const [bookNotes, setBookNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [bookingError, setBookingError] = useState<string | null>(null);

  // Live-Suche für Inventar
  const [itemSearch, setItemSearch] = useState("");
  const [showItemDropdown, setShowItemDropdown] = useState(false);
  const [selectedItemName, setSelectedItemName] = useState("");
  const searchRef = useRef<HTMLDivElement>(null);

  const filteredInventory = useMemo(() => {
    if (!itemSearch.trim()) return inventoryItems;
    const q = itemSearch.toLowerCase();
    return inventoryItems.filter(
      (i) =>
        i.inventory_number?.toLowerCase().includes(q) ||
        i.name.toLowerCase().includes(q) ||
        i.category.toLowerCase().includes(q) ||
        i.description?.toLowerCase().includes(q)
    );
  }, [inventoryItems, itemSearch]);

  // Dropdown schließen bei Klick außerhalb
  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (searchRef.current && !searchRef.current.contains(e.target as Node)) {
        setShowItemDropdown(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const loadData = useCallback(async () => {
    const [projectRes, bookingsRes, costsRes, inventoryRes] = await Promise.all(
      [
        supabase.from("projects").select("*").eq("id", projectId).single(),
        supabase
          .from("bookings")
          .select("*, inventory_items(*)")
          .eq("project_id", projectId)
          .order("date_from"),
        supabase
          .from("cost_items")
          .select("*")
          .eq("project_id", projectId)
          .order("created_at"),
        supabase.from("inventory_items").select("*").order("name"),
      ]
    );

    if (projectRes.data) setProject(projectRes.data as Project);
    if (bookingsRes.data) setBookings(bookingsRes.data as BookingWithItem[]);
    if (costsRes.data) setCosts(costsRes.data as CostItem[]);
    if (inventoryRes.data)
      setInventoryItems(inventoryRes.data as InventoryItem[]);
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Verfügbarkeit prüfen
  async function checkAvailability(
    itemId: string,
    dateFrom: string,
    dateTo: string,
    quantity: number
  ): Promise<{ available: boolean; maxAvailable: number }> {
    const item = inventoryItems.find((i) => i.id === itemId);
    if (!item) return { available: false, maxAvailable: 0 };

    // Alle überlappenden Buchungen für diesen Artikel finden
    const { data: overlapping } = await supabase
      .from("bookings")
      .select("quantity")
      .eq("inventory_item_id", itemId)
      .neq("status", "returned")
      .lte("date_from", dateTo)
      .gte("date_to", dateFrom);

    const booked = (overlapping || []).reduce(
      (sum, b) => sum + b.quantity,
      0
    );
    const maxAvailable = item.quantity - booked;

    return {
      available: quantity <= maxAvailable,
      maxAvailable,
    };
  }

  async function handleBooking(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setBookingError(null);

    // Verfügbarkeit prüfen
    const { available, maxAvailable } = await checkAvailability(
      bookItemId,
      bookFrom,
      bookTo,
      bookQuantity
    );

    if (!available) {
      setBookingError(
        maxAvailable > 0
          ? `Nur ${maxAvailable} Stück verfügbar in diesem Zeitraum.`
          : "Artikel ist in diesem Zeitraum komplett ausgebucht."
      );
      setSaving(false);
      return;
    }

    const { error } = await supabase.from("bookings").insert({
      project_id: projectId,
      inventory_item_id: bookItemId,
      quantity: bookQuantity,
      date_from: bookFrom,
      date_to: bookTo,
      notes: bookNotes || null,
    });

    if (!error) {
      // Automatisch Kostenposition für Inventar-Buchung erstellen
      const item = inventoryItems.find((i) => i.id === bookItemId);
      if (item) {
        const days =
          Math.ceil(
            (new Date(bookTo).getTime() - new Date(bookFrom).getTime()) /
              (1000 * 60 * 60 * 24)
          ) + 1;
        const totalCost = Number(item.cost_per_day) * bookQuantity * days;

        await supabase.from("cost_items").insert({
          project_id: projectId,
          category: "inventory",
          description: `${item.name} (${bookQuantity}x, ${days} Tage)`,
          amount_planned: totalCost,
          amount_actual: totalCost,
        });
      }

      setBookItemId("");
      setBookQuantity(1);
      setBookFrom("");
      setBookTo("");
      setBookNotes("");
      setItemSearch("");
      setSelectedItemName("");
      setShowBooking(false);
      loadData();
    }
    setSaving(false);
  }

  async function handleReturnBooking(bookingId: string) {
    await supabase
      .from("bookings")
      .update({ status: "returned" })
      .eq("id", bookingId);
    loadData();
  }

  async function handleDeleteBooking(bookingId: string) {
    if (!confirm("Buchung wirklich löschen?")) return;
    await supabase.from("bookings").delete().eq("id", bookingId);
    loadData();
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64 text-[var(--color-muted-foreground)]">
        Projekt wird geladen...
      </div>
    );
  }

  if (!project) {
    return (
      <div className="text-center py-12">
        <p className="text-lg mb-4">Projekt nicht gefunden</p>
        <button
          onClick={() => router.push("/projects")}
          className="text-[var(--color-primary)] hover:underline"
        >
          Zurück zur Übersicht
        </button>
      </div>
    );
  }

  const totalCostPlanned = costs.reduce(
    (sum, c) => sum + Number(c.amount_planned),
    0
  );
  const totalCostActual = costs.reduce(
    (sum, c) => sum + (c.amount_actual !== null ? Number(c.amount_actual) : 0),
    0
  );

  const bookingStatusLabels: Record<string, string> = {
    reserved: "Reserviert",
    checked_out: "Ausgecheckt",
    returned: "Zurückgegeben",
  };

  const bookingStatusColors: Record<string, string> = {
    reserved: "bg-blue-100 text-blue-700",
    checked_out: "bg-yellow-100 text-yellow-700",
    returned: "bg-gray-100 text-gray-500",
  };

  return (
    <div>
      {/* Header */}
      <div className="flex items-center gap-3 mb-2">
        <button
          onClick={() => router.push("/projects")}
          className="text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)]"
        >
          &larr; Projekte
        </button>
      </div>

      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-2xl font-bold">{project.name}</h1>
          {project.description && (
            <p className="text-[var(--color-muted-foreground)] mt-1">
              {project.description}
            </p>
          )}
          <div className="flex items-center gap-3 mt-2 text-sm text-[var(--color-muted-foreground)]">
            <span className="px-2 py-0.5 rounded-full bg-[var(--color-muted)] text-xs">
              {statusLabels[project.status]}
            </span>
            {project.date_start && (
              <span>
                {new Date(project.date_start).toLocaleDateString("de-DE")}
                {project.date_end &&
                  ` – ${new Date(project.date_end).toLocaleDateString("de-DE")}`}
              </span>
            )}
          </div>
        </div>
      </div>

      {/* Übersichtskarten */}
      <div className="grid grid-cols-3 gap-4 mb-8">
        <div className="p-4 border border-[var(--color-border)] rounded-lg">
          <div className="text-sm text-[var(--color-muted-foreground)]">
            Gebuchtes Equipment
          </div>
          <div className="text-2xl font-bold mt-1">
            {bookings.filter((b) => b.status !== "returned").length}
          </div>
        </div>
        <div className="p-4 border border-[var(--color-border)] rounded-lg">
          <div className="text-sm text-[var(--color-muted-foreground)]">
            Kosten (Soll)
          </div>
          <div className="text-2xl font-bold mt-1">
            {totalCostPlanned.toLocaleString("de-DE")} &euro;
          </div>
        </div>
        <div className="p-4 border border-[var(--color-border)] rounded-lg">
          <div className="text-sm text-[var(--color-muted-foreground)]">
            Kosten (Ist)
          </div>
          <div className="text-2xl font-bold mt-1">
            {totalCostActual.toLocaleString("de-DE")} &euro;
          </div>
        </div>
      </div>

      {/* ====== INVENTAR-BUCHUNGEN ====== */}
      <div className="mb-10">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">Gebuchtes Inventar</h2>
          <button
            onClick={() => setShowBooking(!showBooking)}
            className="px-3 py-1.5 text-sm bg-[var(--color-primary)] text-white rounded-lg hover:bg-[var(--color-primary-hover)] transition-colors"
          >
            + Equipment buchen
          </button>
        </div>

        {showBooking && (
          <div className="mb-4 p-5 border border-[var(--color-border)] rounded-lg">
            <h3 className="font-medium mb-3">Equipment reservieren</h3>
            {bookingError && (
              <div className="mb-3 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
                {bookingError}
              </div>
            )}
            <form onSubmit={handleBooking} className="space-y-3">
              <div className="grid grid-cols-2 gap-4">
                <div ref={searchRef} className="relative">
                  <label className="block text-sm font-medium mb-1">
                    Artikel
                  </label>
                  <input
                    type="text"
                    value={selectedItemName || itemSearch}
                    onChange={(e) => {
                      setItemSearch(e.target.value);
                      setSelectedItemName("");
                      setBookItemId("");
                      setShowItemDropdown(true);
                    }}
                    onFocus={() => setShowItemDropdown(true)}
                    className="w-full px-3 py-2 border border-[var(--color-border)] rounded-lg bg-[var(--color-background)]"
                    placeholder="Suche nach Name, Inv.-Nr., Kategorie..."
                    required={!bookItemId}
                  />
                  {/* Hidden input for form validation */}
                  <input type="hidden" value={bookItemId} required />
                  {selectedItemName && (
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedItemName("");
                        setBookItemId("");
                        setItemSearch("");
                        setShowItemDropdown(true);
                      }}
                      className="absolute right-2 top-[34px] text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)]"
                    >
                      ✕
                    </button>
                  )}
                  {showItemDropdown && !selectedItemName && (
                    <div
                      className="absolute z-50 w-full mt-1 max-h-64 overflow-y-auto rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)]"
                      style={{ boxShadow: "var(--shadow-lg, 0 10px 15px -3px rgba(0,0,0,0.1))" }}
                    >
                      {filteredInventory.length === 0 ? (
                        <div className="px-3 py-4 text-sm text-center text-[var(--color-muted-foreground)]">
                          Kein Artikel gefunden
                        </div>
                      ) : (
                        filteredInventory.map((item) => (
                          <button
                            key={item.id}
                            type="button"
                            onClick={() => {
                              setBookItemId(item.id);
                              setSelectedItemName(`${item.inventory_number} — ${item.name}`);
                              setItemSearch("");
                              setShowItemDropdown(false);
                            }}
                            className="w-full text-left px-3 py-2.5 hover:bg-[var(--color-surface-hover)] transition-colors border-b border-[var(--color-border-light)] last:border-0"
                          >
                            <div className="flex items-center justify-between">
                              <div>
                                <span className="font-mono text-xs px-1.5 py-0.5 rounded mr-2"
                                  style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                                  {item.inventory_number}
                                </span>
                                <span className="text-sm font-medium">{item.name}</span>
                              </div>
                              <span className="text-xs text-[var(--color-muted-foreground)]">
                                {item.quantity}x · {Number(item.cost_per_day).toFixed(2)} €/Tag
                              </span>
                            </div>
                            <div className="text-xs text-[var(--color-muted-foreground)] mt-0.5 ml-1">
                              {item.category}{item.location ? ` · ${item.location}` : ""}
                            </div>
                          </button>
                        ))
                      )}
                    </div>
                  )}
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">
                    Menge
                  </label>
                  <input
                    type="number"
                    value={bookQuantity}
                    onChange={(e) => setBookQuantity(Number(e.target.value))}
                    min={1}
                    className="w-full px-3 py-2 border border-[var(--color-border)] rounded-lg bg-[var(--color-background)]"
                    required
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Von</label>
                  <input
                    type="date"
                    value={bookFrom}
                    onChange={(e) => setBookFrom(e.target.value)}
                    className="w-full px-3 py-2 border border-[var(--color-border)] rounded-lg bg-[var(--color-background)]"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Bis</label>
                  <input
                    type="date"
                    value={bookTo}
                    onChange={(e) => setBookTo(e.target.value)}
                    className="w-full px-3 py-2 border border-[var(--color-border)] rounded-lg bg-[var(--color-background)]"
                    required
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">
                  Notizen (optional)
                </label>
                <input
                  type="text"
                  value={bookNotes}
                  onChange={(e) => setBookNotes(e.target.value)}
                  className="w-full px-3 py-2 border border-[var(--color-border)] rounded-lg bg-[var(--color-background)]"
                  placeholder="z.B. Wird für Hauptbühne benötigt"
                />
              </div>
              <div className="flex gap-2">
                <button
                  type="submit"
                  disabled={saving}
                  className="px-4 py-2 bg-[var(--color-primary)] text-white rounded-lg hover:bg-[var(--color-primary-hover)] disabled:opacity-50"
                >
                  {saving ? "Wird gebucht..." : "Reservieren"}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setShowBooking(false);
                    setBookingError(null);
                  }}
                  className="px-4 py-2 border border-[var(--color-border)] rounded-lg hover:bg-[var(--color-muted)]"
                >
                  Abbrechen
                </button>
              </div>
            </form>
          </div>
        )}

        {bookings.length === 0 ? (
          <div className="text-center py-8 border border-dashed border-[var(--color-border)] rounded-lg text-[var(--color-muted-foreground)]">
            Noch kein Equipment gebucht
          </div>
        ) : (
          <div className="space-y-2">
            {bookings.map((booking) => (
              <div
                key={booking.id}
                className="flex items-center justify-between p-3 border border-[var(--color-border)] rounded-lg"
              >
                <div className="flex items-center gap-4">
                  <div>
                    <div className="font-medium flex items-center gap-2">
                      <span className="font-mono text-xs px-1.5 py-0.5 rounded"
                        style={{ background: "var(--color-muted)", color: "var(--color-muted-foreground)" }}>
                        {booking.inventory_items?.inventory_number}
                      </span>
                      {booking.inventory_items?.name}
                    </div>
                    <div className="text-xs text-[var(--color-muted-foreground)]">
                      {booking.quantity}x &middot;{" "}
                      {new Date(booking.date_from).toLocaleDateString("de-DE")}{" "}
                      – {new Date(booking.date_to).toLocaleDateString("de-DE")}
                      {booking.notes && ` · ${booking.notes}`}
                    </div>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <span
                    className={`text-xs px-2 py-1 rounded-full ${bookingStatusColors[booking.status]}`}
                  >
                    {bookingStatusLabels[booking.status]}
                  </span>
                  {booking.status !== "returned" && (
                    <button
                      onClick={() => handleReturnBooking(booking.id)}
                      className="text-xs text-[var(--color-muted-foreground)] hover:text-[var(--color-foreground)] px-2"
                    >
                      Zurückgeben
                    </button>
                  )}
                  <button
                    onClick={() => handleDeleteBooking(booking.id)}
                    className="text-xs text-[var(--color-destructive)] hover:underline"
                  >
                    Löschen
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* ====== KOSTEN ====== */}
      <div>
        <h2 className="text-lg font-semibold mb-4">Kostenpositionen</h2>
        {costs.length === 0 ? (
          <div className="text-center py-8 border border-dashed border-[var(--color-border)] rounded-lg text-[var(--color-muted-foreground)]">
            Noch keine Kostenpositionen
          </div>
        ) : (
          <div className="border border-[var(--color-border)] rounded-lg overflow-hidden">
            <table className="w-full">
              <thead className="bg-[var(--color-muted)]">
                <tr>
                  <th className="text-left p-3 text-sm font-medium">
                    Kategorie
                  </th>
                  <th className="text-left p-3 text-sm font-medium">
                    Beschreibung
                  </th>
                  <th className="text-right p-3 text-sm font-medium">Soll</th>
                  <th className="text-right p-3 text-sm font-medium">Ist</th>
                </tr>
              </thead>
              <tbody>
                {costs.map((cost) => (
                  <tr
                    key={cost.id}
                    className="border-t border-[var(--color-border)]"
                  >
                    <td className="p-3 text-sm">
                      <span className="px-2 py-1 bg-[var(--color-muted)] rounded text-xs">
                        {categoryLabels[cost.category]}
                      </span>
                    </td>
                    <td className="p-3 text-sm">{cost.description}</td>
                    <td className="p-3 text-sm text-right">
                      {Number(cost.amount_planned).toLocaleString("de-DE")}{" "}
                      &euro;
                    </td>
                    <td className="p-3 text-sm text-right">
                      {cost.amount_actual !== null
                        ? `${Number(cost.amount_actual).toLocaleString("de-DE")} €`
                        : "–"}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="bg-[var(--color-muted)] font-semibold">
                <tr>
                  <td className="p-3 text-sm" colSpan={2}>
                    Gesamt
                  </td>
                  <td className="p-3 text-sm text-right">
                    {totalCostPlanned.toLocaleString("de-DE")} &euro;
                  </td>
                  <td className="p-3 text-sm text-right">
                    {totalCostActual.toLocaleString("de-DE")} &euro;
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
