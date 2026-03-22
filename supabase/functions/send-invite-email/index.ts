// Supabase Edge Function: Einladungs-Email via Resend
// Deno Runtime — URL-Imports
//
// POST { invitation_id } → Sendet Einladungs-Email an eingeladene Person

import { createClient } from "https://esm.sh/@supabase/supabase-js@2";

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const SUPABASE_SERVICE_ROLE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const RESEND_API_KEY = Deno.env.get("RESEND_API_KEY")!;
const APP_URL = Deno.env.get("APP_URL") || "https://project-prepper.vercel.app";

const corsHeaders = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
};

function getServiceClient() {
  return createClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY);
}

Deno.serve(async (req) => {
  // CORS Preflight
  if (req.method === "OPTIONS") {
    return new Response("ok", { headers: corsHeaders });
  }

  try {
    const { invitation_id } = await req.json();
    if (!invitation_id) {
      return new Response(
        JSON.stringify({ error: "invitation_id required" }),
        { status: 400, headers: { ...corsHeaders, "Content-Type": "application/json" } }
      );
    }

    const supabase = getServiceClient();

    // Einladung + Org + Einlader laden
    const { data: invitation, error: invError } = await supabase
      .from("org_invitations")
      .select(`
        id, email, role_id, status,
        organizations:org_id(name),
        profiles:invited_by(name),
        roles:role_id(name)
      `)
      .eq("id", invitation_id)
      .single();

    if (invError || !invitation) {
      return new Response(
        JSON.stringify({ error: "Invitation not found" }),
        { status: 404, headers: { ...corsHeaders, "Content-Type": "application/json" } }
      );
    }

    const orgName = (invitation.organizations as any)?.name || "Team";
    const inviterName = (invitation.profiles as any)?.name || "Jemand";
    const roleName = (invitation.roles as any)?.name || "Mitglied";
    const joinUrl = `${APP_URL}/join?token=${invitation_id}`;

    // Email via Resend senden
    const emailResponse = await fetch("https://api.resend.com/emails", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${RESEND_API_KEY}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        from: "Project Prepper <noreply@project-prepper.vercel.app>",
        to: [invitation.email],
        subject: `Einladung zum Team ${orgName}`,
        html: `
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:40px 20px;">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
          <!-- Header -->
          <tr>
            <td style="background-color:#6366f1; padding:24px 32px; text-align:center;">
              <h1 style="margin:0; color:#ffffff; font-size:20px; font-weight:600;">
                Project Prepper
              </h1>
            </td>
          </tr>
          <!-- Body -->
          <tr>
            <td style="padding:32px;">
              <h2 style="margin:0 0 8px; font-size:18px; color:#18181b;">
                Du wurdest eingeladen!
              </h2>
              <p style="margin:0 0 24px; font-size:14px; color:#71717a; line-height:1.6;">
                <strong>${inviterName}</strong> hat dich als <strong>${roleName}</strong> zum Team <strong>${orgName}</strong> eingeladen.
              </p>
              <!-- Button -->
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="padding:8px 0 24px;">
                    <a href="${joinUrl}"
                       style="display:inline-block; background-color:#6366f1; color:#ffffff; text-decoration:none; padding:12px 32px; border-radius:8px; font-size:14px; font-weight:600;">
                      Einladung annehmen
                    </a>
                  </td>
                </tr>
              </table>
              <p style="margin:0; font-size:12px; color:#a1a1aa; line-height:1.5;">
                Falls der Button nicht funktioniert, kopiere diesen Link:<br>
                <a href="${joinUrl}" style="color:#6366f1; word-break:break-all;">${joinUrl}</a>
              </p>
            </td>
          </tr>
          <!-- Footer -->
          <tr>
            <td style="padding:16px 32px; background-color:#f4f4f5; text-align:center;">
              <p style="margin:0; font-size:11px; color:#a1a1aa;">
                Project Prepper &middot; Projektmanagement
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
        `,
      }),
    });

    if (!emailResponse.ok) {
      const errorBody = await emailResponse.text();
      console.error("Resend error:", errorBody);
      return new Response(
        JSON.stringify({ error: "Email sending failed", details: errorBody }),
        { status: 500, headers: { ...corsHeaders, "Content-Type": "application/json" } }
      );
    }

    const result = await emailResponse.json();
    return new Response(
      JSON.stringify({ success: true, email_id: result.id }),
      { status: 200, headers: { ...corsHeaders, "Content-Type": "application/json" } }
    );

  } catch (err) {
    console.error("Edge function error:", err);
    return new Response(
      JSON.stringify({ error: (err as Error).message }),
      { status: 500, headers: { ...corsHeaders, "Content-Type": "application/json" } }
    );
  }
});
