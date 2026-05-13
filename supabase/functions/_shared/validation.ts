// Minimal-Validator-Helper fuer Edge Functions.
// Wird per relativem Import (`../_shared/validation.ts`) eingebunden.
//
// Zweck: konsistente Eingabe-Validierung statt 1-zeiligen Falsy-Checks.
// Bewusst ohne externe Lib (zod & Co.) damit Deno-Runtime nicht
// zusaetzlich laedt — Edge Functions sollen cold-start-billig bleiben.

const UUID_REGEX =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// RFC 5322 light — fuer User-Input gut genug, finale Validation ueber SMTP.
const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export class ValidationError extends Error {
  field: string;
  constructor(field: string, message: string) {
    super(`${field}: ${message}`);
    this.field = field;
  }
}

export function requireString(value: unknown, field: string, maxLen = 4000): string {
  if (typeof value !== "string") throw new ValidationError(field, "muss ein String sein");
  if (value.length === 0) throw new ValidationError(field, "darf nicht leer sein");
  if (value.length > maxLen) throw new ValidationError(field, `max ${maxLen} Zeichen`);
  return value;
}

export function requireUuid(value: unknown, field: string): string {
  const s = requireString(value, field, 36);
  if (!UUID_REGEX.test(s)) throw new ValidationError(field, "ist keine gueltige UUID");
  return s.toLowerCase();
}

export function optionalUuid(value: unknown, field: string): string | undefined {
  if (value == null || value === "") return undefined;
  return requireUuid(value, field);
}

export function requireEmail(value: unknown, field: string): string {
  const s = requireString(value, field, 320);
  if (!EMAIL_REGEX.test(s)) throw new ValidationError(field, "ist keine gueltige Email");
  return s.toLowerCase();
}

export function optionalEmail(value: unknown, field: string): string | undefined {
  if (value == null || value === "") return undefined;
  return requireEmail(value, field);
}

/** Helfer-Wrapper: ruft Validator auf und mappt Fehler auf 400 JSON. */
export function validate<T>(fn: () => T): T {
  try {
    return fn();
  } catch (e) {
    if (e instanceof ValidationError) throw e;
    throw new ValidationError("input", e instanceof Error ? e.message : "ungueltige Eingabe");
  }
}
