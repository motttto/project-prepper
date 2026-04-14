// Supabase Edge Function: Partnership-Einladungs-Email via Custom SMTP
// ======================================================================
// Sendet eine Partnership-Einladung per E-Mail über org_email_config der
// einladenden Org. Empfänger klickt Link → /partner-invite?token=<id>.
//
// POST { invitation_id } → Lädt Einladung + Org + SMTP-Config → sendet E-Mail

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

function buildEmailHtml(p: {
  orgName: string;
  inviterName: string;
  shareInventory: boolean;
  shareTeamContacts: boolean;
  acceptUrl: string;
  notes: string | null;
}): string {
  const sharingLines: string[] = [];
  if (p.shareInventory) sharingLines.push("gegenseitige Sichtbarkeit des Inventars");
  if (p.shareTeamContacts) sharingLines.push("Austausch von Team-Kontakten");
  const sharingText = sharingLines.length > 0
    ? sharingLines.join(" und ")
    : "eine unverbindliche Verknüpfung";

  return `<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:40px 20px;">
    <tr><td align="center">
      <table width="100%" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        <tr><td style="background:#18181b;padding:24px 32px;">
          <h1 style="margin:0;color:#ffffff;font-size:18px;font-weight:600;">Project Prepper</h1>
        </td></tr>
        <tr><td style="padding:32px;">
          <h2 style="margin:0 0 8px;color:#18181b;font-size:20px;">Partnerschaftsanfrage</h2>
          <p style="margin:0 0 24px;color:#71717a;font-size:14px;line-height:1.5;">
            <strong>${p.orgName}</strong> möchte mit deiner Organisation zusammenarbeiten.
            ${p.inviterName ? `Eingeladen von <strong>${p.inviterName}</strong>.` : ""}
          </p>
          <table width="100%" style="background:#f9fafb;border-radius:8px;border:1px solid #e4e4e7;margin-bottom:24px;" cellpadding="0" cellspacing="0">
            <tr><td style="padding:20px;">
              <h3 style="margin:0 0 12px;color:#18181b;font-size:15px;">Umfang der Partnerschaft</h3>
              <p style="margin:0;font-size:13px;color:#52525b;line-height:1.6;">
                Nach deiner Zustimmung wird eingerichtet: <strong>${sharingText}</strong>.
              </p>
              ${p.notes ? `<p style="margin:12px 0 0;font-size:13px;color:#52525b;font-style:italic;">„${p.notes}"</p>` : ""}
            </td></tr>
          </table>
          <p style="margin:0 0 12px;color:#71717a;font-size:13px;line-height:1.5;">
            Voraussetzung: Du hast bereits ein Project-Prepper-Konto mit eigener Organisation.
            Sonst registrierst du dich kurz, legst eine Org an und kommst über den Link zurück.
          </p>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center">
              <a href="${p.acceptUrl}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 32px;border-radius:8px;font-size:14px;font-weight:600;">Anfrage prüfen</a>
            </td></tr>
          </table>
          <p style="margin:24px 0 0;color:#a1a1aa;font-size:12px;text-align:center;">
            Diese Einladung läuft in 30 Tagen ab. Keine Zustimmung = keine Datenfreigabe.
          </p>
        </td></tr>
      </table>
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

    // Einladung + einladende Org + Inviter-Name laden
    const { data: invitation, error: invError } = await supabase
      .from("partnership_invitations")
      .select(`
        id, email, share_inventory, share_team_contacts, notes, org_id,
        organizations:org_id(name),
        inviter:invited_by(name)
      `)
      .eq("id", invitation_id)
      .single();

    if (invError || !invitation) return json({ error: "Invitation not found" }, 404);

    // deno-lint-ignore no-explicit-any
    const org = invitation.organizations as any;
    // deno-lint-ignore no-explicit-any
    const inviter = invitation.inviter as any;

    // SMTP-Config der einladenden Org
    const { data: emailConfig } = await supabase
      .from("org_email_config")
      .select("*")
      .eq("org_id", invitation.org_id)
      .eq("is_enabled", true)
      .single();

    if (!emailConfig) {
      return json({ success: false, error: "Keine SMTP-Konfiguration für die einladende Org" }, 400);
    }

    const acceptUrl = `${APP_URL}/partner-invite?token=${invitation.id}`;

    const html = buildEmailHtml({
      orgName: org?.name || "Ein Team",
      inviterName: inviter?.name || "",
      shareInventory: invitation.share_inventory,
      shareTeamContacts: invitation.share_team_contacts,
      acceptUrl,
      notes: invitation.notes || null,
    });

    const security = emailConfig.smtp_security || "starttls";
    const transport = nodemailer.createTransport({
      host: emailConfig.smtp_host,
      port: emailConfig.smtp_port,
      secure: security === "ssl",
      auth: { user: emailConfig.smtp_user, pass: emailConfig.smtp_pass },
      tls: { rejectUnauthorized: false },
    });

    // deno-lint-ignore no-explicit-any
    const mailOptions: any = {
      from: emailConfig.sender_name
        ? `"${emailConfig.sender_name}" <${emailConfig.sender_email}>`
        : emailConfig.sender_email,
      to: invitation.email,
      subject: `Partnerschaftsanfrage von ${org?.name || "einem Team"}`,
      text: `${org?.name || "Ein Team"} möchte mit dir zusammenarbeiten. Link: ${acceptUrl}`,
      html,
    };
    if (emailConfig.bcc_email) mailOptions.bcc = emailConfig.bcc_email;

    await transport.sendMail(mailOptions);

    console.log(`Partnership invite sent to ${invitation.email} from org ${invitation.org_id}`);
    return json({ success: true, method: "email" });

  } catch (err) {
    console.error("Edge function error:", err);
    return json({ error: (err as Error).message }, 500);
  }
});
