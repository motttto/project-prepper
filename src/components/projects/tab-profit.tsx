"use client";

import { useState, useCallback, useEffect } from "react";
import { createClient } from "@/lib/supabase";
import { useOrg } from "@/contexts/org-context";
import { useCurrentUser } from "@/hooks/use-current-user";
import { useRealtimeTable } from "@/hooks/use-realtime-table";
import type {
  Project,
  ProjectProfitShare,
  ShareType,
  OrgDecision,
  OrgDecisionVote,
  VoteChoice,
  CostItem,
} from "@/types/database";
import { IconPlus, IconTrash, IconCheck, IconX, IconPackage } from "@/components/ui/icons";

const shareTypeLabels: Record<ShareType, string> = {
  fixed: "Fixbetrag",
  percentage: "Prozent",
  hourly: "Stundensatz",
};

interface TabProfitProps {
  project: Project;
  canEdit: boolean;
}

export function TabProfit({ project, canEdit }: TabProfitProps) {
  const supabase = createClient();
  const { orgId } = useOrg();
  const currentUser = useCurrentUser();

  // Shares
  const [shares, setShares] = useState<ProjectProfitShare[]>([]);
  const [members, setMembers] = useState<{ id: string; name: string }[]>([]);
  const [revenue, setRevenue] = useState(project.revenue_actual ?? "");
  const [profit, setProfit] = useState<{ revenue: number; total_costs: number; profit: number } | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [addMemberId, setAddMemberId] = useState("");

  // Purchases (Anschaffungen)
  const [purchases, setPurchases] = useState<OrgDecision[]>([]);
  const [purchaseVotes, setPurchaseVotes] = useState<Record<string, OrgDecisionVote[]>>({});
  const [activeMembers, setActiveMembers] = useState<{ id: string; name: string }[]>([]);
  const [showAddPurchase, setShowAddPurchase] = useState(false);
  const [newItemName, setNewItemName] = useState("");
  const [newItemCost, setNewItemCost] = useState<number | "">("");
  const [newItemDescription, setNewItemDescription] = useState("");
  const [newItemCategory, setNewItemCategory] = useState("");
  const [categories, setCategories] = useState<{ id: string; name: string; icon: string }[]>([]);
  const [creating, setCreating] = useState(false);
  const [costItems, setCostItems] = useState<CostItem[]>([]);
  const [votingId, setVotingId] = useState<string | null>(null);
  const [voteComment, setVoteComment] = useState("");
  const [addingToInventory, setAddingToInventory] = useState<string | null>(null);

  // --- Data Loading ---

  const loadShares = useCallback(async () => {
    const { data } = await supabase
      .from("project_profit_shares")
      .select("*, profiles:profile_id(name, avatar_url)")
      .eq("project_id", project.id)
      .order("created_at");
    if (data) setShares(data);
    return data || [];
  }, [supabase, project.id]);

  const loadProfit = useCallback(async () => {
    const { data } = await supabase.rpc("calculate_project_profit", {
      p_project_id: project.id,
    });
    if (data && data.length > 0) {
      setProfit(data[0]);
    }
  }, [supabase, project.id]);

  const loadMembers = useCallback(async () => {
    if (!orgId) return;
    // Org-Mitglieder (für "Mitglied hinzufügen" Dropdown)
    const { data } = await supabase
      .from("org_memberships")
      .select("profile_id, profiles(name)")
      .eq("org_id", orgId)
      .eq("is_active", true);
    if (data) {
      setMembers(data.map((m: any) => ({ id: m.profile_id, name: m.profiles?.name || "" })));
    }
    // Stimmberechtigte = Projekt-Mitglieder + Org-Admins (wie im DB-Trigger)
    const { data: projMembers } = await supabase
      .from("project_members")
      .select("profile_id, profiles:profile_id(name)")
      .eq("project_id", project.id);

    const { data: orgAdmins } = await supabase
      .from("org_memberships")
      .select("profile_id, profiles(name), roles(name)")
      .eq("org_id", orgId)
      .eq("is_active", true);

    // Merge: project members + admins, dedupliziert
    const eligible = new Map<string, string>();
    (projMembers || []).forEach((m: any) => {
      eligible.set(m.profile_id, m.profiles?.name || "");
    });
    (orgAdmins || []).forEach((m: any) => {
      const roleName = Array.isArray(m.roles) ? m.roles[0]?.name : m.roles?.name;
      if (roleName === "admin") {
        eligible.set(m.profile_id, m.profiles?.name || "");
      }
    });
    setActiveMembers(Array.from(eligible.entries()).map(([id, name]) => ({ id, name })));
  }, [supabase, orgId, project.id]);

  const loadPurchases = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("org_decisions")
      .select("*, creator:created_by(name, avatar_url)")
      .eq("org_id", orgId)
      .eq("decision_type", "asset_purchase")
      .eq("related_project_id", project.id)
      .order("created_at", { ascending: false });
    if (data) setPurchases(data);
  }, [supabase, orgId, project.id]);

  const loadPurchaseVotes = useCallback(async () => {
    if (purchases.length === 0) return;
    const ids = purchases.map((p) => p.id);
    const { data } = await supabase
      .from("org_decision_votes")
      .select("*, voter:voter_id(name, avatar_url)")
      .in("decision_id", ids);
    if (data) {
      const grouped: Record<string, OrgDecisionVote[]> = {};
      data.forEach((v: any) => {
        if (!grouped[v.decision_id]) grouped[v.decision_id] = [];
        grouped[v.decision_id].push(v);
      });
      setPurchaseVotes(grouped);
    }
  }, [supabase, purchases]);

  const loadCategories = useCallback(async () => {
    if (!orgId) return;
    const { data } = await supabase
      .from("inventory_categories")
      .select("id, name, icon")
      .eq("org_id", orgId)
      .order("sort_order");
    if (data) setCategories(data);
  }, [supabase, orgId]);

  const loadCostItems = useCallback(async () => {
    const { data } = await supabase
      .from("cost_items")
      .select("*")
      .eq("project_id", project.id)
      .order("created_at");
    if (data) setCostItems(data as CostItem[]);
  }, [supabase, project.id]);

  // Auto-create shares for all project members
  const autoCreateShares = useCallback(async (existingShares: ProjectProfitShare[]) => {
    const { data: projectMembers } = await supabase
      .from("project_members")
      .select("profile_id")
      .eq("project_id", project.id);

    if (!projectMembers || projectMembers.length === 0) return false;

    const existingIds = new Set(existingShares.map((s) => s.profile_id));
    const missing = projectMembers.filter((pm) => !existingIds.has(pm.profile_id));

    if (missing.length === 0) return false;

    const totalAfter = existingShares.length + missing.length;
    const pct = Math.round(10000 / totalAfter) / 100;

    await supabase.from("project_profit_shares").insert(
      missing.map((pm) => ({
        project_id: project.id,
        profile_id: pm.profile_id,
        share_type: "percentage" as ShareType,
        share_value: pct,
      }))
    );

    if (existingShares.length > 0) {
      for (const share of existingShares) {
        await supabase
          .from("project_profit_shares")
          .update({ share_type: "percentage", share_value: pct })
          .eq("id", share.id);
      }
    }

    return true;
  }, [supabase, project.id]);

  // --- Init ---

  useEffect(() => {
    async function init() {
      const [existingShares] = await Promise.all([
        loadShares(), loadProfit(), loadMembers(), loadPurchases(), loadCategories(), loadCostItems(),
      ]);
      const created = await autoCreateShares(existingShares);
      if (created) await loadShares();
      setLoading(false);
    }
    init();
  }, [loadShares, loadProfit, loadMembers, autoCreateShares, loadPurchases, loadCategories, loadCostItems]);

  useEffect(() => { loadPurchaseVotes(); }, [loadPurchaseVotes]);

  // Realtime
  useRealtimeTable({ table: "project_profit_shares", onDataChange: loadShares });
  useRealtimeTable({
    table: "project_members",
    onDataChange: async () => {
      const existing = await loadShares();
      const created = await autoCreateShares(existing);
      if (created) await loadShares();
    },
  });
  useRealtimeTable({
    table: "org_decisions",
    onDataChange: loadPurchases,
  });
  useRealtimeTable({
    table: "org_decision_votes",
    onDataChange: () => { loadPurchases(); loadPurchaseVotes(); },
  });

  useRealtimeTable({
    table: "cost_items",
    onDataChange: () => { loadCostItems(); loadProfit(); },
  });

  // --- Cost Item Toggle ---

  async function toggleCostItem(costItemId: string, currentExcluded: boolean) {
    await supabase
      .from("cost_items")
      .update({ exclude_from_profit: !currentExcluded })
      .eq("id", costItemId);
    await Promise.all([loadCostItems(), loadProfit()]);
  }

  // --- Share Actions ---

  async function saveRevenue() {
    setSaving(true);
    await supabase
      .from("projects")
      .update({ revenue_actual: revenue !== "" ? Number(revenue) : null })
      .eq("id", project.id);
    await loadProfit();
    setSaving(false);
  }

  async function addShare() {
    if (!addMemberId) return;
    await supabase.from("project_profit_shares").insert({
      project_id: project.id,
      profile_id: addMemberId,
      share_type: "percentage",
      share_value: shares.length === 0 ? 100 : 0,
    });
    setAddMemberId("");
    await loadShares();
  }

  async function distributeEvenly() {
    if (shares.length === 0) return;
    const pct = Math.round(10000 / shares.length) / 100;
    for (const share of shares) {
      await supabase
        .from("project_profit_shares")
        .update({ share_type: "percentage", share_value: pct })
        .eq("id", share.id);
    }
    await loadShares();
    await recalculateAmounts();
  }

  async function recalculateAmounts() {
    if (!profit) return;
    const profitAmount = profit.profit;
    for (const share of shares) {
      let amount = 0;
      if (share.share_type === "fixed") {
        amount = share.share_value;
      } else if (share.share_type === "percentage") {
        amount = profitAmount * (share.share_value / 100);
      } else if (share.share_type === "hourly" && share.hours_worked) {
        amount = share.share_value * share.hours_worked;
      }
      await supabase
        .from("project_profit_shares")
        .update({ calculated_amount: Math.round(amount * 100) / 100 })
        .eq("id", share.id);
    }
    await loadShares();
  }

  async function updateShare(shareId: string, field: string, value: any) {
    await supabase
      .from("project_profit_shares")
      .update({ [field]: value })
      .eq("id", shareId);
    await loadShares();
  }

  async function deleteShare(shareId: string) {
    await supabase.from("project_profit_shares").delete().eq("id", shareId);
    await loadShares();
  }

  async function markPaid(shareId: string) {
    await supabase
      .from("project_profit_shares")
      .update({ is_paid: true, paid_at: new Date().toISOString() })
      .eq("id", shareId);
    await loadShares();
  }

  // --- Purchase / Anschaffung Actions ---

  async function handleCreatePurchase() {
    if (!orgId || !currentUser || !newItemName.trim() || !newItemCost) return;
    setCreating(true);

    const costNum = Number(newItemCost);
    await supabase.from("org_decisions").insert({
      org_id: orgId,
      title: `Anschaffung: ${newItemName.trim()}`,
      description: newItemDescription.trim() || null,
      decision_type: "asset_purchase",
      requires_unanimous: true,
      related_project_id: project.id,
      created_by: currentUser.id,
      metadata: {
        item_name: newItemName.trim(),
        estimated_cost: costNum,
        category_id: newItemCategory || null,
        description: newItemDescription.trim() || null,
        source_project: project.id,
      },
    });

    setNewItemName("");
    setNewItemCost("");
    setNewItemDescription("");
    setNewItemCategory("");
    setShowAddPurchase(false);
    setCreating(false);
    await loadPurchases();
  }

  async function handleDeletePurchase(decisionId: string) {
    // Votes werden per CASCADE gelöscht
    await supabase.from("org_decisions").delete().eq("id", decisionId);
    await loadPurchases();
  }

  async function handleVote(decisionId: string, vote: VoteChoice) {
    if (!currentUser) return;
    await supabase.from("org_decision_votes").upsert(
      {
        decision_id: decisionId,
        voter_id: currentUser.id,
        vote,
        comment: voteComment.trim() || null,
      },
      { onConflict: "decision_id,voter_id" }
    );
    setVotingId(null);
    setVoteComment("");
  }

  async function handleAddToInventory(decision: OrgDecision) {
    if (!orgId || !currentUser) return;
    setAddingToInventory(decision.id);

    const meta = decision.metadata as Record<string, any> || {};
    const itemName = meta.item_name || decision.title.replace("Anschaffung: ", "");
    const cost = meta.estimated_cost || 0;
    const categoryId = meta.category_id || null;

    // Find category name for the category field
    let categoryName = "";
    if (categoryId) {
      const cat = categories.find((c) => c.id === categoryId);
      categoryName = cat?.name || "";
    }

    // Insert into inventory
    const { data: newItem } = await supabase
      .from("inventory_items")
      .insert({
        org_id: orgId,
        name: itemName,
        category: categoryName,
        category_id: categoryId,
        quantity: 1,
        condition: "new",
        purchase_price: cost,
        purchased_by: currentUser.id,
        purchased_at: new Date().toISOString(),
        owner: "organization",
      })
      .select("id")
      .single();

    // Link inventory item back to decision
    if (newItem) {
      await supabase
        .from("org_decisions")
        .update({
          related_item_id: newItem.id,
          metadata: { ...meta, added_to_inventory: true, inventory_item_id: newItem.id },
        })
        .eq("id", decision.id);
    }

    setAddingToInventory(null);
    await loadPurchases();
  }

  function getMyVote(decisionId: string): OrgDecisionVote | undefined {
    return purchaseVotes[decisionId]?.find((v) => v.voter_id === currentUser?.id);
  }

  // --- Computed ---

  const inputStyle = {
    border: "1px solid var(--color-border)",
    background: "var(--color-background)",
  };

  const totalShared = shares.reduce((s, sh) => s + Number(sh.calculated_amount || 0), 0);
  const availableMembers = members.filter((m) => !shares.some((s) => s.profile_id === m.id));

  const approvedPurchases = purchases.filter((p) => p.status === "approved");
  const totalPurchaseCost = approvedPurchases.reduce((sum, p) => {
    const meta = p.metadata as Record<string, any> || {};
    return sum + (Number(meta.estimated_cost) || 0);
  }, 0);

  const profitAfterPurchases = (profit?.profit || 0) - totalPurchaseCost;

  if (loading) {
    return <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>Lade...</p>;
  }

  return (
    <div className="space-y-6">
      {/* Finanzen-Übersicht */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div className="rounded-lg p-4" style={{ background: "var(--color-muted)" }}>
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Einnahmen</div>
          {canEdit ? (
            <div className="flex items-center gap-2 mt-1">
              <input
                type="number"
                value={revenue}
                onChange={(e) => setRevenue(e.target.value === "" ? "" : Number(e.target.value))}
                onBlur={saveRevenue}
                className="w-full px-2 py-1 rounded text-lg font-bold"
                style={inputStyle}
                placeholder="0,00"
                min={0}
                step={0.01}
              />
              <span className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>€</span>
            </div>
          ) : (
            <div className="text-lg font-bold mt-1">
              {revenue ? Number(revenue).toLocaleString("de-DE", { style: "currency", currency: "EUR" }) : "–"}
            </div>
          )}
        </div>
        <div className="rounded-lg p-4" style={{ background: "var(--color-muted)" }}>
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Kosten (Ist)</div>
          <div className="text-lg font-bold mt-1" style={{ color: "var(--color-error)" }}>
            {profit ? Number(profit.total_costs).toLocaleString("de-DE", { style: "currency", currency: "EUR" }) : "–"}
          </div>
        </div>
        <div
          className="rounded-lg p-4"
          style={{
            background: profit && profit.profit >= 0 ? "var(--color-success-light)" : "var(--color-error-light)",
          }}
        >
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>Gewinn (Gesamt)</div>
          <div
            className="text-lg font-bold mt-1"
            style={{ color: profit && profit.profit >= 0 ? "var(--color-success)" : "var(--color-error)" }}
          >
            {profit ? Number(profit.profit).toLocaleString("de-DE", { style: "currency", currency: "EUR" }) : "–"}
          </div>
        </div>
        <div
          className="rounded-lg p-4"
          style={{
            background: profitAfterPurchases >= 0 ? "var(--color-primary-light)" : "var(--color-error-light)",
          }}
        >
          <div className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
            Verfügbar {totalPurchaseCost > 0 && <span>(nach Anschaffungen)</span>}
          </div>
          <div
            className="text-lg font-bold mt-1"
            style={{ color: profitAfterPurchases >= 0 ? "var(--color-primary)" : "var(--color-error)" }}
          >
            {profitAfterPurchases.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
          </div>
        </div>
      </div>

      {/* ========== Kostenposten (Toggle für Gewinnberechnung) ========== */}
      {costItems.length > 0 && (
        <div>
          <h3 className="text-sm font-semibold mb-2">Kostenposten</h3>
          <p className="text-xs mb-3" style={{ color: "var(--color-muted-foreground)" }}>
            Deaktivierte Posten werden nicht in die Gewinnberechnung einbezogen.
          </p>
          <div className="space-y-1">
            {costItems.map((ci) => {
              const categoryLabels: Record<string, string> = {
                personnel: "Personal",
                material: "Material",
                inventory: "Inventar",
                external: "Extern",
                other: "Sonstiges",
              };
              const excluded = ci.exclude_from_profit;
              return (
                <div
                  key={ci.id}
                  className="flex items-center gap-3 px-3 py-2 rounded-lg transition-opacity"
                  style={{
                    background: "var(--color-muted)",
                    opacity: excluded ? 0.5 : 1,
                  }}
                >
                  <label className="relative inline-flex items-center cursor-pointer shrink-0">
                    <input
                      type="checkbox"
                      checked={!excluded}
                      onChange={() => toggleCostItem(ci.id, excluded)}
                      disabled={!canEdit}
                      className="sr-only peer"
                    />
                    <div
                      className="w-8 h-4.5 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:rounded-full after:h-3.5 after:w-3.5 after:transition-all"
                      style={{
                        background: excluded ? "var(--color-border)" : "var(--color-success)",
                      }}
                    >
                      <div
                        className="absolute top-[2px] rounded-full h-3.5 w-3.5 bg-white transition-all"
                        style={{ left: excluded ? "2px" : "calc(100% - 16px)" }}
                      />
                    </div>
                  </label>
                  <span
                    className="text-xs px-1.5 py-0.5 rounded shrink-0"
                    style={{ background: "var(--color-surface)", color: "var(--color-muted-foreground)" }}
                  >
                    {categoryLabels[ci.category] || ci.category}
                  </span>
                  <span className="text-sm flex-1 truncate" style={{ color: excluded ? "var(--color-muted-foreground)" : "var(--color-foreground)" }}>
                    {ci.description || "–"}
                  </span>
                  <span className="text-sm font-medium shrink-0 tabular-nums" style={{ color: excluded ? "var(--color-muted-foreground)" : "var(--color-foreground)" }}>
                    {ci.amount_actual != null
                      ? Number(ci.amount_actual).toLocaleString("de-DE", { style: "currency", currency: "EUR" })
                      : ci.amount_planned
                        ? `(${Number(ci.amount_planned).toLocaleString("de-DE", { style: "currency", currency: "EUR" })} geplant)`
                        : "–"}
                  </span>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* ========== Gewinnverwendung / Anschaffungen ========== */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-semibold flex items-center gap-2">
            <IconPackage size={16} />
            Gewinnverwendung
            {purchases.filter((p) => p.status === "open").length > 0 && (
              <span
                className="px-2 py-0.5 rounded-full text-xs font-medium"
                style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}
              >
                {purchases.filter((p) => p.status === "open").length} offen
              </span>
            )}
          </h3>
          {canEdit && (
            <button
              onClick={() => setShowAddPurchase(!showAddPurchase)}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
              style={{ background: "var(--color-primary)" }}
            >
              <IconPlus size={12} />
              Anschaffung vorschlagen
            </button>
          )}
        </div>

        {/* Neue Anschaffung vorschlagen */}
        {showAddPurchase && (
          <div
            className="rounded-lg p-4 mb-3 space-y-3"
            style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
          >
            <div className="text-xs font-medium mb-2" style={{ color: "var(--color-muted-foreground)" }}>
              Wird als Beschluss zur Abstimmung gestellt
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <input
                type="text"
                value={newItemName}
                onChange={(e) => setNewItemName(e.target.value)}
                placeholder="Artikel-Name *"
                className="w-full px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
                autoFocus
              />
              <div className="flex items-center gap-2">
                <input
                  type="number"
                  value={newItemCost}
                  onChange={(e) => setNewItemCost(e.target.value === "" ? "" : Number(e.target.value))}
                  placeholder="Kosten *"
                  className="flex-1 px-3 py-2 rounded-lg text-sm"
                  style={inputStyle}
                  min={0}
                  step={0.01}
                />
                <span className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>€</span>
              </div>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <select
                value={newItemCategory}
                onChange={(e) => setNewItemCategory(e.target.value)}
                className="px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              >
                <option value="">Kategorie (optional)</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.icon} {c.name}
                  </option>
                ))}
              </select>
              <input
                type="text"
                value={newItemDescription}
                onChange={(e) => setNewItemDescription(e.target.value)}
                placeholder="Beschreibung / Begründung (optional)"
                className="px-3 py-2 rounded-lg text-sm"
                style={inputStyle}
              />
            </div>
            <div className="flex gap-2 justify-end">
              <button
                onClick={() => { setShowAddPurchase(false); setNewItemName(""); setNewItemCost(""); setNewItemDescription(""); setNewItemCategory(""); }}
                className="px-3 py-1.5 rounded-lg text-xs font-medium"
                style={{ border: "1px solid var(--color-border)" }}
              >
                Abbrechen
              </button>
              <button
                onClick={handleCreatePurchase}
                disabled={creating || !newItemName.trim() || !newItemCost}
                className="px-3 py-1.5 rounded-lg text-xs font-medium text-white disabled:opacity-50"
                style={{ background: "var(--color-primary)" }}
              >
                {creating ? "Wird erstellt..." : "Zur Abstimmung stellen"}
              </button>
            </div>
          </div>
        )}

        {/* Anschaffungen Liste */}
        {purchases.length === 0 && !showAddPurchase ? (
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Noch keine Anschaffungen geplant. Schlage Anschaffungen vor, über die das Team abstimmen kann.
          </p>
        ) : (
          <div className="space-y-2">
            {purchases.map((purchase) => {
              const meta = purchase.metadata as Record<string, any> || {};
              const cost = Number(meta.estimated_cost) || 0;
              const decVotes = purchaseVotes[purchase.id] || [];
              const myVote = getMyVote(purchase.id);
              const approvals = decVotes.filter((v) => v.vote === "approve").length;
              const rejections = decVotes.filter((v) => v.vote === "reject").length;
              const total = activeMembers.length;
              const addedToInventory = !!meta.added_to_inventory;
              const catId = meta.category_id;
              const cat = catId ? categories.find((c) => c.id === catId) : null;

              const statusConfig: Record<string, { label: string; color: string; bg: string }> = {
                open: { label: "Abstimmung", color: "var(--color-warning)", bg: "var(--color-warning-light)" },
                approved: { label: "Genehmigt", color: "var(--color-success)", bg: "var(--color-success-light)" },
                rejected: { label: "Abgelehnt", color: "var(--color-error)", bg: "var(--color-error-light)" },
                expired: { label: "Abgelaufen", color: "var(--color-muted-foreground)", bg: "var(--color-muted)" },
              };
              const sc = statusConfig[purchase.status] || statusConfig.open;

              return (
                <div
                  key={purchase.id}
                  className="rounded-lg p-3"
                  style={{ background: "var(--color-surface)", border: "1px solid var(--color-border-light)" }}
                >
                  <div className="flex items-start justify-between gap-3 mb-2">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold truncate">
                          {cat && <span className="mr-1">{cat.icon}</span>}
                          {meta.item_name || purchase.title}
                        </span>
                        <span
                          className="shrink-0 px-2 py-0.5 rounded-full text-xs font-medium"
                          style={{ background: sc.bg, color: sc.color }}
                        >
                          {sc.label}
                        </span>
                        {addedToInventory && (
                          <span
                            className="shrink-0 px-2 py-0.5 rounded-full text-xs font-medium"
                            style={{ background: "var(--color-primary-light)", color: "var(--color-primary)" }}
                          >
                            Im Inventar
                          </span>
                        )}
                      </div>
                      {purchase.description && (
                        <p className="text-xs mt-0.5 truncate" style={{ color: "var(--color-muted-foreground)" }}>
                          {purchase.description}
                        </p>
                      )}
                    </div>
                    <span className="text-sm font-bold shrink-0" style={{ color: "var(--color-primary)" }}>
                      {cost.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                    </span>
                  </div>

                  {/* Abstimmungs-Fortschritt */}
                  {purchase.status === "open" && (
                    <div className="mb-2">
                      <div className="flex justify-between text-xs mb-1" style={{ color: "var(--color-muted-foreground)" }}>
                        <span>{approvals} von {total} Stimmen</span>
                        {rejections > 0 && <span style={{ color: "var(--color-error)" }}>{rejections} dagegen</span>}
                      </div>
                      <div className="w-full rounded-full h-1.5 overflow-hidden" style={{ background: "var(--color-border)" }}>
                        <div
                          className="h-full rounded-full transition-all"
                          style={{ width: `${total > 0 ? (approvals / total) * 100 : 0}%`, background: "var(--color-success)" }}
                        />
                      </div>
                      {/* Wer hat abgestimmt */}
                      <div className="flex flex-wrap gap-1 mt-1.5">
                        {activeMembers.map((member) => {
                          const mVote = decVotes.find((v) => v.voter_id === member.id);
                          return (
                            <span
                              key={member.id}
                              className="px-1.5 py-0.5 rounded-full text-xs"
                              style={{
                                background: mVote
                                  ? mVote.vote === "approve" ? "var(--color-success-light)"
                                  : mVote.vote === "reject" ? "var(--color-error-light)"
                                  : "var(--color-muted)"
                                  : "var(--color-muted)",
                                color: mVote
                                  ? mVote.vote === "approve" ? "var(--color-success)"
                                  : mVote.vote === "reject" ? "var(--color-error)"
                                  : "var(--color-muted-foreground)"
                                  : "var(--color-muted-foreground)",
                                opacity: mVote ? 1 : 0.5,
                              }}
                            >
                              {mVote?.vote === "approve" ? "✓ " : mVote?.vote === "reject" ? "✗ " : ""}
                              {member.name}
                            </span>
                          );
                        })}
                      </div>
                    </div>
                  )}

                  {/* Abstimmung (offene Beschlüsse) */}
                  {purchase.status === "open" && !myVote && (
                    <div className="mt-2 space-y-2">
                      <div className="flex items-center gap-2">
                        <button
                          onClick={() => {
                            if (votingId === purchase.id) {
                              handleVote(purchase.id, "approve");
                            } else {
                              handleVote(purchase.id, "approve");
                            }
                          }}
                          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                          style={{ background: "var(--color-success)" }}
                        >
                          <IconCheck size={12} /> Zustimmen
                        </button>
                        <button
                          onClick={() => handleVote(purchase.id, "reject")}
                          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                          style={{ background: "var(--color-error)" }}
                        >
                          <IconX size={12} /> Ablehnen
                        </button>
                        <button
                          onClick={() => setVotingId(votingId === purchase.id ? null : purchase.id)}
                          className="px-3 py-1.5 rounded-lg text-xs"
                          style={{ color: "var(--color-muted-foreground)", border: "1px solid var(--color-border)" }}
                        >
                          Kommentar
                        </button>
                      </div>
                      {votingId === purchase.id && (
                        <input
                          type="text"
                          value={voteComment}
                          onChange={(e) => setVoteComment(e.target.value)}
                          placeholder="Kommentar zur Abstimmung (optional)"
                          className="w-full px-3 py-1.5 rounded-lg text-xs"
                          style={inputStyle}
                          autoFocus
                        />
                      )}
                    </div>
                  )}

                  {/* Eigene Stimme */}
                  {myVote && purchase.status === "open" && (
                    <div className="text-xs mt-1" style={{ color: "var(--color-muted-foreground)" }}>
                      Deine Stimme: {myVote.vote === "approve" ? "✓ Zugestimmt" : myVote.vote === "reject" ? "✗ Abgelehnt" : "Enthalten"}
                      {myVote.comment && <span className="ml-1">— &quot;{myVote.comment}&quot;</span>}
                    </div>
                  )}

                  {/* Genehmigt → Ins Inventar übernehmen */}
                  {purchase.status === "approved" && !addedToInventory && canEdit && (
                    <button
                      onClick={() => handleAddToInventory(purchase)}
                      disabled={addingToInventory === purchase.id}
                      className="mt-2 flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-white disabled:opacity-50"
                      style={{ background: "var(--color-success)" }}
                    >
                      <IconPackage size={12} />
                      {addingToInventory === purchase.id ? "Wird hinzugefügt..." : "Ins Inventar übernehmen"}
                    </button>
                  )}

                  {/* Meta-Info + Löschen */}
                  <div className="flex items-center justify-between mt-2">
                    <div className="flex items-center gap-3 text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                      <span>von {(purchase as any).creator?.name || "Unbekannt"}</span>
                      <span>{new Date(purchase.created_at).toLocaleDateString("de-DE")}</span>
                      {purchase.resolved_at && (
                        <span>entschieden {new Date(purchase.resolved_at).toLocaleDateString("de-DE")}</span>
                      )}
                    </div>
                    {canEdit && !addedToInventory && (
                      <button
                        onClick={() => handleDeletePurchase(purchase.id)}
                        className="p-1 rounded hover:opacity-70"
                        style={{ color: "var(--color-error)" }}
                        title="Vorschlag löschen"
                      >
                        <IconTrash size={12} />
                      </button>
                    )}
                  </div>
                </div>
              );
            })}

            {/* Summe genehmigte Anschaffungen */}
            {approvedPurchases.length > 0 && (
              <div
                className="flex justify-between items-center px-3 py-2 rounded-lg text-sm"
                style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
              >
                <span className="font-medium">Genehmigte Anschaffungen</span>
                <span className="font-bold" style={{ color: "var(--color-primary)" }}>
                  {totalPurchaseCost.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                </span>
              </div>
            )}
          </div>
        )}
      </div>

      {/* ========== Gewinnverteilung ========== */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-semibold">Gewinnverteilung</h3>
          <div className="flex gap-2">
            {shares.length > 0 && canEdit && (
              <>
                <button
                  onClick={distributeEvenly}
                  className="px-3 py-1.5 rounded-lg text-xs font-medium"
                  style={{ border: "1px solid var(--color-border)" }}
                >
                  Gleichmäßig
                </button>
                <button
                  onClick={recalculateAmounts}
                  className="px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                  style={{ background: "var(--color-primary)" }}
                >
                  Neuberechnen
                </button>
              </>
            )}
          </div>
        </div>

        {/* Shares */}
        {shares.length === 0 ? (
          <p className="text-sm" style={{ color: "var(--color-muted-foreground)" }}>
            Noch keine Gewinnverteilung angelegt. Füge Mitglieder hinzu.
          </p>
        ) : (
          <div className="space-y-2">
            {shares.map((share) => (
              <div
                key={share.id}
                className="flex items-center gap-3 px-3 py-2.5 rounded-lg"
                style={{ background: "var(--color-muted)", border: "1px solid var(--color-border-light)" }}
              >
                {/* Name */}
                <span className="text-sm font-medium min-w-[100px]">
                  {(share as any).profiles?.name || "–"}
                </span>

                {/* Typ */}
                {canEdit ? (
                  <select
                    value={share.share_type}
                    onChange={(e) => updateShare(share.id, "share_type", e.target.value)}
                    className="px-2 py-1 rounded text-xs"
                    style={inputStyle}
                  >
                    {Object.entries(shareTypeLabels).map(([k, v]) => (
                      <option key={k} value={k}>{v}</option>
                    ))}
                  </select>
                ) : (
                  <span className="text-xs px-2 py-1 rounded" style={{ background: "var(--color-surface)" }}>
                    {shareTypeLabels[share.share_type]}
                  </span>
                )}

                {/* Wert */}
                {canEdit ? (
                  <input
                    type="number"
                    value={share.share_value}
                    onChange={(e) => updateShare(share.id, "share_value", Number(e.target.value))}
                    className="w-20 px-2 py-1 rounded text-xs text-right"
                    style={inputStyle}
                    min={0}
                    step={share.share_type === "percentage" ? 1 : 0.01}
                  />
                ) : (
                  <span className="text-xs font-mono">{share.share_value}</span>
                )}
                <span className="text-xs" style={{ color: "var(--color-muted-foreground)" }}>
                  {share.share_type === "percentage" ? "%" : "€"}
                  {share.share_type === "hourly" ? "/h" : ""}
                </span>

                {/* Stunden (nur bei hourly) */}
                {share.share_type === "hourly" && canEdit && (
                  <input
                    type="number"
                    value={share.hours_worked ?? ""}
                    onChange={(e) => updateShare(share.id, "hours_worked", e.target.value ? Number(e.target.value) : null)}
                    className="w-16 px-2 py-1 rounded text-xs"
                    style={inputStyle}
                    placeholder="Std."
                    min={0}
                    step={0.5}
                  />
                )}

                {/* Berechneter Betrag */}
                <span className="ml-auto text-sm font-bold" style={{ color: "var(--color-primary)" }}>
                  {Number(share.calculated_amount).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                </span>

                {/* Bezahlt-Status */}
                {share.is_paid ? (
                  <span className="px-2 py-0.5 rounded-full text-xs" style={{ background: "var(--color-success-light)", color: "var(--color-success)" }}>
                    Bezahlt
                  </span>
                ) : canEdit ? (
                  <button
                    onClick={() => markPaid(share.id)}
                    className="p-1 rounded" style={{ color: "var(--color-success)" }}
                    title="Als bezahlt markieren"
                  >
                    <IconCheck size={14} />
                  </button>
                ) : (
                  <span className="px-2 py-0.5 rounded-full text-xs" style={{ background: "var(--color-warning-light)", color: "var(--color-warning)" }}>
                    Offen
                  </span>
                )}

                {/* Löschen */}
                {canEdit && (
                  <button
                    onClick={() => deleteShare(share.id)}
                    className="p-1 rounded" style={{ color: "var(--color-error)" }}
                  >
                    <IconTrash size={12} />
                  </button>
                )}
              </div>
            ))}

            {/* Summe */}
            <div
              className="flex justify-between items-center px-3 py-2 rounded-lg text-sm font-bold"
              style={{ background: "var(--color-surface)", border: "1px solid var(--color-border)" }}
            >
              <span>Gesamt verteilt</span>
              <span style={{ color: totalShared <= (profit?.profit || 0) ? "var(--color-success)" : "var(--color-error)" }}>
                {totalShared.toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                {profit && (
                  <span className="font-normal text-xs ml-2" style={{ color: "var(--color-muted-foreground)" }}>
                    von {Number(profit.profit).toLocaleString("de-DE", { style: "currency", currency: "EUR" })}
                  </span>
                )}
              </span>
            </div>
          </div>
        )}

        {/* Mitglied hinzufügen */}
        {canEdit && availableMembers.length > 0 && (
          <div className="flex gap-2 mt-3">
            <select
              value={addMemberId}
              onChange={(e) => setAddMemberId(e.target.value)}
              className="flex-1 px-3 py-2 rounded-lg text-sm"
              style={inputStyle}
            >
              <option value="">Mitglied hinzufügen...</option>
              {availableMembers.map((m) => (
                <option key={m.id} value={m.id}>{m.name}</option>
              ))}
            </select>
            <button
              onClick={addShare}
              disabled={!addMemberId}
              className="flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-50"
              style={{ background: "var(--color-primary)" }}
            >
              <IconPlus size={14} />
              Hinzufügen
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
