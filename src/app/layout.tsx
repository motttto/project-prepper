import type { Metadata } from "next";
import "./globals.css";

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
      <body>{children}</body>
    </html>
  );
}
