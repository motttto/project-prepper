"use client";

import { useState, useEffect, useCallback, useRef, useMemo } from "react";
import { createClient } from "@/lib/supabase";
import type { Project, Booking, InventoryItem } from "@/types/database";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import { DateInput } from "@/components/ui/date-input";

type BookingWithItem = Booking & { inventory_items: InventoryItem };

interface TabEquipmentProps {
  projectId: string;
  project: Project;
  currentOrgId: string | null;
}

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

const approvalLabels: Record<string, string> = {
  pending: "Wartet auf Genehmigung",
  approved: "Genehmigt",
  rejected: "Abgelehnt",
};

const approvalColors: Record<string, { bg: string; text: string }> = {
  pending: { bg: "#fef3c7", text: "#b45309" },
  approved: { bg: "#dcfce7", text: "#16a34a" },
  rejected: { bg: "#fee2e2", text: "#dc2626" },
};

type OrgInfo = { org_id: string; name: string; slug: string };

export function TabEquipment({ projectId, project, currentOrgId }: TabEquipmentProps) {
  const supabase = createClient();
  const [bookings, setBookings] = useState<BookingWithItem[]>([]);
  const [inventoryItems, setInventoryItems] = useState<InventoryItem[]>([]);
  const [partnerOrgs, setPartnerOrgs] = useState<OrgInfo[]>([]);
  const [loading, setLoading] = useState(true);

  const [showBooking, setShowBooking] = useState(false);
  const [bookItemId, setBookItemId] = useState("");
  const [bookQuantity, setBookQuantity] = useState(1);
  const [bookFrom, setBookFrom] = useState(project.date_start || "");
  const [bookTo, setBookTo] = useState(project.date_end || "");
  const [bookNotes, setBookNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [bookingError, setBookingError] = useState<string | null>(null);
  const [bookingSuccess, setBookingSuccess] = useState<string | null>(null);

  // Live-Suche
  const [itemSearch, setItemSearch] = useState("");
  const [showItemDropdown, setShowItemDropdown] = useState(false);
  const [selectedItemName, setSelectedItemName] = useState("");
  const searchRef = useRef<HTMLDivElement>(null);

  // Items gruppiert nach Org
  const groupedInventory = useMemo(() => {
    const filtered = itemSearch.trim()
      ? inventoryItems.filter((i) => {
          const q = itemSearch.toLowerCase();
          return (
            i.inventory_number?.toLowerCase().includes(q) ||
            i.name.toLowerCase().includes(q) ||
            i.category.toLowerCase().includes(q) ||
            i.description?.toLowerCase().includes(q)
          );
        })
      : inventoryItems;

    const groups: { org: OrgInfo; items: InventoryItem[] }[] = [];

    // Eigene Org zuerst
    const myItems = filtered.filter((i) => i.org_id === currentOrgId);
    if (myItems.length > 0) {
      const myOrg = partnerOrgs.find((o) => o.org_id === currentOrgId);
      groups.push({
        org: myOrg || { org_id: currentOrgId || "", name: "Meine Organisation", slug: "" },
        items: myItems,
      });
    }

    // Partner-Orgs
    partnerOrgs
      .filter((o) => o.org_id !== currentOrgId)
      .forEach((org) => {
        const orgItems = filtered.filter((i) => i.org_id === org.org_id);
        if (orgItems.length > 0) {
          groups.push({ org, items: orgItems });
        }
      });

    return groups;
  }, [inventoryItems, itemSearch, partnerOrgs, currentOrgId]);

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
    // 1. Lade akzeptierte Partner-Orgs
    const { data: projectOrgsData } = await supabase
      .from("project_orgs")
      .select("org_id, organizations(name, slug)")
      .eq("project_id", projectId)
      .eq("status", "accepted");

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const orgs: OrgInfo[] = (projectOrgsData || []).map((po: any) => ({
      org_id: po.org_id,
      name: po.organizations?.name || "Unbekannt",
      slug: po.organizations?.slug || "",
    }));
    setPartnerOrgs(orgs);

    const orgIds = orgs.map((o) => o.org_id);

    // 2. Inventar von allen Partner-Orgs laden
    const [bookingsRes, inventoryRes] = await Promise.all([
      supabase
        .from("bookings")
        .select("*, inventory_items(*)")
        .eq("project_id", projectId)
        .order("date_from"),
      orgIds.length > 0
        ? supabase.from("inventory_items").select("*").in("org_id", orgIds).order("name")
        : Promise.resolve({ data: [] }),
    ]);

    if (bookingsRes.data) setBookings(bookingsRes.data as BookingWithItem[]);
    if (inventoryRes.data) setInventoryItems(inventoryRes.data as InventoryItem[]);
    setLoading(false);
  }, [supabase, projectId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Realtime
  useRealtimeTable({
    table: "bookings",
    filter: { column: "project_id", value: projectId },
    onDataChange: loadData,
  });

  async function checkAvailability(
    itemId: string,
    dateFrom: string,
    dateTo: string,
    quantity: number
  ): Promise<{ available: boolean; maxAvailable: number }> {
    const item = inventoryItems.find((i) => i.id === itemId);
    if (!item) return { available: false, maxAvailable: 0 };
    const { data: overlapping } = await supabase
      .from("bookings")
      .select("quantity")
      .eq("inventory_item_id", itemId)
      .neq("status", "returned")
      .neq("approval_status", "rejected")
      .lte("date_from", dateTo)
      .gte("date_to", dateFrom);
    const booked = (overlapping || []).reduce((sum, b) => sum + b.quantity, 0);
    const maxAvailable = item.quantity - booked;
    return { available: quantity <= maxAvailable, maxAvailable };
  }

  async function handleBooking(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setBookingError(null);
    setBookingSuccess(null);

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

    const item = inventoryItems.find((i) => i.id === bookItemId);
    if (!item) {
      setSaving(false);
      return;
    }

    const isOwnEquipment = item.org_id === currentOrgId;

    // Auth User holen
    const {
      data: { user },
    } = await supabase.auth.getUser();

    const { error } = await supabase.from("bookings").insert({
      project_id: projectId,
      inventory_item_id: bookItemId,
      quantity: bookQuantity,
      date_from: bookFrom,
      date_to: bookTo,
      notes: bookNotes || null,
      requested_by: user?.id || null,
      source_org_id: item.org_id,
      approval_status: isOwnEquipment ? "approved" : "pending",
    });

    if (!error) {
      // Cost-Item nur bei eigenem Equipment sofort erstellen
      if (isOwnEquipment) {
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
      setBookFrom(project.date_start || "");
      setBookTo(project.date_end || "");
      setBookNotes("");
      setItemSearch("");
      setSelectedItemName("");
      setShowBooking(false);

      if (!isOwnEquipment) {
        const orgName = partnerOrgs.find((o) => o.org_id === item.org_id)?.name || "Partner";
        setBookingSuccess(`Buchungsanfrage an ${orgName} gesendet.`);
        setTimeout(() => setBookingSuccess(null), 5000);
      }

      loadData();
    }
    setSaving(false);
  }

  async function handleApproveBooking(bookingId: string) {
    const {
      data: { user },
    } = await supabase.auth.getUser();

    await supabase
      .from("bookings")
      .update({
        approval_status: "approved",
        approved_by: user?.id,
        approved_at: new Date().toISOString(),
      })
      .eq("id", bookingId);

    // Cost-Item erstellen
    const booking = bookings.find((b) => b.id === bookingId);
    if (booking?.inventory_items) {
      const days =
        Math.ceil(
          (new Date(booking.date_to).getTime() - new Date(booking.date_from).getTime()) /
            (1000 * 60 * 60 * 24)
        ) + 1;
      const totalCost =
        Number(booking.inventory_items.cost_per_day) * booking.quantity * days;
      await supabase.from("cost_items").insert({
        project_id: projectId,
        category: "inventory",
        description: `${booking.inventory_items.name} (${booking.quantity}x, ${days} Tage) [extern]`,
        amount_planned: totalCost,
        amount_actual: totalCost,
      });
    }
    loadData();
  }

  async function handleRejectBooking(bookingId: string) {
    const reason = prompt("Ablehnungsgrund (optional):");
    await supabase
      .from("bookings")
      .update({
        approval_status: "rejected",
        rejection_reason: reason || null,
      })
      .eq("id", bookingId);
    loadData();
  }

  async function handleReturnBooking(bookingId: string) {
    await supabase.from("bookings").update({ status: "returned" }).eq("id", bookingId);
    loadData();
  }

  async function handleDeleteBooking(bookingId: string) {
    if (!confirm("Buchung wirklich löschen?")) return;
    await supabase.from("bookings").delete().eq("id", bookingId);
    loadData();
  }

  // Org-Name zu einer Org-ID
  function getOrgName(orgId: string | null): string {
    if (!orgId) return "";
    const org = partnerOrgs.find((o) => o.org_id === orgId);
    return org?.name || "";
  }

  if (loading) {
    return (
      <div
        className="py-8 text-center"
        style={{ color: "var(--color-muted-foreground)" }}
      >
        Equipment wird geladen...
      </div>
    );
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-lg font-semibold">Gebuchtes Inventar</h2>
        <button
          onClick={() => setShowBooking(!showBooking)}
          className="px-3 py-1.5 text-sm text-white rounded-lg transition-colors"
          style={{ background: "var(--color-primary)" }}
        >
          + Equipment buchen
        </button>
      </div>

      {/* Erfolgs-Meldung */}
      {bookingSuccess && (
        <div
          className="mb-4 p-3 rounded-lg text-sm"
          style={{ background: "#dcfce7", color: "#16a34a", border: "1px solid #bbf7d0" }}
        >
          {bookingSuccess}
        </div>
      )}

      {showBooking && (
        <div
          className="mb-4 p-5 rounded-lg"
          style={{
            border: "1px solid var(--color-border)",
            background: "var(--color-surface)",
          }}
        >
          <h3 className="font-medium mb-3">Equipment reservieren</h3>
          {bookingError && (
            <div className="mb-3 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
              {bookingError}
            </div>
          )}
          <form onSubmit={handleBooking} className="space-y-3">
            <div className="grid grid-cols-2 gap-4">
              <div ref={searchRef} className="relative">
                <label className="block text-sm font-medium mb-1">Artikel</label>
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
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{
                    border: "1px solid var(--color-border)",
                    background: "var(--color-background)",
                  }}
                  placeholder="Suche nach Name, Inv.-Nr., Kategorie..."
                  required={!bookItemId}
                />
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
                    className="absolute right-2 top-[34px]"
                    style={{ color: "var(--color-muted-foreground)" }}
                  >
                    ✕
                  </button>
                )}
                {showItemDropdown && !selectedItemName && (
                  <div
                    className="absolute z-50 w-full mt-1 max-h-64 overflow-y-auto rounded-lg"
                    style={{
                      border: "1px solid var(--color-border)",
                      background: "var(--color-surface)",
                      boxShadow:
                        "var(--shadow-lg, 0 10px 15px -3px rgba(0,0,0,0.1))",
                    }}
                  >
                    {groupedInventory.length === 0 ? (
                      <div
                        className="px-3 py-4 text-sm text-center"
                        style={{ color: "var(--color-muted-foreground)" }}
                      >
                        Kein Artikel gefunden
                      </div>
                    ) : (
                      groupedInventory.map((group) => (
                        <div key={group.org.org_id}>
                          {/* Org-Header */}
                          <div
                            className="px-3 py-1.5 text-xs font-semibold sticky top-0"
                            style={{
                              background: "var(--color-muted)",
                              color: "var(--color-muted-foreground)",
                              borderBottom: "1px solid var(--color-border)",
                            }}
                          >
                            {group.org.org_id === currentOrgId
                              ? `Meine Organisation (${group.org.name})`
                              : `Partner: ${group.org.name}`}
                          </div>
                          {group.items.map((item) => (
                            <button
                              key={item.id}
                              type="button"
                              onClick={() => {
                                setBookItemId(item.id);
                                const prefix =
                                  item.org_id !== currentOrgId
                                    ? `[${getOrgName(item.org_id)}] `
                                    : "";
                                setSelectedItemName(
                                  `${prefix}${item.inventory_number} — ${item.name}`
                                );
                                setItemSearch("");
                                setShowItemDropdown(false);
                              }}
                              className="w-full text-left px-3 py-2.5 transition-colors"
                              style={{
                                borderBottom: "1px solid var(--color-border-light)",
                              }}
                              onMouseEnter={(e) =>
                                (e.currentTarget.style.background =
                                  "var(--color-surface-hover)")
                              }
                              onMouseLeave={(e) =>
                                (e.currentTarget.style.background = "transparent")
                              }
                            >
                              <div className="flex items-center justify-between">
                                <div className="flex items-center gap-1">
                                  {item.org_id !== currentOrgId && (
                                    <span
                                      className="w-2 h-2 rounded-full shrink-0"
                                      style={{ background: "#3b82f6" }}
                                    />
                                  )}
                                  <span
                                    className="font-mono text-xs px-1.5 py-0.5 rounded mr-2"
                                    style={{
                                      background: "var(--color-muted)",
                                      color: "var(--color-muted-foreground)",
                                    }}
                                  >
                                    {item.inventory_number}
                                  </span>
                                  <span className="text-sm font-medium">
                                    {item.name}
                                  </span>
                                </div>
                                <span
                                  className="text-xs"
                                  style={{ color: "var(--color-muted-foreground)" }}
                                >
                                  {item.quantity}x · {Number(item.cost_per_day).toFixed(2)}{" "}
                                  €/Tag
                                </span>
                              </div>
                              <div
                                className="text-xs mt-0.5 ml-1"
                                style={{ color: "var(--color-muted-foreground)" }}
                              >
                                {item.category}
                                {item.location ? ` · ${item.location}` : ""}
                              </div>
                            </button>
                          ))}
                        </div>
                      ))
                    )}
                  </div>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Menge</label>
                <input
                  type="number"
                  value={bookQuantity}
                  onChange={(e) => setBookQuantity(Number(e.target.value))}
                  min={1}
                  className="w-full px-3 py-2 rounded-lg text-sm"
                  style={{
                    border: "1px solid var(--color-border)",
                    background: "var(--color-background)",
                  }}
                  required
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Von</label>
                <DateInput
                  value={bookFrom}
                  onChange={setBookFrom}
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Bis</label>
                <DateInput
                  value={bookTo}
                  onChange={setBookTo}
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
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{
                  border: "1px solid var(--color-border)",
                  background: "var(--color-background)",
                }}
                placeholder="z.B. Wird für Hauptbühne benötigt"
              />
            </div>

            {/* Hinweis bei Partner-Equipment */}
            {bookItemId && (
              (() => {
                const selectedItem = inventoryItems.find((i) => i.id === bookItemId);
                if (selectedItem && selectedItem.org_id !== currentOrgId) {
                  return (
                    <div
                      className="p-2 rounded-lg text-xs"
                      style={{ background: "#fef3c7", color: "#b45309" }}
                    >
                      Dieses Equipment gehört{" "}
                      <strong>{getOrgName(selectedItem.org_id)}</strong>. Die Buchung
                      muss erst genehmigt werden.
                    </div>
                  );
                }
                return null;
              })()
            )}

            <div className="flex gap-2">
              <button
                type="submit"
                disabled={saving}
                className="px-4 py-2 text-sm text-white rounded-lg disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                {saving ? "Wird gebucht..." : "Reservieren"}
              </button>
              <button
                type="button"
                onClick={() => {
                  setShowBooking(false);
                  setBookingError(null);
                }}
                className="px-4 py-2 text-sm rounded-lg"
                style={{ border: "1px solid var(--color-border)" }}
              >
                Abbrechen
              </button>
            </div>
          </form>
        </div>
      )}

      {bookings.length === 0 ? (
        <div
          className="text-center py-8 rounded-lg"
          style={{
            border: "2px dashed var(--color-border)",
            color: "var(--color-muted-foreground)",
          }}
        >
          Noch kein Equipment gebucht
        </div>
      ) : (
        <div className="space-y-2">
          {bookings.map((booking) => {
            const isMyOrgEquipment =
              booking.inventory_items?.org_id === currentOrgId;
            const isExternalEquipment =
              booking.source_org_id && booking.source_org_id !== currentOrgId;
            const approvalColor =
              approvalColors[booking.approval_status] || approvalColors.approved;

            return (
              <div
                key={booking.id}
                className="flex items-center justify-between p-3 rounded-lg"
                style={{
                  border: "1px solid var(--color-border)",
                  opacity: booking.approval_status === "rejected" ? 0.5 : 1,
                }}
              >
                <div className="flex items-center gap-4">
                  <div>
                    <div className="font-medium flex items-center gap-2">
                      {/* Org-Indikator */}
                      {booking.source_org_id &&
                        booking.source_org_id !== currentOrgId && (
                          <span
                            className="inline-flex items-center gap-1 text-xs px-1.5 py-0.5 rounded-full"
                            style={{
                              background: "#dbeafe",
                              color: "#1d4ed8",
                            }}
                          >
                            {getOrgName(booking.source_org_id)}
                          </span>
                        )}
                      <span
                        className="font-mono text-xs px-1.5 py-0.5 rounded"
                        style={{
                          background: "var(--color-muted)",
                          color: "var(--color-muted-foreground)",
                        }}
                      >
                        {booking.inventory_items?.inventory_number}
                      </span>
                      {booking.inventory_items?.name}
                    </div>
                    <div
                      className="text-xs"
                      style={{ color: "var(--color-muted-foreground)" }}
                    >
                      {booking.quantity}x &middot;{" "}
                      {new Date(booking.date_from).toLocaleDateString("de-DE")} –{" "}
                      {new Date(booking.date_to).toLocaleDateString("de-DE")}
                      {booking.notes && ` · ${booking.notes}`}
                    </div>
                    {/* Ablehnungsgrund */}
                    {booking.approval_status === "rejected" &&
                      booking.rejection_reason && (
                        <div
                          className="text-xs mt-1"
                          style={{ color: "#dc2626" }}
                        >
                          Grund: {booking.rejection_reason}
                        </div>
                      )}
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  {/* Approval Badge */}
                  {booking.approval_status !== "approved" && (
                    <span
                      className="text-xs px-2 py-1 rounded-full font-medium"
                      style={{
                        background: approvalColor.bg,
                        color: approvalColor.text,
                      }}
                    >
                      {approvalLabels[booking.approval_status]}
                    </span>
                  )}

                  {/* Approval-Buttons für eigenes Equipment */}
                  {booking.approval_status === "pending" &&
                    isMyOrgEquipment && (
                      <>
                        <button
                          onClick={() => handleApproveBooking(booking.id)}
                          className="text-xs px-2 py-1 rounded-lg font-medium"
                          style={{
                            background: "#dcfce7",
                            color: "#16a34a",
                          }}
                        >
                          Genehmigen
                        </button>
                        <button
                          onClick={() => handleRejectBooking(booking.id)}
                          className="text-xs px-2 py-1 rounded-lg font-medium"
                          style={{
                            background: "#fee2e2",
                            color: "#dc2626",
                          }}
                        >
                          Ablehnen
                        </button>
                      </>
                    )}

                  {/* Status Badge (nur bei approved bookings) */}
                  {booking.approval_status === "approved" && (
                    <span
                      className={`text-xs px-2 py-1 rounded-full ${bookingStatusColors[booking.status]}`}
                    >
                      {bookingStatusLabels[booking.status]}
                    </span>
                  )}

                  {/* Actions */}
                  {booking.approval_status === "approved" &&
                    booking.status !== "returned" && (
                      <button
                        onClick={() => handleReturnBooking(booking.id)}
                        className="text-xs px-2"
                        style={{ color: "var(--color-muted-foreground)" }}
                      >
                        Zurückgeben
                      </button>
                    )}
                  <button
                    onClick={() => handleDeleteBooking(booking.id)}
                    className="text-xs"
                    style={{ color: "var(--color-destructive)" }}
                  >
                    Löschen
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
