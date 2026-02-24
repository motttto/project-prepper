import Link from "next/link";

export default function Home() {
  return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="text-center space-y-6">
        <h1 className="text-4xl font-bold">Projektplanner</h1>
        <p className="text-[var(--color-muted-foreground)] text-lg">
          Projektmanagement &middot; Inventar &middot; Kostenkalkulation
        </p>
        <div className="flex gap-4 justify-center">
          <Link
            href="/login"
            className="px-6 py-3 bg-[var(--color-primary)] text-white rounded-lg hover:bg-[var(--color-primary-hover)] transition-colors"
          >
            Login
          </Link>
          <Link
            href="/projects"
            className="px-6 py-3 border border-[var(--color-border)] rounded-lg hover:bg-[var(--color-muted)] transition-colors"
          >
            Dashboard
          </Link>
        </div>
      </div>
    </div>
  );
}
