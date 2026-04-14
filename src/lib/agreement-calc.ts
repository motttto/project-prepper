// Berechnungen für Kooperationsvereinbarungen
//
// Gewinn wird nach flexibler Formel aufgeteilt:
//   netProfit = revenue - costs - pre_deductions
//   Jeder Beteiligte erhält einen Anteil, dessen Gewichtung nach profit_formula.weights bestimmt wird.

import type {
  ProfitFormula,
  AgreementRole,
  AgreementInventoryContribution,
} from "@/types/database";

export interface ProfitShareResult {
  profile_id: string;
  name: string;
  // Rohanteile
  hours_contribution: number;    // stunden * stundensatz
  inventory_contribution: number; // inventar-tagessaetze * tage
  capital_contribution: number;
  fixed_amount: number;
  // Berechneter Anteil in Euro
  calculated_amount: number;
  // Anteil in Prozent
  percentage: number;
}

/**
 * Berechnet Pre-Deductions-Summe
 */
export function totalPreDeductions(formula: ProfitFormula): number {
  if (!formula.pre_deductions) return 0;
  return formula.pre_deductions.reduce((sum, d) => sum + Number(d.amount || 0), 0);
}

/**
 * Berechnet den Netto-Gewinn nach Abzug Pre-Deductions
 */
export function calculateNetProfit(
  revenue: number,
  costs: number,
  formula: ProfitFormula
): number {
  return revenue - costs - totalPreDeductions(formula);
}

/**
 * Berechnet Gewinn-Shares für alle Beteiligten
 *
 * @param netProfit - Netto-Gewinn nach Abzug Pre-Deductions
 * @param roles - Agreement-Rollen mit Stunden/Kapital/Festbeträgen
 * @param contributions - Inventar-Beiträge
 * @param formula - Gewinn-Formel mit Gewichtungen
 * @param projectDays - Anzahl Tage (für Inventar-Berechnung)
 */
export function calculateProfitShares(
  netProfit: number,
  roles: AgreementRole[],
  contributions: AgreementInventoryContribution[],
  formula: ProfitFormula,
  projectDays: number = 1
): ProfitShareResult[] {
  if (netProfit <= 0 || roles.length === 0) {
    return roles.map((r) => ({
      profile_id: r.profile_id,
      name: r.profiles?.name || "",
      hours_contribution: Number(r.hours_estimate) * Number(r.hourly_rate),
      inventory_contribution: 0,
      capital_contribution: Number(r.capital_contribution),
      fixed_amount: Number(r.fixed_amount),
      calculated_amount: 0,
      percentage: 0,
    }));
  }

  // Gewichtungen normalisieren
  const weights = {
    hours: formula.weights?.hours ?? 0,
    inventory: formula.weights?.inventory ?? 0,
    capital: formula.weights?.capital ?? 0,
    fixed: formula.weights?.fixed ?? 0,
  };
  const weightSum = weights.hours + weights.inventory + weights.capital + weights.fixed;

  // Wenn keine Gewichtungen definiert → gleicher Anteil pro Person
  if (weightSum === 0) {
    const equalShare = netProfit / roles.length;
    return roles.map((r) => ({
      profile_id: r.profile_id,
      name: r.profiles?.name || "",
      hours_contribution: Number(r.hours_estimate) * Number(r.hourly_rate),
      inventory_contribution: 0,
      capital_contribution: Number(r.capital_contribution),
      fixed_amount: Number(r.fixed_amount),
      calculated_amount: equalShare,
      percentage: 100 / roles.length,
    }));
  }

  // Pro Person die einzelnen Beiträge berechnen
  const perPerson = roles.map((r) => {
    const hoursValue = Number(r.hours_estimate) * Number(r.hourly_rate);
    const invValue = contributions
      .filter((c) => c.contributor_id === r.profile_id)
      .reduce((sum, c) => sum + Number(c.daily_rate) * Number(c.quantity) * projectDays, 0);
    return {
      profile_id: r.profile_id,
      name: r.profiles?.name || "",
      hours: hoursValue,
      inventory: invValue,
      capital: Number(r.capital_contribution),
      fixed: Number(r.fixed_amount),
    };
  });

  // Summen pro Komponente (für Normalisierung)
  const totals = {
    hours: perPerson.reduce((s, p) => s + p.hours, 0),
    inventory: perPerson.reduce((s, p) => s + p.inventory, 0),
    capital: perPerson.reduce((s, p) => s + p.capital, 0),
    fixed: perPerson.reduce((s, p) => s + p.fixed, 0),
  };

  // Pro Person: gewichteter Anteil
  const results: ProfitShareResult[] = perPerson.map((p) => {
    const hoursShare = totals.hours > 0 ? p.hours / totals.hours : 0;
    const invShare = totals.inventory > 0 ? p.inventory / totals.inventory : 0;
    const capShare = totals.capital > 0 ? p.capital / totals.capital : 0;
    const fixedShare = totals.fixed > 0 ? p.fixed / totals.fixed : 0;

    const combinedShare =
      (hoursShare * weights.hours +
        invShare * weights.inventory +
        capShare * weights.capital +
        fixedShare * weights.fixed) /
      weightSum;

    return {
      profile_id: p.profile_id,
      name: p.name,
      hours_contribution: p.hours,
      inventory_contribution: p.inventory,
      capital_contribution: p.capital,
      fixed_amount: p.fixed,
      calculated_amount: Math.round(netProfit * combinedShare * 100) / 100,
      percentage: Math.round(combinedShare * 10000) / 100,
    };
  });

  return results;
}

/**
 * Zusammenfassung für das UI
 */
export function formatFormulaLabel(formula: ProfitFormula): string {
  const w = formula.weights || {};
  const parts: string[] = [];
  if (w.hours) parts.push(`${Math.round(w.hours * 100)}% Stunden`);
  if (w.inventory) parts.push(`${Math.round(w.inventory * 100)}% Inventar`);
  if (w.capital) parts.push(`${Math.round(w.capital * 100)}% Kapital`);
  if (w.fixed) parts.push(`${Math.round(w.fixed * 100)}% Festbetrag`);
  return parts.length > 0 ? parts.join(" · ") : "Gleichverteilung";
}
