// Supabase Edge Function: Group-Einladungs-Email
// ================================================
// POST { invitation_id }
// - Laedt Einladung + Gruppe + Inviter
// - Sucht SMTP-Config des Inviters (org_email_config.owner_profile_id = inviter.id)
// - Sendet HTML-Email an invited_email
// - Funktioniert sowohl fuer existierende User (Hinweis aufs Dashboard)
//   als auch fuer Nicht-User (Hinweis sich zu registrieren)

import { createClient } from "https://esm.sh/@supabase/supabase-js@2";
import nodemailer from "https://esm.sh/nodemailer@6.9.10";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const SUPABASE_SERVICE_ROLE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const APP_URL = Deno.env.get("APP_URL") || "https://project-prepper.dunkelstrom.net";

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

function buildHtml(p: {
  groupName: string;
  inviterName: string;
  message: string | null;
  appUrl: string;
  ctaUrl: string;
  isExistingUser: boolean;
}): string {
  return `<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:40px 20px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <table width="100%" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
    <tr><td style="background:#18181b;padding:20px 32px;">
      <h1 style="margin:0;color:#ffffff;font-size:18px;">Project Prepper</h1>
    </td></tr>
    <tr><td style="padding:32px;">
      <h2 style="margin:0 0 12px;color:#18181b;font-size:20px;">Du wurdest in eine Gruppe eingeladen</h2>
      <p style="margin:0 0 16px;color:#52525b;font-size:14px;line-height:1.5;">
        <strong>${p.inviterName}</strong> moechte dich in die Gruppe
        <strong>&quot;${p.groupName}&quot;</strong> einladen.
      </p>
      ${p.message ? `<div style="padding:12px;background:#f9fafb;border-left:3px solid #2563eb;border-radius:4px;margin-bottom:20px;color:#52525b;font-size:14px;font-style:italic;">${p.message}</div>` : ""}

      <h3 style="margin:24px 0 8px;color:#18181b;font-size:14px;">So geht's weiter:</h3>
      ${
        p.isExistingUser
          ? `<ol style="color:#52525b;font-size:14px;line-height:1.6;padding-left:20px;margin:0 0 20px;">
               <li>Logge dich auf Project Prepper ein</li>
               <li>Auf dem Dashboard erscheint die Einladung</li>
               <li>Klicke <strong>Akzeptieren</strong> — danach stimmen die bestehenden Mitglieder ueber deinen Beitritt ab</li>
               <li>Bei einstimmiger Zustimmung wirst du Mitglied der Gruppe</li>
             </ol>`
          : `<ol style="color:#52525b;font-size:14px;line-height:1.6;padding-left:20px;margin:0 0 20px;">
               <li>Registriere dich auf Project Prepper mit dieser E-Mail-Adresse</li>
               <li>Durchlaufe das kurze Onboarding</li>
               <li>Auf dem Dashboard findest du die Einladung</li>
               <li>Akzeptiere sie — danach stimmen die bestehenden Mitglieder ueber deinen Beitritt ab</li>
             </ol>`
      }

      <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
        <tr><td align="center">
          <a href="${p.ctaUrl}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 32px;border-radius:8px;font-size:14px;font-weight:600;">
            ${p.isExistingUser ? "Zum Dashboard" : "Jetzt registrieren"}
          </a>
        </td></tr>
      </table>

      <p style="margin:24px 0 0;color:#a1a1aa;font-size:12px;line-height:1.5;text-align:center;">
        Du erhaeltst diese Email weil ${p.inviterName} dich in die Gruppe &quot;${p.groupName}&quot;
        eingeladen hat. Wenn das ein Versehen war, kannst du diese Email ignorieren.
      </p>
    </td></tr>
  </table>
</body>
</html>`;
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") {
    return new Response("ok", { headers: corsHeaders });
  }

  try {
    const { invitation_id } = await req.json();
    if (!invitation_id) return json({ error: "invitation_id required" }, 400);

    const supabase = createClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY);

    // Einladung + Gruppe + Inviter laden
    const { data: inv, error: invError } = await supabase
      .from("group_invitations")
      .select(`
        id, invited_email, invited_profile_id, invited_message, invited_by,
        group:groups(id, name),
        inviter:profiles!invited_by(id, name, email)
      `)
      .eq("id", invitation_id)
      .maybeSingle();

    if (invError || !inv) return json({ error: "Invitation not found" }, 404);

    // deno-lint-ignore no-explicit-any
    const group = inv.group as any;
    // deno-lint-ignore no-explicit-any
    const inviter = inv.inviter as any;

    if (!inv.invited_email) return json({ success: true, method: "skipped", message: "Keine Email" });

    // SMTP-Config des Inviters laden
    const { data: emailConfig } = await supabase
      .from("org_email_config")
      .select("*")
      .eq("owner_profile_id", inviter?.id)
      .maybeSingle();

    if (!emailConfig || !emailConfig.is_enabled) {
      return json({
        success: true,
        method: "skipped",
        message: "Inviter hat keine SMTP-Config — Einladung nur in App sichtbar",
      });
    }

    // Pruefen ob User existiert
    const isExistingUser = !!inv.invited_profile_id;

    const ctaUrl = isExistingUser
      ? `${APP_URL}/dashboard`
      : `${APP_URL}/login?mode=register&email=${encodeURIComponent(inv.invited_email)}`;

    const html = buildHtml({
      groupName: group?.name || "Gruppe",
      inviterName: inviter?.name || "Jemand",
      message: inv.invited_message,
      appUrl: APP_URL,
      ctaUrl,
      isExistingUser,
    });

    const security = emailConfig.smtp_security || "starttls";
    const transport = nodemailer.createTransport({
      host: emailConfig.smtp_host,
      port: emailConfig.smtp_port,
      secure: security === "ssl",
      auth: { user: emailConfig.smtp_user, pass: emailConfig.smtp_pass },
      tls: { rejectUnauthorized: false },
    });

    const mailOptions: Record<string, unknown> = {
      from: emailConfig.sender_name
        ? `"${emailConfig.sender_name}" <${emailConfig.sender_email}>`
        : emailConfig.sender_email,
      to: inv.invited_email,
      subject: `Einladung: Gruppe "${group?.name || "Project Prepper"}"`,
      text: `${inviter?.name} hat dich in die Gruppe "${group?.name}" eingeladen. Login auf ${APP_URL}`,
      html,
    };
    if (emailConfig.bcc_email) mailOptions.bcc = emailConfig.bcc_email;

    await transport.sendMail(mailOptions);

    console.log(`Group invite email sent to ${inv.invited_email} for group ${group?.name}`);
    return json({ success: true, method: "email" });
  } catch (err) {
    console.error("Edge function error:", err);
    return json({ error: (err as Error).message }, 500);
  }
});
