"use client";

import { useRouter } from "next/navigation";
import { IconChevronRight } from "@/components/ui/icons";

interface DashboardCardProps {
  label: string;
  value: string | number;
  subtext?: string;
  icon: React.ReactNode;
  iconBg: string;
  iconColor: string;
  href?: string;
  /** Wert in Rot anzeigen (z.B. überfällige Aufgaben) */
  urgent?: boolean;
  /** Nicht klickbar, kein Hover */
  disabled?: boolean;
}

export function DashboardCard({
  label,
  value,
  subtext,
  icon,
  iconBg,
  iconColor,
  href,
  urgent = false,
  disabled = false,
}: DashboardCardProps) {
  const router = useRouter();
  const isClickable = !!href && !disabled;

  function handleClick() {
    if (isClickable && href) {
      router.push(href);
    }
  }

  return (
    <div
      onClick={handleClick}
      className={`p-5 rounded-xl relative group transition-all duration-150 ${
        isClickable ? "cursor-pointer" : ""
      }`}
      style={{
        background: "var(--color-surface)",
        boxShadow: "var(--shadow-sm)",
        border: "1px solid var(--color-border-light)",
      }}
      onMouseEnter={(e) => {
        if (isClickable) {
          e.currentTarget.style.borderColor = "var(--color-primary)";
          e.currentTarget.style.boxShadow = "var(--shadow-md)";
        }
      }}
      onMouseLeave={(e) => {
        if (isClickable) {
          e.currentTarget.style.borderColor = "var(--color-border-light)";
          e.currentTarget.style.boxShadow = "var(--shadow-sm)";
        }
      }}
    >
      <div className="flex items-center justify-between mb-3">
        <span
          className="text-sm font-medium"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          {label}
        </span>
        <div
          className="w-9 h-9 rounded-lg flex items-center justify-center"
          style={{ background: iconBg, color: iconColor }}
        >
          {icon}
        </div>
      </div>
      <div
        className="text-3xl font-bold"
        style={urgent ? { color: "var(--color-destructive)" } : undefined}
      >
        {value}
      </div>
      {subtext && (
        <div
          className="text-sm mt-1"
          style={{ color: "var(--color-muted-foreground)" }}
        >
          {subtext}
        </div>
      )}
      {/* Klick-Hinweis: Chevron rechts unten */}
      {isClickable && (
        <div
          className="absolute bottom-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity"
          style={{ color: "var(--color-primary)" }}
        >
          <IconChevronRight size={16} />
        </div>
      )}
    </div>
  );
}
