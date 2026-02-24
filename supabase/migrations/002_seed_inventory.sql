-- =============================================
-- INVENTAR SEED - Dunkelstrom Equipment
-- Aus 2026_DunkelstromPlanung.xlsx extrahiert
-- Führe im Supabase SQL Editor aus
-- =============================================

INSERT INTO public.inventory_items (name, category, quantity, condition, cost_per_day, location, description) VALUES
-- PROJEKTION
('Kodak SAV 2050 Diaprojektor', 'Projektion', 10, 'good', 15.00, 'Lager', 'Slide-Projektor mit Kaltgerätestecker'),
('Housing Schaukel', 'Projektion', 2, 'good', 10.00, 'Lager', 'Gehäuse für Diaprojektor'),
('Housing Baumstamm', 'Projektion', 2, 'good', 10.00, 'Lager', 'Gehäuse für Diaprojektor'),
('Objektiv 60mm', 'Projektion', 6, 'good', 5.00, 'Objektivrucksack', '60mm Objektiv für Kodak SAV'),
('Objektiv 100mm', 'Projektion', 4, 'good', 5.00, 'Objektivrucksack', '100mm Objektiv für Kodak SAV'),
('Objektiv 70-120mm', 'Projektion', 8, 'good', 5.00, 'Objektivrucksack', '70-120mm Objektiv für Kodak SAV'),
('Objektiv 60-110mm', 'Projektion', 6, 'good', 5.00, 'Objektivrucksack', '60-110mm Objektiv für Kodak SAV'),
('Objektiv 25mm', 'Projektion', 1, 'good', 5.00, 'Objektivrucksack', '25mm Weitwinkel-Objektiv'),
('Objektiv 15mm', 'Projektion', 1, 'good', 5.00, 'Objektivrucksack', '15mm Weitwinkel-Objektiv'),
('Dia-Set Galaxy', 'Projektion', 1, 'good', 5.00, 'Lager', 'Dia-Set Galaxie-Motive'),
('Dia-Set Strukturglas', 'Projektion', 1, 'good', 5.00, 'Lager', 'Dia-Set Strukturglas'),
('Dia-Set gemischt', 'Projektion', 1, 'good', 5.00, 'Lager', 'Diverse Dias gemischt'),
('Beamer Shortthrow', 'Projektion', 1, 'good', 30.00, 'Lager', 'Kurzdistanz-Beamer'),
('Beamer 5000 Lumen', 'Projektion', 1, 'good', 40.00, 'Lager', 'Hochleistungs-Beamer 5000lm'),
('Beamer (Standard)', 'Projektion', 4, 'good', 25.00, 'Lager', 'Standard-Beamer'),

-- LICHT
('LED Cube', 'Licht', 6, 'good', 8.00, 'Lager', 'LED Cube Lichteffekt'),
('LED Spotlight RGBWA', 'Licht', 10, 'good', 6.00, 'Lager', 'RGBWA LED Scheinwerfer'),
('RGBAW Spot', 'Licht', 4, 'good', 8.00, 'Lager', 'RGBAW LED Spot'),
('RGB Fluter Outdoor', 'Licht', 12, 'good', 5.00, 'Lager', 'Outdoor RGB LED Fluter'),
('UV Fluter', 'Licht', 3, 'good', 5.00, 'Lager', 'UV-Schwarzlicht Fluter'),
('Pinspot Halogen', 'Licht', 3, 'fair', 3.00, 'Lager', 'Halogen Pinspot'),
('Pinspot LED', 'Licht', 1, 'good', 4.00, 'Lager', 'LED Pinspot'),
('Lichtschlauch', 'Licht', 8, 'good', 3.00, 'Lager', 'LED Lichtschlauch'),
('LED Lichterkette 100m', 'Licht', 1, 'good', 10.00, 'Lukas', '100m LED Lichterkette'),
('Gartenlaser', 'Licht', 5, 'good', 5.00, 'Lager', 'Outdoor Gartenlaser'),

-- EFFEKTE
('Bubble Hazer', 'Effekte', 1, 'good', 15.00, 'Lager', 'Seifenblasen-Hazer'),
('Nebelmaschine', 'Effekte', 1, 'good', 10.00, 'Lager', 'Standard Nebelmaschine'),
('Diskokugel groß + Motor', 'Effekte', 1, 'good', 8.00, 'Lager', 'Große Spiegelkugel mit Motor'),
('Diskokugel klein', 'Effekte', 1, 'fair', 3.00, 'Lager', 'Kleine Diskokugel (Ersatz)'),

-- STEUERUNG / DMX
('Enttec USB DMX Interface', 'Steuerung', 1, 'good', 8.00, 'Lager', 'USB zu DMX Interface'),
('DMX Switch', 'Steuerung', 1, 'good', 5.00, 'Lager', 'DMX Switch/Splitter'),
('DMX Dimmer', 'Steuerung', 1, 'good', 5.00, 'Lager', 'DMX Dimmer'),
('Lichtpult (klein)', 'Steuerung', 1, 'good', 10.00, 'Lager', 'Kleines Lichtmischpult'),

-- VIDEO
('Videomischer', 'Video', 1, 'good', 20.00, 'Lager', 'Video-Mischer'),
('AV Wandler', 'Video', 1, 'good', 5.00, 'Lager', 'Audio/Video Signalwandler'),
('Mini Screen', 'Video', 1, 'good', 10.00, 'Lager', 'Kleiner Monitor/Screen'),
('HDMI Kabeltrommel', 'Video', 2, 'good', 5.00, 'Lager', 'HDMI Kabel auf Trommel'),

-- AUDIO
('PA / Soundsystem', 'Audio', 1, 'good', 40.00, 'Lager', 'Musikanlage / PA-System'),

-- KABEL & STROM
('DMX Kabel 5m', 'Kabel', 5, 'good', 1.00, 'Lager', 'DMX Kabel 5 Meter'),
('DMX Kabel 10m', 'Kabel', 4, 'good', 1.00, 'Lager', 'DMX Kabel 10 Meter'),
('DMX Kabel diverse', 'Kabel', 15, 'good', 1.00, 'Lager', 'DMX Kabel verschiedene Längen'),
('Schuko Verlängerung 5m', 'Kabel', 5, 'good', 1.00, 'Lager', 'Schukostecker-Verlängerung 5m'),
('Schuko Verlängerung 10m', 'Kabel', 2, 'good', 1.00, 'Lager', 'Schukostecker-Verlängerung 10m'),
('Schuko Verlängerung 15m', 'Kabel', 2, 'good', 1.00, 'Lager', 'Schukostecker-Verlängerung 15m'),
('Schuko Verlängerung 20m', 'Kabel', 1, 'good', 2.00, 'Lager', 'Schukostecker-Verlängerung 20m'),
('Outdoor Verlängerung 25m', 'Kabel', 2, 'good', 3.00, 'Lager', 'Outdoor Verlängerungskabel 25m'),
('Cat-Kabel 30m', 'Kabel', 1, 'good', 2.00, 'Lager', 'Netzwerkkabel 30m'),
('3er Stromverteiler', 'Kabel', 6, 'good', 1.00, 'Lager', 'Schuko 3er Verteilung'),
('STR Block / Adapter', 'Kabel', 2, 'good', 2.00, 'Lager', 'STR Adapter Block'),
('Kaltgerätestecker', 'Kabel', 10, 'good', 0.50, 'Lager', 'Kaltgerätestecker/Kabel'),

-- RIGGING & ZUBEHÖR
('Manfrotto Klemme', 'Rigging', 5, 'good', 2.00, 'Lager', 'Manfrotto Super Clamp'),
('Spanngurt', 'Rigging', 4, 'good', 1.00, 'Lager', 'Spanngurt/Ratsche'),
('Safety Cable', 'Rigging', 10, 'good', 0.50, 'Lager', 'Sicherungsseil'),
('Stativ', 'Rigging', 4, 'good', 3.00, 'Lager', 'Licht-/Projektor-Stativ'),

-- TRANSPORT
('Transportkiste groß', 'Transport', 3, 'good', 3.00, 'Lager', 'Transportkiste diverse Größen'),
('Transportkiste klein', 'Transport', 5, 'good', 2.00, 'Lager', 'Kleinere Transportkisten'),
('Werkzeug-Set', 'Zubehör', 1, 'good', 5.00, 'Lager', 'Werkzeugkiste für Aufbau');
