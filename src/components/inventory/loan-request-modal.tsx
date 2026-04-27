"use client";

import { useState } from "react";
import { createClient } from "@/lib/supabase";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useWorkspace } from "@/contexts/org-context";
import { showToast } from "@/hooks/use-toast";
import type { InventoryItem } from "@/types/database";

export function LoanRequestModal({
  item,
  onClose,
  onSubmitted,
}: {
  item: InventoryItem;
  onClose: () => void;
  onSubmitted: () => void;
}) {
  const supabase = createClient();
  const currentUser = useCurrentUser();
  const { groupId } = useWorkspace();
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [quantity, setQuantity] = useState(1);
  const [purpose, setPurpose] = useState("");
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!currentUser) return;
    if (!dateFrom || !dateTo) {
      showToast("Zeitraum erforderlich", "error");
      return;
    }
    setSaving(true);

    // Anfrage einlegen — XOR: requesting = aktiver Workspace, supplying = Owner des Items
    const payload: Record<string, unknown> = {
      inventory_item_id: item.id,
      quantity,
      date_from: dateFrom,
      date_to: dateTo,
      purpose: purpose || null,
      message: message || null,
      requested_by: currentUser.id,
      status: "pending",
      // requesting:
      requesting_profile_id: groupId ? null : currentUser.id,
      requesting_group_id: groupId ?? null,
      requesting_org_id: null,
      // supplying:
      supplying_profile_id: item.owner_profile_id,
      supplying_group_id: item.owner_group_id,
      supplying_org_id: null,
    };

    const { error } = await supabase.from("equipment_requests").insert(payload);
    if (error) {
      setSaving(false);
      showToast("Fehler: " + error.message, "error");
      return;
    }

    // Notification an Owner
    try {
      let recipients: string[] = [];
      if (item.owner_profile_id) {
        recipients = [item.owner_profile_id];
      } else if (item.owner_group_id) {
        const { data: members } = await supabase
          .from("group_memberships")
          .select("profile_id")
          .eq("group_id", item.owner_group_id)
          .eq("is_active", true);
        recipients = (members || []).map((m) => m.profile_id);
      }
      if (recipients.length > 0) {
        const dates = `${new Date(dateFrom).toLocaleDateString("de-DE")} – ${new Date(dateTo).toLocaleDateString("de-DE")}`;
        await supabase.functions.invoke("send-notification", {
          body: {
            template_key: "loan_request_received",
            recipients,
            pref_key: "loans",
            vars: {
              item_name: item.name,
              requester_name: currentUser.name,
              dates,
              message: message || "(keine Nachricht)",
              request_url: `${window.location.origin}/inventory`,
            },
          },
        });
      }
    } catch {
      // silent
    }

    setSaving(false);
    showToast("Anfrage gesendet", "success");
    onSubmitted();
    onClose();
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background: "rgba(0,0,0,0.5)" }}
      onClick={onClose}
    >
      <div
        className="w-full max-w-md rounded-xl p-6"
        style={{ background: "var(--color-surface)" }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2 className="text-lg font-bold mb-1">Verleih-Anfrage</h2>
        <p className="text-sm mb-4" style={{ color: "var(--color-muted-foreground)" }}>
          {item.name}
        </p>

        <form onSubmit={submit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1">Von</label>
              <input
                type="date"
                value={dateFrom}
                onChange={(e) => setDateFrom(e.target.value)}
                required
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
              />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1">Bis</label>
              <input
                type="date"
                value={dateTo}
                onChange={(e) => setDateTo(e.target.value)}
                required
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium mb-1">Menge</label>
            <input
              type="number"
              min={1}
              max={item.quantity}
              value={quantity}
              onChange={(e) => setQuantity(parseInt(e.target.value) || 1)}
              className="w-full px-3 py-2 rounded-lg text-sm"
              style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
            />
            <p className="text-[11px] mt-1" style={{ color: "var(--color-muted-foreground)" }}>
              Verfügbar: {item.quantity}
            </p>
          </div>

          <div>
            <label className="block text-xs font-medium mb-1">Verwendung (optional)</label>
            <input
              type="text"
              value={purpose}
              onChange={(e) => setPurpose(e.target.value)}
              placeholder="z.B. Projekt XY"
              className="w-full px-3 py-2 rounded-lg text-sm"
              style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
            />
          </div>

          <div>
            <label className="block text-xs font-medium mb-1">Nachricht an den Eigentümer</label>
            <textarea
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              rows={3}
              placeholder="Optional"
              className="w-full px-3 py-2 rounded-lg text-sm"
              style={{ background: "var(--color-background)", border: "1px solid var(--color-border)" }}
            />
          </div>

          <div className="flex gap-2 justify-end pt-2">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 rounded-lg text-sm font-medium"
              style={{ border: "1px solid var(--color-border)" }}
            >
              Abbrechen
            </button>
            <button
              type="submit"
              disabled={saving}
              className="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              {saving ? "Sende..." : "Anfrage senden"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
