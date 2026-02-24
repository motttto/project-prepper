"use client";

import { useState } from "react";

export interface TabItem {
  key: string;
  label: string;
}

interface TabsProps {
  tabs: TabItem[];
  activeTab: string;
  onTabChange: (key: string) => void;
}

export function TabBar({ tabs, activeTab, onTabChange }: TabsProps) {
  return (
    <div
      className="flex gap-1 p-1 rounded-lg mb-6 overflow-x-auto"
      style={{ background: "var(--color-muted)" }}
    >
      {tabs.map((tab) => (
        <button
          key={tab.key}
          onClick={() => onTabChange(tab.key)}
          className="px-4 py-2 rounded-md text-sm font-medium whitespace-nowrap transition-all"
          style={{
            background: activeTab === tab.key ? "var(--color-surface)" : "transparent",
            color: activeTab === tab.key ? "var(--color-foreground)" : "var(--color-muted-foreground)",
            boxShadow: activeTab === tab.key ? "var(--shadow-sm)" : "none",
          }}
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}
