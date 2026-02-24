// Inventar-Seed basierend auf 2026_DunkelstromPlanung.xlsx
// Ausführen: npx tsx scripts/seed-inventory.ts

import { createClient } from "@supabase/supabase-js";

const supabase = createClient(
  process.env.NEXT_PUBLIC_SUPABASE_URL!,
  process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY!
);

const inventory = [
  // === PROJEKTION ===
  { name: "Kodak SAV 2050 Diaprojektor", category: "Projektion", quantity: 10, condition: "good", cost_per_day: 15, location: "Lager", description: "Slide-Projektor mit Kaltgerätestecker" },
  { name: "Housing Schaukel", category: "Projektion", quantity: 2, condition: "good", cost_per_day: 10, location: "Lager", description: "Gehäuse für Diaprojektor" },
  { name: "Housing Baumstamm", category: "Projektion", quantity: 2, condition: "good", cost_per_day: 10, location: "Lager", description: "Gehäuse für Diaprojektor" },
  { name: "Objektiv 60mm", category: "Projektion", quantity: 6, condition: "good", cost_per_day: 5, location: "Objektivrucksack", description: "60mm Objektiv für Kodak SAV" },
  { name: "Objektiv 100mm", category: "Projektion", quantity: 4, condition: "good", cost_per_day: 5, location: "Objektivrucksack", description: "100mm Objektiv für Kodak SAV" },
  { name: "Objektiv 70-120mm", category: "Projektion", quantity: 8, condition: "good", cost_per_day: 5, location: "Objektivrucksack", description: "70-120mm Objektiv für Kodak SAV" },
  { name: "Objektiv 60-110mm", category: "Projektion", quantity: 6, condition: "good", cost_per_day: 5, location: "Objektivrucksack", description: "60-110mm Objektiv für Kodak SAV" },
  { name: "Objektiv 25mm", category: "Projektion", quantity: 1, condition: "good", cost_per_day: 5, location: "Objektivrucksack", description: "25mm Weitwinkel-Objektiv" },
  { name: "Objektiv 15mm", category: "Projektion", quantity: 1, condition: "good", cost_per_day: 5, location: "Objektivrucksack", description: "15mm Weitwinkel-Objektiv" },
  { name: "Dia-Set Galaxy (Jan)", category: "Projektion", quantity: 1, condition: "good", cost_per_day: 5, location: "Lager", description: "Dia-Set Galaxie-Motive" },
  { name: "Dia-Set Strukturglas", category: "Projektion", quantity: 1, condition: "good", cost_per_day: 5, location: "Lager", description: "Dia-Set Strukturglas" },
  { name: "Dia-Set gemischt", category: "Projektion", quantity: 1, condition: "good", cost_per_day: 5, location: "Lager", description: "Diverse Dias gemischt" },
  { name: "Beamer Shortthrow", category: "Projektion", quantity: 1, condition: "good", cost_per_day: 30, location: "Lager", description: "Kurzdistanz-Beamer" },
  { name: "Beamer 5000 Lumen", category: "Projektion", quantity: 1, condition: "good", cost_per_day: 40, location: "Lager", description: "Hochleistungs-Beamer 5000lm" },
  { name: "Beamer (gut)", category: "Projektion", quantity: 4, condition: "good", cost_per_day: 25, location: "Lager", description: "Standard-Beamer" },

  // === LICHT ===
  { name: "LED Cube", category: "Licht", quantity: 6, condition: "good", cost_per_day: 8, location: "Lager", description: "LED Cube Lichteffekt" },
  { name: "LED Spotlight RGBWA", category: "Licht", quantity: 10, condition: "good", cost_per_day: 6, location: "Lager", description: "RGBWA LED Scheinwerfer" },
  { name: "RGBAW Spot", category: "Licht", quantity: 4, condition: "good", cost_per_day: 8, location: "Lager", description: "RGBAW LED Spot" },
  { name: "RGB Fluter Outdoor", category: "Licht", quantity: 12, condition: "good", cost_per_day: 5, location: "Lager", description: "Outdoor RGB Fluter" },
  { name: "UV Fluter", category: "Licht", quantity: 3, condition: "good", cost_per_day: 5, location: "Lager", description: "UV-Schwarzlicht Fluter" },
  { name: "Pinspot Halogen", category: "Licht", quantity: 3, condition: "fair", cost_per_day: 3, location: "Lager", description: "Halogen Pinspot" },
  { name: "Pinspot LED", category: "Licht", quantity: 1, condition: "good", cost_per_day: 4, location: "Lager", description: "LED Pinspot" },
  { name: "Lichtschlauch", category: "Licht", quantity: 8, condition: "good", cost_per_day: 3, location: "Lager", description: "LED Lichtschlauch" },
  { name: "LED Lichterkette 100m", category: "Licht", quantity: 1, condition: "good", cost_per_day: 10, location: "Lukas", description: "100m LED Lichterkette" },
  { name: "Gartenlaser", category: "Licht", quantity: 5, condition: "good", cost_per_day: 5, location: "Lager", description: "Outdoor Gartenlaser" },

  // === EFFEKTE ===
  { name: "Bubble Hazer", category: "Effekte", quantity: 1, condition: "good", cost_per_day: 15, location: "Lager", description: "Seifenblasen-Hazer" },
  { name: "Nebelmaschine", category: "Effekte", quantity: 1, condition: "good", cost_per_day: 10, location: "Lager", description: "Standard Nebelmaschine" },
  { name: "Diskokugel groß + Motor", category: "Effekte", quantity: 1, condition: "good", cost_per_day: 8, location: "Lager", description: "Große Spiegelkugel mit Motor" },
  { name: "Diskokugel klein", category: "Effekte", quantity: 1, condition: "fair", cost_per_day: 3, location: "Lager", description: "Kleine Diskokugel (Ersatz)" },
  { name: "Diskokugelmotor", category: "Effekte", quantity: 1, condition: "good", cost_per_day: 3, location: "Lager", description: "Separater Motor für Diskokugel" },

  // === STEUERUNG / DMX ===
  { name: "Enttec USB DMX Interface", category: "Steuerung", quantity: 1, condition: "good", cost_per_day: 8, location: "Lager", description: "USB zu DMX Interface" },
  { name: "DMX Switch", category: "Steuerung", quantity: 1, condition: "good", cost_per_day: 5, location: "Lager", description: "DMX Switch/Splitter" },
  { name: "DMX Dimmer", category: "Steuerung", quantity: 1, condition: "good", cost_per_day: 5, location: "Lager", description: "DMX Dimmer" },
  { name: "Lichtpult (klein)", category: "Steuerung", quantity: 1, condition: "good", cost_per_day: 10, location: "Lager", description: "Kleines Lichtmischpult" },

  // === VIDEO ===
  { name: "Videomischer", category: "Video", quantity: 1, condition: "good", cost_per_day: 20, location: "Lager", description: "Video-Mischer" },
  { name: "AV Wandler", category: "Video", quantity: 1, condition: "good", cost_per_day: 5, location: "Lager", description: "Audio/Video Signalwandler" },
  { name: "Mini Screen", category: "Video", quantity: 1, condition: "good", cost_per_day: 10, location: "Lager", description: "Kleiner Monitor/Screen" },
  { name: "HDMI Kabeltrommel", category: "Video", quantity: 2, condition: "good", cost_per_day: 5, location: "Lager", description: "HDMI Kabel auf Trommel" },

  // === AUDIO ===
  { name: "PA / Soundsystem", category: "Audio", quantity: 1, condition: "good", cost_per_day: 40, location: "Lager", description: "Musikanlage / PA-System" },

  // === KABEL & STROM ===
  { name: "DMX Kabel 5m", category: "Kabel", quantity: 5, condition: "good", cost_per_day: 1, location: "Lager", description: "DMX Kabel 5 Meter" },
  { name: "DMX Kabel 10m", category: "Kabel", quantity: 4, condition: "good", cost_per_day: 1, location: "Lager", description: "DMX Kabel 10 Meter" },
  { name: "DMX Kabel diverse", category: "Kabel", quantity: 15, condition: "good", cost_per_day: 1, location: "Lager", description: "DMX Kabel verschiedene Längen" },
  { name: "Schuko Verlängerung 5m", category: "Kabel", quantity: 5, condition: "good", cost_per_day: 1, location: "Lager", description: "Schukostecker-Verlängerung 5m" },
  { name: "Schuko Verlängerung 10m", category: "Kabel", quantity: 2, condition: "good", cost_per_day: 1, location: "Lager", description: "Schukostecker-Verlängerung 10m" },
  { name: "Schuko Verlängerung 15m", category: "Kabel", quantity: 2, condition: "good", cost_per_day: 1, location: "Lager", description: "Schukostecker-Verlängerung 15m" },
  { name: "Schuko Verlängerung 20m", category: "Kabel", quantity: 1, condition: "good", cost_per_day: 2, location: "Lager", description: "Schukostecker-Verlängerung 20m" },
  { name: "Outdoor Verlängerung 25m", category: "Kabel", quantity: 2, condition: "good", cost_per_day: 3, location: "Lager", description: "Outdoor Verlängerungskabel 25m" },
  { name: "Cat-Kabel 30m", category: "Kabel", quantity: 1, condition: "good", cost_per_day: 2, location: "Lager", description: "Netzwerkkabel 30m" },
  { name: "Cat-Brücke", category: "Kabel", quantity: 3, condition: "good", cost_per_day: 1, location: "Privat", description: "Cat-Kabel Brücke" },
  { name: "3er Stromverteiler", category: "Kabel", quantity: 6, condition: "good", cost_per_day: 1, location: "Lager", description: "Schuko 3er Verteilung" },
  { name: "STR Block / Adapter", category: "Kabel", quantity: 2, condition: "good", cost_per_day: 2, location: "Lager", description: "STR Adapter Block" },
  { name: "Kaltgerätestecker", category: "Kabel", quantity: 10, condition: "good", cost_per_day: 0.5, location: "Lager", description: "Kaltgerätestecker/Kabel" },

  // === RIGGING & ZUBEHÖR ===
  { name: "Manfrotto Klemme", category: "Rigging", quantity: 5, condition: "good", cost_per_day: 2, location: "Lager", description: "Manfrotto Super Clamp" },
  { name: "Spanngurt", category: "Rigging", quantity: 4, condition: "good", cost_per_day: 1, location: "Lager", description: "Spanngurt/Ratsche" },
  { name: "Safety", category: "Rigging", quantity: 10, condition: "good", cost_per_day: 0.5, location: "Lager", description: "Sicherungsseil / Safety Cable" },
  { name: "Transportkiste groß", category: "Transport", quantity: 3, condition: "good", cost_per_day: 3, location: "Lager", description: "Transportkiste diverse Größen" },
  { name: "Transportkiste klein", category: "Transport", quantity: 5, condition: "good", cost_per_day: 2, location: "Lager", description: "Kleinere Transportkisten" },
  { name: "Stativ", category: "Rigging", quantity: 4, condition: "good", cost_per_day: 3, location: "Lager", description: "Licht-/Projektor-Stativ" },
  { name: "Werkzeug-Set", category: "Zubehör", quantity: 1, condition: "good", cost_per_day: 5, location: "Lager", description: "Werkzeugkiste für Aufbau" },
];

async function seed() {
  console.log(`Füge ${inventory.length} Inventar-Artikel ein...`);

  const { data, error } = await supabase
    .from("inventory_items")
    .insert(inventory)
    .select();

  if (error) {
    console.error("Fehler:", error.message);
    return;
  }

  console.log(`✅ ${data.length} Artikel erfolgreich eingefügt!`);

  // Zusammenfassung nach Kategorie
  const categories: Record<string, number> = {};
  inventory.forEach((item) => {
    categories[item.category] = (categories[item.category] || 0) + 1;
  });
  console.log("\nNach Kategorie:");
  Object.entries(categories).forEach(([cat, count]) => {
    console.log(`  ${cat}: ${count} Artikel`);
  });
}

seed();
