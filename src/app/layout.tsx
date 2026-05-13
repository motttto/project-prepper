import type { Metadata } from "next";
import "./globals.css";
import { Analytics } from "@vercel/analytics/next";
import { SpeedInsights } from "@vercel/speed-insights/next";

export const metadata: Metadata = {
  title: "Project Prepper",
  description: "Projektmanagement mit Inventarverwaltung und Kostenkalkulation",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="de">
      <body>
        {children}
        {/* Production-Telemetrie (laden nur in Production, kein Cookie-Consent noetig
            weil first-party + ohne Personenbezug — Vercel garantiert Anonymitaet) */}
        <Analytics />
        <SpeedInsights />
      </body>
    </html>
  );
}
