// Supabase Edge Function: SMTP-Verbindungstest
// Deno Runtime — nutzt nodemailer via esm.sh (breiteste Kompatibilität)
//
// POST { org_id, test_email? } → Lädt SMTP-Config → Sendet Test-Email

import { createClient } from "https://esm.sh/@supabase/supabase-js@2";
import nodemailer from "https://esm.sh/nodemailer@6.9.10";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const SUPABASE_SERVICE_ROLE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;

const corsHeaders = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
};

function json(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { ...corsHeaders, "Content-Type": "application/json" },
  });
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") {
    return new Response("ok", { headers: corsHeaders });
  }

  try {
    const { org_id, test_email } = await req.json();
    if (!org_id) return json({ error: "org_id required" }, 400);

    const supabase = createClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY);

    const { data: emailConfig, error: configError } = await supabase
      .from("org_email_config")
      .select("*")
      .eq("org_id", org_id)
      .single();

    if (configError || !emailConfig) return json({ error: "Keine SMTP-Konfiguration gefunden" }, 404);
    if (!emailConfig.smtp_host || !emailConfig.smtp_user) return json({ error: "SMTP-Konfiguration unvollständig" }, 400);

    const recipientEmail = test_email || emailConfig.sender_email;
    if (!recipientEmail) return json({ error: "Keine Test-Email-Adresse angegeben" }, 400);

    const { data: org } = await supabase.from("organizations").select("name").eq("id", org_id).single();
    const orgName = org?.name || "Organisation";

    // Nodemailer Transport erstellen
    const security = emailConfig.smtp_security || "starttls";
    const transport = nodemailer.createTransport({
      host: emailConfig.smtp_host,
      port: emailConfig.smtp_port,
      secure: security === "ssl", // true = implicit TLS (465), false = STARTTLS (587)
      auth: {
        user: emailConfig.smtp_user,
        pass: emailConfig.smtp_pass,
      },
      tls: {
        rejectUnauthorized: false, // Selbstsignierte Zertifikate erlauben
      },
    });

    await transport.sendMail({
      from: emailConfig.sender_name
        ? `"${emailConfig.sender_name}" <${emailConfig.sender_email}>`
        : emailConfig.sender_email,
      to: recipientEmail,
      subject: `[${orgName}] SMTP-Test erfolgreich`,
      text: `Diese Test-Email bestätigt, dass die SMTP-Konfiguration für ${orgName} in Project Prepper korrekt eingerichtet ist.`,
      html: `
<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:40px 20px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <table width="100%" style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
    <tr><td style="background:#22c55e;padding:20px 32px;">
      <h1 style="margin:0;color:#ffffff;font-size:16px;">SMTP-Test erfolgreich</h1>
    </td></tr>
    <tr><td style="padding:24px 32px;">
      <p style="margin:0 0 8px;color:#18181b;font-size:14px;">
        Die SMTP-Konfiguration für <strong>${orgName}</strong> funktioniert.
      </p>
      <p style="margin:0;color:#71717a;font-size:13px;">
        Projekt-Einladungen werden ab jetzt per Email versendet.
      </p>
    </td></tr>
  </table>
</body>
</html>`,
    });

    console.log(`Test email sent to ${recipientEmail} for org ${orgName}`);
    return json({ success: true, message: `Test-Email an ${recipientEmail} gesendet` });

  } catch (err) {
    console.error("SMTP test error:", err);
    const message = (err as Error).message || String(err);
    if (message.includes("ECONNREFUSED") || message.includes("ETIMEDOUT") || message.includes("connect")) {
      return json({ error: "Verbindung zum SMTP-Server fehlgeschlagen. Prüfe Host, Port und Sicherheitseinstellung." }, 500);
    }
    if (message.includes("auth") || message.includes("535") || message.includes("Invalid login")) {
      return json({ error: "Anmeldung fehlgeschlagen. Prüfe Benutzername und Passwort." }, 500);
    }
    return json({ error: `SMTP-Fehler: ${message}` }, 500);
  }
});
