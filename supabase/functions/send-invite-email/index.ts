// Supabase Edge Function: Einladungs-Email via Supabase Auth
// Deno Runtime — URL-Imports
//
// POST { invitation_id } → Sendet Einladungs-Email an eingeladene Person
// Nutzt Supabase Auth inviteUserByEmail (eingebauter Email-Service)
// Für bestehende User: kein Email nötig, sie sehen die Einladung in der App

import { createClient } from "https://esm.sh/@supabase/supabase-js@2";
import { requireUuid, ValidationError } from "../_shared/validation.ts";

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

function getServiceClient() {
  return createClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY);
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") {
    return new Response("ok", { headers: corsHeaders });
  }

  try {
    let invitation_id: string;
    try {
      const body = await req.json();
      invitation_id = requireUuid(body?.invitation_id, "invitation_id");
    } catch (e) {
      if (e instanceof ValidationError) return json({ error: e.message }, 400);
      return json({ error: "Invalid JSON" }, 400);
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
      return json({ error: "Invitation not found" }, 404);
    }

    // deno-lint-ignore no-explicit-any
    const orgName = (invitation.organizations as any)?.name || "Team";
    // deno-lint-ignore no-explicit-any
    const inviterName = (invitation.profiles as any)?.name || "Jemand";
    // deno-lint-ignore no-explicit-any
    const roleName = (invitation.roles as any)?.name || "Mitglied";

    // Prüfe ob User schon existiert
    const { data: existingUsers } = await supabase.auth.admin.listUsers();
    const userExists = existingUsers?.users?.some(
      (u) => u.email?.toLowerCase() === invitation.email.toLowerCase()
    );

    if (userExists) {
      // User existiert → kein Email nötig, sieht Einladung in der App (Sidebar Badge)
      console.log(`User ${invitation.email} exists, skipping invite email (visible in-app)`);
      return json({
        success: true,
        method: "in_app",
        message: "User existiert bereits — Einladung wird in der App angezeigt",
      });
    }

    // Neuer User → Supabase Auth Invite senden
    const redirectUrl = `${APP_URL}/join?token=${invitation_id}`;

    const { data: inviteData, error: inviteError } = await supabase.auth.admin.inviteUserByEmail(
      invitation.email,
      {
        redirectTo: redirectUrl,
        data: {
          org_name: orgName,
          inviter_name: inviterName,
          role_name: roleName,
          invitation_id: invitation_id,
        },
      }
    );

    if (inviteError) {
      console.error("Invite error:", inviteError);
      return json({ error: inviteError.message }, 500);
    }

    console.log(`Invite email sent to ${invitation.email} for org ${orgName}`);
    return json({
      success: true,
      method: "email",
      user_id: inviteData?.user?.id,
    });

  } catch (err) {
    console.error("Edge function error:", err);
    return json({ error: (err as Error).message }, 500);
  }
});
