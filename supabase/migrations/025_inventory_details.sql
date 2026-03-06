-- Migration 025: Erweiterte Inventar-Details
-- Neue Felder: Gerätebezeichnung, Seriennummer, Kaufpreis, Abmaße, Leistung, Zubehör, Freifeld

ALTER TABLE inventory_items
  ADD COLUMN IF NOT EXISTS device_name     text,          -- Gerätebezeichnung / Modell
  ADD COLUMN IF NOT EXISTS serial_number   text,          -- Seriennummer
  ADD COLUMN IF NOT EXISTS purchase_price  numeric(10,2), -- Kaufpreis (€)
  ADD COLUMN IF NOT EXISTS dimensions      text,          -- Abmaße (z.B. "60x40x30 cm")
  ADD COLUMN IF NOT EXISTS power_watts     integer,       -- Leistung in Watt
  ADD COLUMN IF NOT EXISTS accessories     text[],        -- Zubehör-Tags (Netzteil, Tasche, Kabel, Adapter, ...)
  ADD COLUMN IF NOT EXISTS custom_field    text;          -- Freifeld für sonstige Infos
