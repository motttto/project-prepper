-- Inventarnummer für jeden Artikel
ALTER TABLE inventory_items
ADD COLUMN inventory_number TEXT UNIQUE;

-- Bestehende Artikel mit Inventarnummern versehen
-- Format: Kategorie-Kürzel + laufende Nummer (z.B. PRO-001, LIC-002)
DO $$
DECLARE
  r RECORD;
  prefix TEXT;
  counter INTEGER;
  cat TEXT;
  last_cat TEXT := '';
BEGIN
  FOR r IN
    SELECT id, category
    FROM inventory_items
    ORDER BY category, created_at
  LOOP
    -- Kürzel aus Kategorie ableiten
    CASE r.category
      WHEN 'Projektion' THEN prefix := 'PRO';
      WHEN 'Licht'      THEN prefix := 'LIC';
      WHEN 'Effekte'    THEN prefix := 'EFF';
      WHEN 'Steuerung'  THEN prefix := 'STR';
      WHEN 'Video'      THEN prefix := 'VID';
      WHEN 'Audio'      THEN prefix := 'AUD';
      WHEN 'Kabel'      THEN prefix := 'KAB';
      WHEN 'Rigging'    THEN prefix := 'RIG';
      WHEN 'Transport'  THEN prefix := 'TRA';
      WHEN 'Zubehör'    THEN prefix := 'ZUB';
      ELSE prefix := UPPER(LEFT(r.category, 3));
    END CASE;

    IF r.category != last_cat THEN
      counter := 0;
      last_cat := r.category;
    END IF;

    counter := counter + 1;

    UPDATE inventory_items
    SET inventory_number = prefix || '-' || LPAD(counter::TEXT, 3, '0')
    WHERE id = r.id;
  END LOOP;
END $$;

-- NOT NULL setzen nachdem alle Werte befüllt sind
ALTER TABLE inventory_items
ALTER COLUMN inventory_number SET NOT NULL;
