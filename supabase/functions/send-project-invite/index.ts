// Supabase Edge Function: Projekt-Einladungs-Email via Custom SMTP
// Deno Runtime
//
// POST { invitation_id } → Lädt Einladung + Projekt + SMTP-Config → sendet Email

import { createClient } from "https://esm.sh/@supabase/supabase-js@2";
import { SMTPClient } from "https://deno.land/x/denomailer@1.6.0/mod.ts";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const SUPABASE_SERVICE_ROLE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const APP_URL = Deno.env.get("APP_URL") || "https://project-prepper.vercel.app";

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

function formatDate(dateStr: string | null): string {
  if (!dateStr) return "—";
  return new Date(dateStr).toLocaleDateString("de-DE", {
    weekday: "long",
    day: "2-digit",
    month: "long",
    year: "numeric",
  });
}

function buildEmailHtml(params: {
  projectName: string;
  projectDate: string | null;
  projectDateEnd: string | null;
  venueName: string | null;
  venueAddress: string | null;
  role: string;
  inviterName: string;
  orgName: string;
  projectUrl: string;
}): string {
  const { projectName, projectDate, projectDateEnd, venueName, venueAddress, role, inviterName, orgName, projectUrl } = params;

  const roleLabels: Record<string, string> = {
    owner: "Eigentümer",
    editor: "Bearbeiter",
    viewer: "Betrachter",
  };

  const dateRange = projectDate
    ? projectDateEnd && projectDateEnd !== projectDate
      ? `${formatDate(projectDate)} – ${formatDate(projectDateEnd)}`
      : formatDate(projectDate)
    : null;

  return `
<!DOCTYPE html>
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
          <h2 style="margin:0 0 8px;color:#18181b;font-size:20px;">Projekteinladung</h2>
          <p style="margin:0 0 24px;color:#71717a;font-size:14px;line-height:1.5;">
            <strong>${inviterName}</strong> hat dich zum Projekt eingeladen.
          </p>
          <table width="100%" style="background:#f9fafb;border-radius:8px;border:1px solid #e4e4e7;margin-bottom:24px;" cellpadding="0" cellspacing="0">
            <tr><td style="padding:20px;">
              <h3 style="margin:0 0 12px;color:#18181b;font-size:16px;">${projectName}</h3>
              <table cellpadding="0" cellspacing="0" style="font-size:13px;color:#52525b;">
                ${dateRange ? `<tr><td style="padding:2px 12px 2px 0;color:#a1a1aa;">Datum</td><td style="padding:2px 0;">${dateRange}</td></tr>` : ""}
                ${venueName ? `<tr><td style="padding:2px 12px 2px 0;color:#a1a1aa;">Ort</td><td style="padding:2px 0;">${venueName}${venueAddress ? `, ${venueAddress}` : ""}</td></tr>` : ""}
                <tr><td style="padding:2px 12px 2px 0;color:#a1a1aa;">Rolle</td><td style="padding:2px 0;">${roleLabels[role] || role}</td></tr>
                <tr><td style="padding:2px 12px 2px 0;color:#a1a1aa;">Organisation</td><td style="padding:2px 0;">${orgName}</td></tr>
              </table>
            </td></tr>
          </table>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center">
              <a href="${projectUrl}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 32px;border-radius:8px;font-size:14px;font-weight:600;">
                Zum Projekt
              </a>
            </td></tr>
          </table>
          <p style="margin:24px 0 0;color:#a1a1aa;font-size:12px;text-align:center;">
            Du kannst die Einladung auch in der App annehmen oder ablehnen.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>`.trim();
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") {
    return new Response("ok", { headers: corsHeaders });
  }

  try {
    const { invitation_id } = await req.json();
    if (!invitation_id) return json({ error: "invitation_id required" }, 400);

    const supabase = createClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY);

    // Einladung laden
    const { data: invitation, error: invError } = await supabase
      .from("project_invitations")
      .select(`
        id, project_id, role, invited_profile_id, invited_by,
        projects:project_id(name, date_start, date_end, venue_name, venue_address, org_id),
        inviter:invited_by(name),
        invitee:invited_profile_id(name, email)
      `)
      .eq("id", invitation_id)
      .single();

    if (invError || !invitation) return json({ error: "Invitation not found" }, 404);

    // deno-lint-ignore no-explicit-any
    const project = invitation.projects as any;
    // deno-lint-ignore no-explicit-any
    const inviter = invitation.inviter as any;
    // deno-lint-ignore no-explicit-any
    const invitee = invitation.invitee as any;

    if (!invitee?.email) return json({ error: "Invitee has no email", method: "skipped" }, 200);

    const orgId = project?.org_id;
    if (!orgId) return json({ error: "No org_id found", method: "skipped" }, 200);

    // SMTP-Config laden
    const { data: emailConfig } = await supabase
      .from("org_email_config")
      .select("*")
      .eq("org_id", orgId)
      .eq("is_enabled", true)
      .single();

    if (!emailConfig) {
      console.log("No SMTP config for org, skipping email");
      return json({ success: true, method: "skipped", message: "No SMTP configured" });
    }

    // Org-Name
    const { data: org } = await supabase.from("organizations").select("name").eq("id", orgId).single();

    const projectUrl = `${APP_URL}/projects/${invitation.project_id}`;

    const html = buildEmailHtml({
      projectName: project?.name || "Unbenanntes Projekt",
      projectDate: project?.date_start || null,
      projectDateEnd: project?.date_end || null,
      venueName: project?.venue_name || null,
      venueAddress: project?.venue_address || null,
      role: invitation.role,
      inviterName: inviter?.name || "Jemand",
      orgName: org?.name || "Team",
      projectUrl,
    });

    // TLS-Modus bestimmen
    const security = emailConfig.smtp_security || "starttls";
    const tls = security === "ssl";

    const client = new SMTPClient({
      connection: {
        hostname: emailConfig.smtp_host,
        port: emailConfig.smtp_port,
        tls,
        auth: {
          username: emailConfig.smtp_user,
          password: emailConfig.smtp_pass,
        },
      },
    });

    const sendOptions: Record<string, unknown> = {
      from: emailConfig.sender_name
        ? `${emailConfig.sender_name} <${emailConfig.sender_email}>`
        : emailConfig.sender_email,
      to: invitee.email,
      subject: `Einladung: ${project?.name || "Projekt"}`,
      content: "Du wurdest zu einem Projekt eingeladen.",
      html,
    };
    if (emailConfig.bcc_email) {
      sendOptions.bcc = emailConfig.bcc_email;
    }

    await client.send(sendOptions);
    await client.close();

    console.log(`Project invite email sent to ${invitee.email} for project ${project?.name}`);
    return json({ success: true, method: "email" });

  } catch (err) {
    console.error("Edge function error:", err);
    return json({ error: (err as Error).message }, 500);
  }
});
