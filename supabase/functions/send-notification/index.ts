// Supabase Edge Function: Generische Notification-Email mit Template
// Deno Runtime — nutzt nodemailer via esm.sh
//
// POST {
//   template_key: string,
//   recipients: string[],   // profile_ids (NICHT email-Adressen)
//   vars: Record<string, string>,
//   pref_key?: "voting" | "polls" | "loans" | "feedback"  // optional: filter by user pref
// }
// → laedt Template + Empfaenger-Emails + SMTP-Config (vom Sender = is_system)
// → ersetzt {{var}} Platzhalter, sendet Email

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

function renderTemplate(tpl: string, vars: Record<string, string>): string {
  return tpl.replace(/\{\{(\w+)\}\}/g, (_, key) => vars[key] ?? "");
}

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  if (req.method !== "POST") return json({ error: "Method not allowed" }, 405);

  try {
    const { template_key, recipients, vars, pref_key } = await req.json();
    if (!template_key || !Array.isArray(recipients) || recipients.length === 0) {
      return json({ error: "Missing template_key or recipients" }, 400);
    }

    const supabase = createClient(SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY);

    // Template laden
    const { data: tpl } = await supabase
      .from("email_templates")
      .select("subject, html_body, text_body")
      .eq("key", template_key)
      .single();
    if (!tpl) return json({ error: `Template ${template_key} not found` }, 404);

    // Empfaenger laden (Email + Notification-Pref)
    const { data: profiles } = await supabase
      .from("profiles")
      .select("id, email, name, notification_prefs")
      .in("id", recipients);
    if (!profiles || profiles.length === 0) {
      return json({ success: true, method: "skipped", message: "No recipients" });
    }

    // Filter: nur User mit gueltiger Email + zugestimmter Pref
    const filtered = profiles.filter((p) => {
      if (!p.email) return false;
      if (pref_key) {
        const prefs = (p.notification_prefs || {}) as Record<string, boolean>;
        if (prefs[pref_key] === false) return false;
      }
      return true;
    });
    if (filtered.length === 0) {
      return json({ success: true, method: "skipped", message: "No recipients after filter" });
    }

    // SMTP-Config: nutze System-Admin SMTP (Fallback)
    const { data: sysAdmin } = await supabase
      .from("profiles")
      .select("id")
      .eq("is_system", true)
      .limit(1)
      .single();

    const { data: emailConfig } = await supabase
      .from("org_email_config")
      .select("*")
      .eq("owner_profile_id", sysAdmin?.id)
      .eq("is_enabled", true)
      .maybeSingle();

    if (!emailConfig) {
      console.log("No SMTP config for system admin, skipping");
      return json({ success: true, method: "skipped", message: "No SMTP config" });
    }

    const security = emailConfig.smtp_security || "starttls";
    const transport = nodemailer.createTransport({
      host: emailConfig.smtp_host,
      port: emailConfig.smtp_port,
      secure: security === "ssl",
      auth: { user: emailConfig.smtp_user, pass: emailConfig.smtp_pass },
      tls: { rejectUnauthorized: false },
    });

    // APP_URL automatisch in vars verfuegbar machen
    const allVars = { app_url: APP_URL, ...vars };
    const subject = renderTemplate(tpl.subject, allVars);
    const html = renderTemplate(tpl.html_body, allVars);
    const text = tpl.text_body ? renderTemplate(tpl.text_body, allVars) : undefined;

    const sentTo: string[] = [];
    for (const p of filtered) {
      const personalVars = { ...allVars, recipient_name: p.name || "" };
      const personalSubject = renderTemplate(tpl.subject, personalVars);
      const personalHtml = renderTemplate(tpl.html_body, personalVars);
      const personalText = tpl.text_body ? renderTemplate(tpl.text_body, personalVars) : undefined;

      try {
        // deno-lint-ignore no-explicit-any
        const mailOptions: any = {
          from: emailConfig.sender_name
            ? `"${emailConfig.sender_name}" <${emailConfig.sender_email}>`
            : emailConfig.sender_email,
          to: p.email,
          subject: personalSubject,
          html: personalHtml,
        };
        if (personalText) mailOptions.text = personalText;
        if (emailConfig.bcc_email) mailOptions.bcc = emailConfig.bcc_email;
        await transport.sendMail(mailOptions);
        sentTo.push(p.email);
      } catch (e) {
        console.error(`Failed to send to ${p.email}:`, e);
      }
    }

    return json({ success: true, method: "email", sent_to: sentTo, count: sentTo.length });
  } catch (err) {
    console.error("Edge function error:", err);
    return json({ error: (err as Error).message }, 500);
  }
});
