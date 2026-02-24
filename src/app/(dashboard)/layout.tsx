import { Sidebar } from "@/components/layout/sidebar";

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="flex min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8 overflow-auto" style={{ background: "var(--color-background)" }}>
        <div className="max-w-6xl">
          {children}
        </div>
      </main>
    </div>
  );
}
