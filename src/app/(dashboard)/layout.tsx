"use client";

import { useState } from "react";
import { OrgProvider } from "@/contexts/org-context";
import { ImpersonateProvider } from "@/contexts/impersonate-context";
import { Sidebar } from "@/components/layout/sidebar";
import { TopBar } from "@/components/layout/top-bar";
import { ImpersonateBanner } from "@/components/layout/impersonate-banner";
import { ToastProvider } from "@/components/ui/toast-provider";

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <OrgProvider>
      <ImpersonateProvider>
        <div className="flex min-h-screen">
          {/* Mobile Overlay */}
          {sidebarOpen && (
            <div
              className="fixed inset-0 bg-black/50 z-40 lg:hidden"
              onClick={() => setSidebarOpen(false)}
            />
          )}

          <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

          <main
            className="flex-1 p-4 sm:p-6 lg:p-8 overflow-auto min-w-0"
            style={{ background: "var(--color-background)" }}
          >
            <div className="max-w-7xl mx-auto">
              <ImpersonateBanner />
              <TopBar onMenuToggle={() => setSidebarOpen(true)} />
              {children}
            </div>
          </main>
          <ToastProvider />
        </div>
      </ImpersonateProvider>
    </OrgProvider>
  );
}
